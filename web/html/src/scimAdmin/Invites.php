<?php
namespace scimAdmin;

use PDO;
use PDOException;

class Invites {
  private $error = '';
  private $scope = '';
  private $sourceIdP = '';
  private $backendIdP = '';
  private $attributes2migrate = '';

  const SQL_INVITELIST = 'SELECT *  FROM invites WHERE `instance` = :Instance
    ORDER BY `status` DESC, `hash`, `session`';
  const SQL_INVITE = 'SELECT *  FROM invites WHERE `instance` = :Instance AND `id` = :Id';
  const SQL_SPECIFICINVITE = 'SELECT *  FROM invites WHERE `session` = :Session AND `instance` = :Instance';

  const SQL_INSTANCE = ':Instance';
  const SQL_ID = ':Id';
  const SQL_MIGRATEINFO = ':MigrateInfo';
  const SQL_SESSION = ':Session';

  public function __construct() {
    $a = func_get_args();
    if (isset($a[0])) {
      $this->baseDir = array_shift($a);
      include $this->baseDir . '/config.php'; # NOSONAR
      try {
        $this->Db = new PDO("mysql:host=$dbServername;dbname=$dbName", $dbUsername, $dbPassword);
        // set the PDO error mode to exception
        $this->Db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      } catch(PDOException $e) {
        echo "Error: " . $e->getMessage();
      }

      $this->error = '';
      $this->scope = str_replace('/','',$_SERVER['CONTEXT_PREFIX']);
      $this->sourceIdP = $instances[$this->scope]['sourceIdP'];
      $this->backendIdP = $instances[$this->scope]['backendIdP'];
      $this->attributes2migrate = $instances[$this->scope]['attributes2migrate'];

      $dbVersionHandler = $this->Db->query("SELECT value FROM params WHERE `instance`='' AND `id`='dbVersion'");
      if (! $dbVersion = $dbVersionHandler->fetch(PDO::FETCH_ASSOC)) {
        $dbVersion = 0;
      } else {
        $dbVersion=$dbVersion['value'];
      }
      if ($dbVersion < 2) {
        if ($dbVersion < 1) {
          $this->Db->query("INSERT INTO params (`instance`, `id`, `value`) VALUES ('', 'dbVersion', 1)");
        }
        if ($this->Db->query(
          "ALTER TABLE invites ADD
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY
            FIRST")) {
          # 1 Update went well. Do the rest
          $this->Db->query(
            "ALTER TABLE invites ADD
              `status` tinyint unsigned
            AFTER `hash`");
          $this->Db->query(
            "ALTER TABLE invites ADD
              `inviteInfo` text DEFAULT NULL
            AFTER `attributes`");
          $this->Db->query(
            "ALTER TABLE invites ADD
              `migrateInfo` text DEFAULT NULL
            AFTER `inviteInfo`");
          $this->Db->query("UPDATE params SET value = 2 WHERE `instance`='' AND `id`='dbVersion'");
        }
      }
    }
  }

  public function getInvitesList() {
    $invitesHandler = $this->Db->prepare(self::SQL_INVITELIST);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->scope);
    $invitesHandler->execute();
    return $invitesHandler->fetchAll(PDO::FETCH_ASSOC);
  }

  public function getInvite($id) {
    $invitesHandler = $this->Db->prepare(self::SQL_INVITE);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->scope);
    $invitesHandler->bindValue(self::SQL_ID, $id);
    $invitesHandler->execute();
    return $invitesHandler->fetch(PDO::FETCH_ASSOC);
  }

  public function validateID($id) {
    $invitesHandler = $this->Db->prepare(self::SQL_INVITE);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->scope);
    $invitesHandler->bindValue(self::SQL_ID, $id);
    $invitesHandler->execute();
    if ($result = $invitesHandler->fetch(PDO::FETCH_ASSOC)) {
      return $result['id'];
    }
    return false;
  }

  public function checkInviteBySession($session) {
    $invitesHandler = $this->Db->prepare(self::SQL_SPECIFICINVITE);
    $invitesHandler->bindParam(self::SQL_SESSION, $session);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->scope);
    $invitesHandler->execute();
    if ($invite = $invitesHandler->fetch(PDO::FETCH_ASSOC)) {
      return $invite;
    } else {
      return false;
    }
  }

