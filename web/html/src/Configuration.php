<?php
namespace scimAdmin;

use PDO;
use PDOException;

class Configuration {
  private string $scope = '';
  private array $possibleAffiliations = array();
  private array $instance = array();
  private array $scim = array();
  private array $smtp = array();
  private $mode = 'Lab';
  private PDO $db;
  private $dbInstanceId = 0;

  /**
   * Setup the class
   *
   * @param bool $startDB If we should start the database connection or not.
   *
   * @param bool $scope scope/instance of config. If not set pick from URL
   *
   * @return void
   */
  public function __construct($startDB = true, $scope = false) {
    include __DIR__ . '/../config.php'; # NOSONAR

    $reqParams = array('dbServername', 'dbUsername', 'dbPassword', 'dbName',
      'authUrl', 'keyName', 'authCert', 'authKey', 'apiUrl',
      'smtpHost', 'saslUser', 'saslPassword', 'mailFrom',
      'Mode', 'possibleAffiliations', 'instances');
    $reqParamsInstance = array('sourceIdP', 'backendIdP', 'forceMFA',
      'orgName', 'allowedScopes', 'attributes2migrate', 'autoEPPN');

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
    }
    if ($startDB) {
      $this->checkInstanceExitInDB($this->scope);
    }
  }

  /**
   * Check Database version
   *
   * Check the version of database. If needed update to latest version
   *
   * @return void
   */
  private function checkDBVersion() {
    $dbVersionHandler = $this->db->query("SELECT value FROM params WHERE `id` = 'dbVersion'");
    if (! $dbVersion = $dbVersionHandler->fetch(PDO::FETCH_ASSOC)) {
      $dbVersion = 0;
    } else {
      $dbVersion=$dbVersion['value'];
    }
    if ($dbVersion < 4) {
      if ($dbVersion < 1) {
        $this->db->query("INSERT INTO params (`instance`, `id`, `value`) VALUES ('', 'dbVersion', 1)");
      }
      if ($dbVersion < 2 && $this->db->query(
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
      if ($dbVersion < 3) {
        # To ver 3
        $this->db->query('CREATE TABLE `instances` (
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
          `instance` varchar(30) DEFAULT NULL)');
        $this->db->query("INSERT INTO `instances` (`id`, `instance`) VALUES (1, 'Admin')");
        $this->db->query('CREATE TABLE `users` (
          `instance_id` int(10) unsigned NOT NULL,
          `ePPN` varchar(40) DEFAULT NULL,
          CONSTRAINT `users_ibfk_1` FOREIGN KEY (`instance_id`) REFERENCES `instances` (`id`) ON DELETE CASCADE)');
        $this->db->query('DELETE FROM `invites`');
        $this->db->query('ALTER TABLE `invites`
          CHANGE `instance` `instance_id` int(10) unsigned NOT NUM@tilda
          LL,
          ADD `lang` varchar(2) DEFAULT NULL,
          ADD FOREIGN KEY (`instance_id`)
            REFERENCES `instances` (`id`)
            ON DELETE CASCADE');
        $this->db->query("DELETE FROM `params` WHERE `id` = 'token'");
        $this->db->query("UPDATE params SET value = 3, `instance` = '1' WHERE `instance` = '' AND `id` = 'dbVersion'");
        $this->db->query('ALTER TABLE `params`
          CHANGE `instance` `instance_id` int(10) unsigned NOT NULL,
          ADD FOREIGN KEY (`instance_id`)
            REFERENCES `instances` (`id`)
            ON DELETE CASCADE');
      }
      $this->db->query('ALTER TABLE `users`
        ADD `externalId` text DEFAULT NULL,
        ADD `name` text DEFAULT NULL,
        ADD `scimId` varchar(40) DEFAULT NULL,
        ADD `personNIN` varchar(12) DEFAULT NULL,
        ADD `lastSeen` datetime DEFAULT NULL,
        ADD `status` tinyint');
      $this->db->query('DELETE FROM `users`');
      $this->db->query("UPDATE params SET value = 4 WHERE `instance_id` = 1 AND `id` = 'dbVersion'");
    }
  }

  /**
   * Check if scope is configured for use
   *
   * Check if scope exists in database. If not insert into database.
   * In both cases store the id in $this->dbInstanceId
   *
   * @param string $scope
   * @return void
   */
  private function checkInstanceExitInDB($scope) {
    $instanceHandler = $this->db->prepare('SELECT `id` FROM `instances` WHERE `instance` = :Instance');
    $instanceHandler->execute(array('Instance' => $scope));
    if ($instance = $instanceHandler->fetch(PDO::FETCH_ASSOC)) {
      $this->dbInstanceId = $instance['id'];
    } else {
      $instanceAddHandler = $this->db->prepare('INSERT INTO `instances` SET `instance` = :Instance');
      $instanceAddHandler->execute(array('Instance' => $scope));
      $this->dbInstanceId = $this->db->lastInsertId();
    }
  }

  /**
   * Return array of possible affiliations
   *
   * @return array
   */
  public function getPossibleAffiliations() {
    return $this->possibleAffiliations;
  }

  /**
   * Return configuration for connecting to SCIM API
   *
   * @return array
   */
  public function getSCIM() {
    return $this->scim;
  }

  /**
   * Return configuration for connecting to SMTP Server
   *
   * @return array
   */
  public function getSMTP() {
    return $this->smtp;
  }

  /**
   * Return configuration for instance
   *
   * Return configuration for configured instance based on what scope wsa selected on init.
   *
   * @return array
   */
  public function getInstance() {
    return $this->instance;
  }

  /**
   * Return configuration for connecting to database server
   *
   * @return PDO
   */
  public function getDb() {
    return $this->db;
  }

  /**
   * Return scope
   *
   * @return string
   */
  public function getScope() {
    return $this->scope;
  }

  /**
   * Return db instance
   *
   * @return int
   */
  public function getDbInstanceId() {
    return $this->dbInstanceId;
  }

  /**
   * Return forceMFA
   *
   * @return bool
   */
  public function forceMFA() {
    return $this->instance['forceMFA'];
  }

  /**
   * Return orgName
   *
   * @return string
   */
  public function orgName() {
    return $this->instance['orgName'];
  }

  /**
   * Return mode
   *
   * @return string
   */
  public function mode() {
    return $this->mode;
  }

  /**
   * Return if scope is configured
   *
   * @return bool
   */
  public function scopeConfigured() {
    return ($this->instance) ? true : false;
  }
}
