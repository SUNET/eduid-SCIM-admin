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

    if (isset($dbServername) && isset($dbUsername) && isset($dbPassword) && isset($dbName)) {
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
    } else {
      print 'Missing config for DB. One or more of $dbServername, $dbUsername, $dbPassword or $dbName is missing in config.php';
      exit;
    }

    $this->scope = $scope ? $scope : str_replace('/','',$_SERVER['CONTEXT_PREFIX']);

    if (isset($Mode)) {
      $this->mode =  $Mode;
    } else {
      print 'Missing config for Mode in config.php. Should be Lab or Prod';
      exit;
    }

    if (isset($authUrl) && isset($keyName) && isset($authCert) && isset($authKey) && isset($apiUrl)) {
      $this->scim['authUrl'] =  $authUrl;
      $this->scim['keyName'] = $keyName;
      $this->scim['authCert'] = $authCert;
      $this->scim['authKey'] = $authKey;
      $this->scim['apiUrl'] = $apiUrl;
    } else {
      print 'Missing config for Auth or SCIM-api. One or more of $authUrl, $keyName, $authCert, $authKey or $apiUrl is missing in config.php';
      exit;
    }

    if (isset($possibleAffiliations)) {
      $this->possibleAffiliations = $possibleAffiliations;
    } else {
      print 'Missing config for $possibleAffiliations in config.php';
      exit;
    }


    if (isset($smtpHost) && isset($saslUser) && isset($saslPassword) && isset($mailFrom)) {
      $this->smtp['Host'] = $smtpHost;
      $this->smtp['User'] = $saslUser;
      $this->smtp['Password'] = $saslPassword;
      $this->smtp['From'] = $mailFrom;
    } else {
      print 'Missing mail config. One or more of $smtpHost, $saslUser, $saslPassword or $mailFrom is missing in config.php';
      exit;
    }

    if (isset($instances[$this->scope])) {
      $this->instance = $instances[$this->scope];

      if (!(isset($instances[$this->scope]['sourceIdP']) && isset($instances[$this->scope]['backendIdP']))) {
        printf ('Missing config for IdP:s. One or more of sourceIdP or backendIdP is missing in $instances[%s] in config.php', $this->scope);
        exit;
      }

      if (! isset($instances[$this->scope]['forceMFA'])) {
        $this->instance['forceMFA'] = false;
      }

      if (isset($instances[$this->scope]['orgName']) && isset($instances[$this->scope]['allowedScopes']) && isset($instances[$this->scope]['attributes2migrate']) && isset($instances[$this->scope]['adminUsers'])) {
        $this->orgName = $instances[$this->scope]['orgName'];
      } else {
        printf ('Missing config for Organisation. One or more of orgName, attributes2migrate, allowedScopes or adminUsers is missing in $instances[%s] in config.php', $this->scope);
        exit;
      }
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
