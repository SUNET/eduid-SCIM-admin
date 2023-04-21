
<?php
Class Invites {
	function __construct() {
		$a = func_get_args();
		$i = func_num_args();
		if (isset($a[0])) {
			$this->baseDir = array_shift($a);
			include $this->baseDir . '/config.php';
			try {
				$this->Db = new PDO("mysql:host=$dbServername;dbname=$dbName", $dbUsername, $dbPassword);
				// set the PDO error mode to exception
				$this->Db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			} catch(PDOException $e) {
				echo "Error: " . $e->getMessage();
			}

			$this->error = '';
			$this->sourceIdP = $sourceIdP;
			$this->backendIdP = $backendIdP;
			$this->attibutes2migrate = $attibutes2migrate;
		}
		if (method_exists($this,$f='__construct'.$i)) {
				call_user_func_array(array($this,$f),$a);
		}
	}

	public function getInvitesList() {
		$invitesHandler = $this->Db->prepare('SELECT *  FROM invites ORDER BY `hash`, `session`');
		$invitesHandler->execute();
		return $invitesHandler->fetchAll(PDO::FETCH_ASSOC);
	}

	public function checkInviteBySession($session) {
		$invitesHandler = $this->Db->prepare('SELECT *  FROM invites WHERE `session` = :Session');
		$invitesHandler->bindParam(':Session', $session);
		$invitesHandler->execute();
		if ($invite = $invitesHandler->fetch(PDO::FETCH_ASSOC)) {
			return $invite;
		} else {
			return false;
		}
	}

	public function startMigrateFromSourceIdP() {
		$hostURL = "http".(!empty($_SERVER['HTTPS'])?"s":"")."://".$_SERVER['SERVER_NAME'];
		$redirectURL = sprintf('%s/Shibboleth.sso/Login?entityID=%s&target=%s', $hostURL, $this->sourceIdP, urlencode($hostURL . '/admin/migrate.php?source'));
		header('Location: ' . $redirectURL);
	}

	public function finalizeMigrateToNewIdP() {
		$hostURL = "http".(!empty($_SERVER['HTTPS'])?"s":"")."://".$_SERVER['SERVER_NAME'];
		$redirectURL = sprintf('%s/Shibboleth.sso/Login?entityID=%s&target=%s', $hostURL, $this->backendIdP, urlencode($hostURL . '/admin/migrate.php?backend'));
		header('Location: ' . $redirectURL);
	}

	public function checkSourceData() {
		$migrate = array();
		if ($_SERVER['Shib-Identity-Provider'] == $this->sourceIdP) {
			foreach ($this->attibutes2migrate as $attribute => $SCIM) {
				if (isset($_SERVER[$attribute])) {
					$value = strpos($_SERVER[$attribute], ';') ? explode(";", $_SERVER[$attribute]) : $_SERVER[$attribute];
					$migrate[$SCIM] = $value;
				}
			}
			return json_encode($migrate);
		} else {
			return false;
		}	
	}

	public function checkBackendData() {
		$migrate = array();
		if ($_SERVER['Shib-Identity-Provider'] == $this->backendIdP) {
			if (isset($_SERVER['eduPersonPrincipalName'])) {
				return $_SERVER['eduPersonPrincipalName']; 
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	public function updateInviteAttributes($session, $data) {
		$invitesHandler = $this->Db->prepare('SELECT *  FROM invites WHERE `session` = :Session');
		$invitesHandler->bindParam(':Session', $session);
		$invitesHandler->execute();
		if ($invitesHandler->fetch(PDO::FETCH_ASSOC)) {
			# session exists in DB 
			$updateHandler = $this->Db->prepare('UPDATE invites SET `attributes` = :Data, `modified` = NOW() WHERE `session` = :Session');
			$updateHandler->bindParam(':Session', $session);
			$updateHandler->bindParam(':Data', $data);
			return $updateHandler->execute();
		} else {
			# No session exists, create a new
			$insertHandler = $this->Db->prepare('INSERT INTO invites (`session`, `modified`, `attributes`) VALUES (:Session, NOW(), :Data)');
			$insertHandler->bindParam(':Session', $session);
			$insertHandler->bindParam(':Data', $data);
			return $insertHandler->execute();
		}
	}

	public function updateInviteByCode($session,$code) {
		$invitesHandler = $this->Db->prepare('SELECT *  FROM invites WHERE `hash` = :Code');
		$invitesHandler->bindParam(':Code', $code);
		$invitesHandler->execute();
		if ($invitesHandler->fetch(PDO::FETCH_ASSOC)) {
			# inviteCode exists in DB 
			$updateHandler = $this->Db->prepare('UPDATE invites SET `session` = :Session, `modified` = NOW() WHERE `hash` = :Code');
			$updateHandler->bindParam(':Code', $code);
			$updateHandler->bindParam(':Session', $session);
			return $updateHandler->execute();
		} else {
			return false;
		}
	}

	public function getInviteAttributes($session) {
		$invitesHandler = $this->Db->prepare('SELECT *  FROM invites WHERE `session` = :Session');
		$invitesHandler->bindParam(':Session', $session);
		$invitesHandler->execute();
		if ($invite = $invitesHandler->fetch(PDO::FETCH_ASSOC)) {
			return $invite['attributes'];
		}
	}

	public function removeInvite($session) {
		$invitesHandler = $this->Db->prepare('DELETE FROM invites WHERE `session` = :Session');
		$invitesHandler->bindParam(':Session', $session);
		return ($invitesHandler->execute());
	}
}