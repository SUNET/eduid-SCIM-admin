<?php
namespace scimAdmin;

use PDO;
use PDOException;

class Configuration {
  private $scope = '';
  private $possibleAffiliations = '';
  private $instance = false;
  private $scim = false;
  private $smtp = false;
  private $mode = 'Lab';
  private $db = false;
  private $orgName = '';

  public function __construct($startDB = true, $scope = false) {
    include __DIR__ . '/../config.php'; # NOSONAR

    $reqParams = array('dbServername', 'dbUsername', 'dbPassword', 'dbName',
      'authUrl', 'keyName', 'authCert', 'authKey', 'apiUrl',
      'smtpHost', 'saslUser', 'saslPassword', 'mailFrom',
      'Mode', 'possibleAffiliations', 'instances');
    $reqParamsInstance = array('sourceIdP', 'backendIdP', 'forceMFA',
      'orgName', 'allowedScopes', 'attributes2migrate');

    foreach ($reqParams as $param) {
      if (! isset(${$param})) {
        print "Missing $param in config.php<br>";
        exit;
      }
    }

    if ($startDB) {
      try {
        $this->db = new PDO("mysql:host=$dbServername;dbname=$dbName", $dbUsername, $dbPassword);
        // set the PDO error mode to exception
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      } catch(PDOException $e) {
        echo "Error: " . $e->getMessage();
      }
      $this->checkDBVersion();
    }

    $this->scope = $scope ? $scope : str_replace('/','',$_SERVER['CONTEXT_PREFIX']);

    $this->mode =  $Mode;

    $this->scim['authUrl'] =  $authUrl;
    $this->scim['keyName'] = $keyName;
    $this->scim['authCert'] = $authCert;
    $this->scim['authKey'] = $authKey;
    $this->scim['apiUrl'] = $apiUrl;

    $this->possibleAffiliations = $possibleAffiliations;

    $this->smtp['Host'] = $smtpHost;
    $this->smtp['User'] = $saslUser;
    $this->smtp['Password'] = $saslPassword;
    $this->smtp['From'] = $mailFrom;

    if (isset($instances[$this->scope])) {
      $this->instance = $instances[$this->scope];

      foreach ($reqParamsInstance as $param) {
        if (! isset($this->instance[$param])) {
          printf('Missing $instances[%s][%s] in config.php<br>', $this->scope, $param);
          exit;
        }
      }
      $this->orgName = $instances[$this->scope]['orgName'];
    }
  }

  public function checkDBVersion() {
    $dbVersionHandler = $this->db->query("SELECT value FROM params WHERE `instance`='' AND `id`='dbVersion'");
    if (! $dbVersion = $dbVersionHandler->fetch(PDO::FETCH_ASSOC)) {
      $dbVersion = 0;
    } else {
      $dbVersion=$dbVersion['value'];
    }
    if ($dbVersion < 2) {
      if ($dbVersion < 1) {
        $this->db->query("INSERT INTO params (`instance`, `id`, `value`) VALUES ('', 'dbVersion', 1)");
      }
      if ($this->db->query(
        "ALTER TABLE invites ADD
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY
          FIRST")) {
        # 1 Update went well. Do the rest
        $this->db->query(
          "ALTER TABLE invites ADD
            `status` tinyint unsigned
          AFTER `hash`");
        $this->db->query(
          "ALTER TABLE invites ADD
            `inviteInfo` text DEFAULT NULL
          AFTER `attributes`");
        $this->db->query(
          "ALTER TABLE invites ADD
            `migrateInfo` text DEFAULT NULL
          AFTER `inviteInfo`");
        $this->db->query(
          "ALTER TABLE invites MODIFY COLUMN
            `hash` varchar(65) DEFAULT NULL");
        $this->db->query("UPDATE params SET value = 2 WHERE `instance`='' AND `id`='dbVersion'");
      }
    }
  }

  public function getPossibleAffiliations() {
    return $this->possibleAffiliations;
  }

  public function getSCIM() {
    return $this->scim;
  }

  public function getSMTP() {
    return $this->smtp;
  }

  public function getInstance() {
    return $this->instance;
  }

  public function getDb() {
    return $this->db;
  }

  public function getScope(){
    return $this->scope;
  }

  public function getOrgName() {
    return $this->orgName;
  }

  public function forceMFA() {
    return $this->instance['forceMFA'];
  }

  public function mode() {
    return $this->mode;
  }

  public function scopeConfigured() {
    return ($this->instance) ? true : false;
  }
}
