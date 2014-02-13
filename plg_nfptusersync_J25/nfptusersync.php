<?php defined( '_JEXEC' ) or die( 'Restricted access' );

class plgSystemnfptusersync extends JPlugin {
	private $nfpt_db;
	/*
	 * This is where the plugin does all the work.
	 */
	function onAfterInitialise() {
		$app = JFactory::getApplication();
		if($app->isAdmin()) {
			//do not run from administrator interface
			return true;
		}
		//temporary measure to ignore this plugin entirely for all users except for me - for testing purposes
		//if(JRequest::getString('10skdOlsid03kDKowl','false') != 'aodkfnxog20498KSL') {
		//	return;
		//}

		//start by connecting to the users_live database
		if(!$this->db_connect()) {
			if(JRequest::getString('10skdOlsid03kDKowl','false') != 'aodkfnxog20498KSL') {
				die('UNABLE TO CONNECT TO USERS LIVE DATABASE. SYNCING USERS FAILED.');
			}
			return false;
		}

		//From here on out, we want to hide warnings and notices from users if they happen. Use our own error handler instead of the regular Joomla error handlers
		JError::setErrorHandling(E_WARNING, 'callback', array(get_class($this), 'handleWarning'));
		JError::setErrorHandling(E_NOTICE, 'callback', array(get_class($this), 'handleNotice'));

		//Now that we have a database connection, fetch the users to process
		$myfield = 'update'.$this->params->get('myupdatefield');
		$query = "SELECT * FROM users_live WHERE $myfield=1";
		$this->nfpt_db->setQuery($query);
		$this->nfpt_db->query();
		$updateusers = $this->nfpt_db->loadObjectList();

		if(!empty($updateusers)) {
			jimport('joomla.application.component.helper');
			$users_config = JComponentHelper::getParams('com_users');
			$defaultUserGroup = $users_config->get('new_usertype',2);

			foreach($updateusers as $nfpt_user) {
				//check to see if the user already exists in the database
				$findfields = array();
				$findfields[] = array('key'=>'email', 'value'=>$nfpt_user->email); //the new/updated email should always be first
				if(!empty($nfpt_user->previousemail)) {
					//if the email address has recently changed, that should be the second search field
					$findfields[] = array('key'=>'email', 'value'=>$nfpt_user->previousemail);
				}
				$findfields[] = array('key'=>'username', 'value'=>$nfpt_user->username); //the username should also be a unique identifier
				$user = $this->fetch_juser_from_fields($findfields);
				$success = false;

				//*******************************************************
				//CASE 1. If the user is marked for deletion, delete them
				if($nfpt_user->deleted) {
					if(!$user) {
						//we cannot delete a user that does not already exist. Log this in the userlog table
						$this->addlog($nfpt_user->email, 'SYNC', 'IGNORED', 'Could not locate the user to delete. Skipping.');
						$success = true;
					} else {
						$user->set('nfptflag','true');
						if($user->delete()) {
							$success = true;
						} else {
							$this->addlog($nfpt_user->email, 'SYNC', 'ERROR', 'Could not delete user. Please contact tech@mangotreemedia.com for assistance.');
						}
					}
				//***************************************
				//CASE 2. If this is a new user, add them
				} else if(!$user)  {
					$data = array(
						'name' => $nfpt_user->name,
						'username' => $nfpt_user->username,
						'usertype' => 'Registered',
						'email' => $nfpt_user->email,
						'groups' => array($defaultUserGroup),
						'password' => $nfpt_user->passplain,
						'password2' => $nfpt_user->passplain,
						'block' => $nfpt_user->blocked,
						'nfptflag' => true,
					);

					$newuser = new JUser;
					if($newuser->bind($data) && $newuser->save()) {
						$success = true;
					} else {
						$this->addlog($nfpt_user->email, 'SYNC', 'ERROR', 'Could not add user. Error: '.$newuser->getError());
					}
				//***************************************
				//CASE 3. If this is an existing user, udpate them
				} else {
					$data = array(
						'name' => $nfpt_user->name,
						'username' => $nfpt_user->username,
						'email' => $nfpt_user->email,
						'password' => $nfpt_user->passplain,
						'password2' => $nfpt_user->passplain,
						'block' => $nfpt_user->blocked,
						'nfptflag' => true,
					);
					if($user->bind($data) && $user->save()) {
						$success = true;
					} else {
						$this->addlog($nfpt_user->email, 'SYNC', 'ERROR', 'Could not update user. Error: '.$user->getError());
					}
				}

				//**************************************************************************************//
				//************** Finally, remove our update flag from the users_live table *************//
				if($success) {
					$emptyemail = true;
					//if there is a previousemail field but no more databases to update, we can remove the previous email from the record
					if(!empty($nfpt_user->previousemail)) {
						$nfpt_vars = get_object_vars($nfpt_user);
						foreach($nfpt_vars as $var => $val) {
							if(substr($var,0,6)=='update' && $var != $myfield && $val == 1) {
								$emptyemail = false;
							}
						}
					}
					if($emptyemail) {
						$query = "UPDATE users_live SET $myfield=0, previousemail='' WHERE email=".$this->nfpt_db->quote($nfpt_user->email);
					} else {
						$query = "UPDATE users_live SET $myfield=0 WHERE email=".$this->nfpt_db->quote($nfpt_user->email);
					}
					$this->nfpt_db->setQuery($query);
					$this->nfpt_db->query();
					if(($numrows = $this->nfpt_db->getAffectedRows()) != 1) {
						if($numrows==0) {
							//we failed to update the user, log it as an error
							$this->addLog($nfpt_user->email, 'SYNC', 'ERROR', 'Processed user successfully but failed to update users_live table afterwards. Something isn\'t right here.');
						} else {
							//we updated more than 1 user, log it as an error
							$this->addLog($nfpt_user->email, 'SYNC', 'ERROR', 'Processed user successfully, but accidentally updated '.$numrows.' rows in the users_live table afterwards. Should have only updated 1 row. Something isn\'t right here.');
						}
					}
				}
			}
		}

		//before we end, we want to set error handling back to the regular Joomla method
		JError::setErrorHandling(E_WARNING, 'message');
		JError::setErrorHandling(E_NOTICE, 'message');
		return true;
	}