  public function startMigrateFromSourceIdP() {
    $hostURL = "http".(!empty($_SERVER['HTTPS'])?"s":"")."://".$_SERVER['SERVER_NAME'];
    $redirectURL = sprintf('%s/Shibboleth.sso/Login?entityID=%s&target=%s',
      $hostURL, $this->sourceIdP, urlencode($hostURL . '/' . $this->scope . '/migrate/?source'));
    header('Location: ' . $redirectURL);
  }

  public function checkSourceData() {
    $migrate = array();
    if ($_SERVER['Shib-Identity-Provider'] == $this->sourceIdP) {
      foreach ($this->attributes2migrate as $attribute => $SCIM) {
        if (isset($_SERVER[$attribute])) {
          $value = strpos($_SERVER[$attribute], ';') ? explode(";", $_SERVER[$attribute]) : $_SERVER[$attribute];
          $migrate[$SCIM] = $value;
        }
      }
      return $migrate;
    } else {
      return false;
    }
  }

  public function checkCorrectBackendIdP() {
    return $_SERVER['Shib-Identity-Provider'] == $this->backendIdP;
  }

  public function getUserDataFromIdP() {
    $migrate = array();
    $attributes= array('eduPersonPrincipalName','givenName', 'mail', 'mailLocalAddress',
      'norEduPersonNIN', 'schacDateOfBirth', 'sn', 'eduPersonAssurance');
    foreach ($attributes as $attribute) {
      $migrate[$attribute] = isset($_SERVER[$attribute]) ? $_SERVER[$attribute] : '';
    }
    return $migrate;
  }

  public function checkBackendData() {
    if ($this->checkCorrectBackendIdP()) {
      if (isset($_SERVER['eduPersonPrincipalName'])) {
        $migrate = $this->getUserDataFromIdP();
      } else {
        # No ePPN no need to continue
        return false;
      }
      return $migrate;
    } else {
      return false;
    }
  }

  public function checkALLevel($level) {
    $idpACFound = false;
    $userACFound = false;
    if (isset($_SERVER['Meta-Assurance-Certification'])) {
      foreach (explode(';', $_SERVER['Meta-Assurance-Certification']) as $AC) {
        $idpACFound = ($AC == 'http://www.swamid.se/policy/assurance/al'. $level) ? true : $idpACFound;
      }
    }
    if (isset($_SERVER['eduPersonAssurance'])) {
      foreach (explode(';', $_SERVER['eduPersonAssurance']) as $AC) {
        $userACFound = ($AC == 'http://www.swamid.se/policy/assurance/al'. $level) ? true : $userACFound;
      }
    }
    return $idpACFound && $userACFound;
  }

