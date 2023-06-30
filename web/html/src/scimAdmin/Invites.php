<?php
namespace scimAdmin;

use PDO;
use PDOException;

class Invites {
  private $error = '';
  private $scope = '';
  private $sourceIdP = '';
  private $backendIdP = '';
  private $attibutes2migrate = '';

  const SQL_INVITELIST = 'SELECT *  FROM invites WHERE `instance` = :Instance ORDER BY `hash`, `session`';
  const SQL_SPECIFICINVITE = 'SELECT *  FROM invites WHERE `session` = :Session AND `instance` = :Instance';

  const SQL_INSTANCE = ':Instance';
  const SQL_SESSION = ':Session';

  public function __construct() {
    $a = func_get_args();
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
      $this->scope = str_replace('/','',$_SERVER['CONTEXT_PREFIX']);
      $this->sourceIdP = $instances[$this->scope]['sourceIdP'];
      $this->backendIdP = $instances[$this->scope]['backendIdP'];
      $this->attibutes2migrate = $instances[$this->scope]['attibutes2migrate'];
    }
  }

  public function getInvitesList() {
    $invitesHandler = $this->Db->prepare(self::SQL_INVITELIST);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->scope);
    $invitesHandler->execute();
    return $invitesHandler->fetchAll(PDO::FETCH_ASSOC);
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
      $hostURL, $this->sourceIdP, urlencode($hostURL . '/' . $this->scope . '/admin/migrate.php?source'));
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

  public function updateInviteAttributes($session, $data) {
    $invitesHandler = $this->Db->prepare(self::SQL_SPECIFICINVITE);
    $invitesHandler->bindParam(self::SQL_SESSION, $session);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->scope);
    $invitesHandler->execute();
    if ($invitesHandler->fetch(PDO::FETCH_ASSOC)) {
      # session exists in DB
      $updateHandler = $this->Db->prepare('UPDATE invites
        SET `attributes` = :Data, `modified` = NOW()
        WHERE `session` = :Session AND `instance` = :Instance');
      $updateHandler->bindParam(self::SQL_SESSION, $session);
      $updateHandler->bindValue(self::SQL_INSTANCE, $this->scope);
      $updateHandler->bindParam(':Data', $data);
      return $updateHandler->execute();
    } else {
      # No session exists, create a new
      $insertHandler = $this->Db->prepare('INSERT INTO invites
        (`instance`, `session`, `modified`, `attributes`)
        VALUES (:Instance, :Session, NOW(), :Data)');
      $insertHandler->bindParam(self::SQL_SESSION, $session);
      $insertHandler->bindValue(self::SQL_INSTANCE, $this->scope);
      $insertHandler->bindParam(':Data', $data);
      return $insertHandler->execute();
    }
  }

  public function updateInviteByCode($session,$code) {
    $invitesHandler = $this->Db->prepare('SELECT *  FROM invites WHERE `hash` = :Code AND `instance` = :Instance');
    $invitesHandler->bindParam(':Code', $code);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->scope);
    $invitesHandler->execute();
    if ($invitesHandler->fetch(PDO::FETCH_ASSOC)) {
      # inviteCode exists in DB
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

  public function getInviteAttributes($session) {
    $invitesHandler = $this->Db->prepare(self::SQL_SPECIFICINVITE);
    $invitesHandler->bindParam(self::SQL_SESSION, $session);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->scope);
    $invitesHandler->execute();
    if ($invite = $invitesHandler->fetch(PDO::FETCH_ASSOC)) {
      return $invite['attributes'];
    }
  }

  public function removeInvite($session) {
    $invitesHandler = $this->Db->prepare('DELETE FROM invites
      WHERE `session` = :Session AND `instance` = :Instance');
    $invitesHandler->bindParam(self::SQL_SESSION, $session);
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
