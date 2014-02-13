<?php defined( '_JEXEC' ) or die( 'Restricted access' );

class plgUsernfptuserchange extends JPlugin {
	private $nfpt_db;
	private $olduserinfo;

	//We need to use the beforeSave method to grab the user's existing email address
	function onUserBeforeSave($olduser, $isnew, $newuser) {
		//if the user was added by way of the nfptusersync system plugin, we do not want to fire this plugin since that will cause an endless loop
		if(isset($newuser['nfptflag'])) {
			return true;
		}
		$this->olduserinfo = array();

		//first, we need to verify that the selected email address is not already in the users_live table
		if(!$this->db_connect($newuser['email'])) {
			throw new Exception('Unable to connect to users database. Please try again later, or contact the website administrator for assistance.');
			return false;
		}

		//if we are updating the user, we want to save the old email so that we can change it properly in the onUserAfterSave event
		if(!$isnew) {
			$this->olduserinfo['email']=$olduser['email'];
		}

		//before we allow the user to be saved in the database, we need to make sure that we will not be inserting a duplicate email address into the database
		if($isnew || $newuser['email'] != $olduser['email']) {
			$query = 'SELECT count(*) FROM users_live WHERE email='.$this->nfpt_db->quote($newuser['email']);
			$this->nfpt_db->setQuery($query);
			$this->nfpt_db->query();
			if($this->nfpt_db->loadResult() != 0) {
				throw new Exception('A user with that email address already exists in our user database. Please use a different email address or contact an administrator for assistance.');
				return false;
			}
		}
		//we also don't want to insert duplicate usernames since those are supposed to be unique
		if($isnew || $newuser['username'] != $olduser['username']) {
			$query = 'SELECT count(*) FROM users_live WHERE username='.$this->nfpt_db->quote($newuser['username']);
			$this->nfpt_db->setQuery($query);
			$this->nfpt_db->query();
			if($this->nfpt_db->loadResult() != 0) {
				throw new Exception('500', 'Error: A user with that email address already exists in our user database. Please use a different email address or contact an administrator for assistance.');
				return false;
			}
		}

		return true;
	}


	/**
	 * Store User Method
	 * @param 	array		holds the new user data
	 * @param 	boolean		true if a new user is stored
	 * @param	boolean		true if user was succesfully stored in the database
	 * @param	string		message
	 */
	function onUserAfterSave($user, $isnew, $success, $msg) { //J2.5 method name
		//if the user was added by way of the nfptusersync system plugin, we do not want to fire this plugin since that will cause an endless loop
		if(isset($user['nfptflag'])) {
			return true;
		}
		//if we were not able to connect to the database, we cannot procede
		if(!$this->db_connect($user['email'])) {
			return false;
		}
		//if the user was not successfully stored in the database. We don't want to update the users_live table with bad information
		if(!$success) {
			return false;
		}

		//this code is necessary to handle when a user changes
		$new_email = $nfpt_email = $user['email'];
		$email_change = false;
		if(!$isnew && !empty($this->olduserinfo['email'])) {
			$nfpt_email = $this->olduserinfo['email'];
			if($nfpt_email != $new_email) {
				$email_change = true;
			}
		}

		//check to see how many users exist in the users_live table with this email address
		$query = 'SELECT * FROM users_live WHERE email='.$this->nfpt_db->quote($nfpt_email);
		$this->nfpt_db->setQuery($query);
		$this->nfpt_db->query();
		$existing_users = $this->nfpt_db->getNumRows();

		//if there are more than one users in the users_live table with this email address, we do not know which user to update
		if($existing_users > 1) {
			$message = 'There are multiple users in the users_live table with this email. Remove the duplicates and try updating the user again.';
			if($email_change) {
				$message .= " (special note - the user's email address was changed to '$new_email')";
			}
			$this->addLog($nfpt_email, 'UPDATE', 'ERROR', $message);
			return false;
		}

		//******************************************************************************
		//***************** Get User Information ***************************************
		$myfield = 'update'.$this->params->get('myupdatefield');
		$userfields = array(
			'name' => $user['name'],
			'email' => $new_email,
			'username' => $user['username'],
			'blocked' => $user['block'],
			'deleted' => 0,
			$myfield => 0,
		);
		if($email_change) {
			$userfields['previousemail'] = $nfpt_email;
		}

		$query = 'SHOW COLUMNS FROM users_live';
		$this->nfpt_db->setQuery($query);
		$allcolumns = $this->nfpt_db->loadResultArray();
		foreach($allcolumns as $col) {
			if(substr($col,0,6)=='update' && $col != $myfield) {
				$userfields[$col] = 1;
			}
		}

		//get the user's plaintext password, if possible.
		//also get the user's encrypted password.
		$password = false;
		$possible_passfields = array(
			$user['password_clear'],
			$_POST['password'],
			$_POST['password1'],
			$_POST['password2'],
			$_POST['jspassword'],
			$user['password2'],
		);
		foreach($possible_passfields as $pass) {
			if(!$password && !empty($pass)) {
				$password = $pass;
			}
		}
		if($password) {
			$userfields['passplain'] = $password;
			$userfields['passjoom'] = $user['password'];
		}

		//******************************************************************************
		//***************** Insert or Update User **************************************
		if($existing_users == 0) {
			//Create GUID for user
			mt_srand((double) microtime() * 10000);
			$charid = strtoupper(md5(uniqid(rand(), true)));
			$userfields['user_guid'] = substr($charid,  0, 8).'-'.substr($charid,  8, 4).'-'.substr($charid, 12, 4).'-'.substr($charid, 16, 4).'-'.substr($charid, 20, 12);

			//Create and run the INSERT query
			$fields = array();
			$values = array();
			foreach($userfields as $field => $value) {
				$fields[] = $field;
				$values[] = $this->nfpt_db->quote($value);
			}
			$query = 'INSERT INTO users_live('.implode(',',$fields).') VALUES('.implode(',',$values).')';
			$this->nfpt_db->setQuery($query);
			$this->nfpt_db->query();
			if($this->nfpt_db->getErrorNum()) {
				//we failed to insert the user, log the error in the userlog table
				$this->addLog($nfpt_email, 'INSERT', 'ERROR', 'Error when running database insert query: '.$this->nfpt_db->getErrorMsg());
				return false;
			} else {
				//we successfully inserted the user, log the success
				$this->addLog($nfpt_email, 'INSERT');
				return true;
			}
		} else if($existing_users == 1) {
			//Create and run the UPDATE query
			$fields = array();
			foreach($userfields as $field => $value) {
				$fields[] = $field.'='.$this->nfpt_db->quote($value);
			}
			$query = 'UPDATE users_live SET '.implode(', ',$fields).' WHERE email='.$this->nfpt_db->quote($nfpt_email);
			$this->nfpt_db->setQuery($query);
			$this->nfpt_db->query();
			if($this->nfpt_db->getErrorNum()) {
				//we failed to update the user, log the error in the userlog table
				$this->addLog($nfpt_email, 'UPDATE', 'ERROR', 'Error when running database update query: '.$this->nfpt_db->getErrorMsg());
				return false;
			}
			if(($numrows = $this->nfpt_db->getAffectedRows()) != 1) {
				if($numrows==0) {
					//we failed to update the user, log it as an error
					$this->addLog($nfpt_email, 'UPDATE', 'ERROR', 'No rows affected by update query. Something isn\'t right here.');
					return false;
				} else {
					//we updated more than 1 user, log it as an error
					$this->addLog($nfpt_email, 'UPDATE', 'ERROR', $numrows.' rows affected by update query. Should be 1. Something isn\'t right here.');
					return false;
				}
			} else {
				//we successfully updated the user, log the success
				$this->addLog($nfpt_email, 'UPDATE');
				return true;
			}
		}

		//we should never get here. The function should always have returned a value in one of the previous if statements
		JError::raiseError('500','User add/update function failed to return value. Contact your administrator for assistance.');
		return false;
	}