  public function updateInviteAttributes($session, $attributes, $inviteInfo) {
    $invitesHandler = $this->Db->prepare(self::SQL_SPECIFICINVITE);
    $invitesHandler->bindParam(self::SQL_SESSION, $session);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->scope);
    $invitesHandler->execute();
    if ($invitesHandler->fetch(PDO::FETCH_ASSOC)) {
      # session exists in DB
      $updateHandler = $this->Db->prepare('UPDATE invites
        SET `attributes` = :Attributes, `modified` = NOW(), status = 1, `inviteInfo` = :InviteInfo
        WHERE `session` = :Session AND `instance` = :Instance');
      $updateHandler->bindParam(self::SQL_SESSION, $session);
      $updateHandler->bindValue(self::SQL_INSTANCE, $this->scope);
      $updateHandler->bindValue(':Attributes', json_encode($attributes));
      $updateHandler->bindValue(':InviteInfo', json_encode($inviteInfo));
      return $updateHandler->execute();
    } else {
      # No session exists, create a new
      $insertHandler = $this->Db->prepare('INSERT INTO invites
        (`instance`, `session`, `modified`, `attributes`, `status`, `inviteInfo`)
        VALUES (:Instance, :Session, NOW(), :Attributes, 1, :InviteInfo)');
      $insertHandler->bindParam(self::SQL_SESSION, $session);
      $insertHandler->bindValue(self::SQL_INSTANCE, $this->scope);
      $insertHandler->bindValue(':Attributes', json_encode($attributes));
      $insertHandler->bindValue(':InviteInfo', json_encode($inviteInfo));
      return $insertHandler->execute();
    }
  }

  public function updateInviteAttributesById($id, $attributes, $inviteInfo) {
    $invitesHandler = $this->Db->prepare('SELECT *  FROM invites WHERE `id` = :Id AND `instance` = :Instance');
    $invitesHandler->bindParam(self::SQL_ID, $id);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->scope);
    $invitesHandler->execute();
    if ($invitesHandler->fetch(PDO::FETCH_ASSOC)) {
      # session exists in DB
      $updateHandler = $this->Db->prepare('UPDATE invites
        SET `attributes` = :Attributes, `modified` = NOW(), status = 1, `inviteInfo` = :InviteInfo
        WHERE `id` = :Id AND `instance` = :Instance');
      $updateHandler->bindParam(self::SQL_ID, $id);
      $updateHandler->bindValue(self::SQL_INSTANCE, $this->scope);
      $updateHandler->bindValue(':Attributes', json_encode($attributes));
      $updateHandler->bindValue(':InviteInfo', json_encode($inviteInfo));
      return $updateHandler->execute();
    } else {
      # No id exists, create a new
      $insertHandler = $this->Db->prepare('INSERT INTO invites
        (`instance`, `modified`, `attributes`, `status`, `inviteInfo`)
        VALUES (:Instance, NOW(), :Attributes, 1, :InviteInfo)');
      $insertHandler->bindValue(self::SQL_INSTANCE, $this->scope);
      $insertHandler->bindValue(':Attributes', json_encode($attributes));
      $insertHandler->bindValue(':InviteInfo', json_encode($inviteInfo));
      return $insertHandler->execute();
    }
  }

  public function updateInviteByCode($session,$code) {
    $invitesHandler = $this->Db->prepare("SELECT *  FROM invites
      WHERE `hash` = :Code AND `instance` = :Instance AND `session` = '' ");
    $invitesHandler->bindParam(':Code', $code);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->scope);
    $invitesHandler->execute();
    if ($invitesHandler->fetch(PDO::FETCH_ASSOC)) {
      # inviteCode exists in DB and has not been used
      $updateHandler = $this->Db->prepare('UPDATE invites
        SET `session` = :Session, `modified` = NOW()
        WHERE `hash` = :Code AND `instance` = :Instance');
      $updateHandler->bindParam(':Code', $code);
      $updateHandler->bindParam(self::SQL_SESSION, $session);
      $updateHandler->bindValue(self::SQL_INSTANCE, $this->scope);
      return $updateHandler->execute();
    } else {
      return false;
    }
  }

  public function getInviteBySession($session) {
    $invitesHandler = $this->Db->prepare(self::SQL_SPECIFICINVITE);
    $invitesHandler->bindParam(self::SQL_SESSION, $session);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->scope);
    $invitesHandler->execute();
    return $invitesHandler->fetch(PDO::FETCH_ASSOC);
  }

  public function removeInvite($id) {
    $invitesHandler = $this->Db->prepare('DELETE FROM invites
      WHERE `id` = :Id AND `instance` = :Instance');
    $invitesHandler->bindParam(self::SQL_ID, $id);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->scope);
    return $invitesHandler->execute();
  }

  public function move2Manual($id, $migrateInfo) {
    $invitesHandler = $this->Db->prepare('UPDATE invites
      SET `status` = 2, `migrateInfo`= :MigrateInfo
      WHERE `id` = :Id AND `instance` = :Instance');
    $invitesHandler->bindParam(self::SQL_ID, $id);
    $invitesHandler->bindValue(self::SQL_MIGRATEINFO, $migrateInfo);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->scope);
    return $invitesHandler->execute();
  }

  public function getInstance() {
    return $this->scope;
  }

  public function redirectToNewIdP($page) {
    $hostURL = "http".(!empty($_SERVER['HTTPS'])?"s":"")."://".$_SERVER['SERVER_NAME'];
    $redirectURL = sprintf('%s/Shibboleth.sso/Login?entityID=%s&target=%s',
      $hostURL, $this->backendIdP, urlencode($hostURL . '/' . $this->scope . '/' . $page));
    header('Location: ' . $redirectURL);
  }
}