	/*
	 * Helper function to find a user given a set of possible field/values to search
	 *
	 * @param fieldarray should be an array. Each element in that array should be an associative array with a key/value pair
	 */
	private function fetch_juser_from_fields($fieldarray) {
		$db = JFactory::getDbo();
		foreach($fieldarray as $field) {
			$query = "SELECT id FROM #__users WHERE {$field['key']}=".$db->quote($field['value']);
			$db->setQuery($query);
			$db->query();
			if($db->getNumRows() == 1) {
				return JUser::getInstance($db->loadResult());
			}
		}
		return false;
	}

	private function db_connect() {
		//if we have already connected, we do not need to connect again
		if($this->nfpt_db) return true;

		//otherwise, connect using the information in the plugin parameters
		$nfpt_options = array(
			'host' => $this->params->get('mainhost'),
			'user' => $this->params->get('mainuser'),
			'password' => $this->params->get('mainpass'),
			'database' => $this->params->get('maindb'),
		);
		$this->nfpt_db = JDatabase::getInstance($nfpt_options);

		//return whether or not we were able to connect to the database
		return !$this->nfpt_db->getErrorNum();
	}

	private function addlog($email, $operation, $status='SUCCESS', $notes = '') {
		$origin=JURI::base();
		$query = 'INSERT INTO userlog(origin, email, operation, status, notes) VALUES ('.$this->nfpt_db->quote($origin).','.$this->nfpt_db->quote($email).','.$this->nfpt_db->quote($operation).','.$this->nfpt_db->quote($status).','.$this->nfpt_db->quote($notes).')';
		$this->nfpt_db->setQuery($query);
		$this->nfpt_db->query();
		if($this->nfpt_db->getErrorNum()) {
			$mailer = JFactory::getMailer();
			$config = JFactory::getConfig();
			$site = JURI::root();
			$message = <<<HEREDOC
Failed to insert userlog entry into the database from $site after processing user.
The user's email address is '$email'.
The attempted operation was '$operation', which terminiated with status '$status'.

NOTES (if applicable):
$notes

If you receive this email, there is something fishy going on - please contact tech@mangotreemedia.com for assistance.
HEREDOC;
			if(!$mailer->sendMail($config->getValue('config.mailfrom'), $config->getValue('config.fromname'), $this->params->get('failemail'), 'Userlog Failure', $message)) {
				throw new Exception('There was an error in the user application. Please contact tech@mangotreemedia.com if you continue to experience this issue.');
			}
		}
	}

	function handleWarning(&$error, $options=array()) {
		$this->addlog('', 'SYNC', 'WARNING', $error->get('message'));
		return $error;
	}
	function handleNotice(&$error, $options=array()) {
		$this->addlog('', 'SYNC', 'NOTICE', $error->get('message'));
		return $error;
	}
}