	//We need a way to delete users, too!
	function onUserAfterDelete($user, $success, $msg) {
		//if we are calling the delete from the system plugin, we do not want to run this function
		if(isset($user['nfptflag'])) {
			return true;
		}

		//if we were not able to connect to the database, we cannot procede
		if(!$this->db_connect($user['email'])) {
			return false;
		}
		//if the user was not successfully deleted. We don't want to delete from in the users_live table
		if(!$success) {
			return false;
		}

		$myfield = 'update'.$this->params->get('myupdatefield');
		$updates = array('deleted=\'1\'', $myfield.'=\'0\'');

		$query = 'SHOW COLUMNS FROM users_live';
		$this->nfpt_db->setQuery($query);
		$allcolumns = $this->nfpt_db->loadResultArray();
		foreach($allcolumns as $col) {
			if(substr($col,0,6)=='update' && $col != $myfield) {
				$updates[] = $col.'=\'1\'';
			}
		}

		$query='UPDATE users_live SET '.implode(', ',$updates).' WHERE email='.$this->nfpt_db->quote($user['email']);
		$this->nfpt_db->setQuery($query);
		$this->nfpt_db->query();
		if(($numrows = $this->nfpt_db->getAffectedRows()) != 1) {
			if($numrows==0) {
				//we failed to delete the user in the users_live table, log it as an error
				$this->addLog($user['email'], 'DELETE', 'ERROR', 'Unable to mark the user in the users_live table for deletion. No rows affected by update query.');
				return false;
			} else {
				//we deleted more than 1 user, log it as an error
				$this->addLog($user['email'], 'DELETE', 'ERROR', 'Accidentally marked '.$numrows.' rows in the user_live table for deletion. Should be 1. Something isn\'t right here.');
				return false;
			}
		} else {
			//we successfully updated the user, log the success
			$this->addLog($user['email'], 'DELETE');
			return true;
		}
	}

	private function db_connect($emailval) {
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

		//if we were unable to connect to the database, send an email to the administrator notifying them.
		//if the email fails to send, at least we can notify the user that there was a problem.
		if($this->nfpt_db->getErrorNum()) {
			$mailer = JFactory::getMailer();
			$config = JFactory::getConfig();
			$site = JURI::root();
			$message = <<<HEREDOC
Failed to connect to the users database from $site after saving user.
The user's email address is '$emailval'.

The easiest way to correct this user's information in the database:
1. go the administrator interface on $site
2. search for '$emailval' from the users manager
3. edit the user with that email address.
4. press the "Save and Close" button. You do not actually have to change any values.

If you repeately receive this email from $site, that is an indicator that the database connection is no longer working from there. Please change the database connection details in the user plugin, or contact tech@mangotreemedia.com for assistance.
HEREDOC;
			if(!$mailer->sendMail($config->getValue('config.mailfrom'), $config->getValue('config.fromname'), $this->params->get('failemail'), 'User Save Failure', $message)) {
				throw new Exception('Unable to save information to our other sites. Please contact tech@mangotreemedia.com for assistance.');
			}
			//since we were unable to connect to the database, return false for failure.
			return false;
		}

		//We were able to initiate the database connection. Return true for success.
		return true;
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
				throw new Exception('Unable to save information to our other sites. Please contact tech@mangotreemedia.com for assistance.');
			}
		}
	}
}
?>
