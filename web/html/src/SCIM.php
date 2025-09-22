<?php
namespace scimAdmin;

use PDO;
use scimAdmin\Configuration;

/**
 * Class to handle Connection with SCIM API
 */
class SCIM {
  private $scope = false;
  private $autoEPPN = false;
  private $authURL = '';
  private $keyName = '';
  private $certFile = '';
  private $keyFile = '';
  private $apiURL = '';
  private $attributes2migrate = array();
  private $allowedScopes = array();
  private $possibleAffiliations = '';
  private $adminUsers = array();
  private $adminAccess = 0;
  private PDO $db;
  private $token;
  private $dbInstanceId = 0;
  private $translateSAML = array();

  const SCIM_USERS = 'Users/';

  const SQL_INSTANCE = ':Instance';
  const SQL_EPPN = ':EPPN';

  const SCIM_NUTID_SCHEMA = 'https://scim.eduid.se/schema/nutid/user/v1';

  /**
   * Setup the class
   *
   * @return void
   */
  public function __construct() {
    $config = new Configuration();
    $this->db = $config->getDb();

    $scimConfig = $config->getSCIM();
    $this->authURL =  $scimConfig['authUrl'];
    $this->keyName = $scimConfig['keyName'];
    $this->certFile = $scimConfig['authCert'];
    $this->keyFile = $scimConfig['authKey'];
    $this->apiURL = $scimConfig['apiUrl'];

    if ($instance = $config->getInstance()) {
      $this->scope = $config->getScope();
      $this->attributes2migrate = $instance['attributes2migrate'];
      $this->allowedScopes = $instance['allowedScopes'];
      $this->adminUsers = $instance['adminUsers'];
      $this->autoEPPN = $instance['autoEPPN'];
      $this->possibleAffiliations = $config->getPossibleAffiliations();
      $this->dbInstanceId = $config->getDbInstanceId();

      # Make sure we have an array called translateSAML
      $this->translateSAML = isset($instance['translateSAML']) ? $instance['translateSAML'] : array();

      // Get token from DB. If no param exists create
      $paramsHandler = $this->db->prepare('SELECT `value` FROM params WHERE `id` = :Id AND `instance_id` = :Instance;');
      $paramsHandler->bindValue(':Id', 'token');
      $paramsHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
      $paramsHandler->execute();
      if ($param = $paramsHandler->fetch(PDO::FETCH_ASSOC)) {
        $this->token = $param['value'];
      } else {
        $addParamsHandler = $this->db->prepare('INSERT INTO params (`instance_id`,`id`, `value`)
          VALUES ( :Instance, ' ."'token', '')");
        $addParamsHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
        $addParamsHandler->execute();
        $this->getToken();
      }
    }
  }

  /**
   * Request a token from auth-server
   *
   * @param bool $first If this is first request.
   *
   * @return string
   */
  private function getToken($first = true) {
    $access = new \stdClass();
    $access->scope = $this->scope;
    $access->type = 'scim-api';

    $accessToken = new \stdClass();
    $accessToken->flags = array('bearer');
    $accessToken->access = array($access);

    $data = new \stdClass();
    $data->access_token = array($accessToken);
    $data->client = new \stdClass();
    $data->client->key = $this->keyName;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->authURL . 'transaction');
    curl_setopt($ch, CURLOPT_PORT , 443);
    curl_setopt($ch, CURLOPT_VERBOSE, 0);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Accept: */*',
      'Content-Type: application/json'
    ));

    curl_setopt($ch, CURLOPT_SSLCERT, $this->certFile);

    curl_setopt($ch, CURLOPT_SSLKEY, $this->keyFile);
    curl_setopt($ch, CURLOPT_CAINFO, "/etc/ssl/certs/ca-certificates.crt");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    if (curl_errno($ch) == 0) {
      $info = curl_getinfo($ch);
      curl_close($ch);
      switch ($info['http_code']) {
        case 200 :
        case 201 :
          $token = json_decode($response);
          $tokenValue = $token->access_token->value;

          $tokenHandler = $this->db->prepare("UPDATE params
            SET `value` = :Token
            WHERE `id` = 'token' AND `instance_id` = :Instance");
          $tokenHandler->bindValue(':Token', $tokenValue);
          $tokenHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
          $tokenHandler->execute();
          $this->token = $tokenValue;
          break;
        case 503 :
          if ($first) {
            sleep(3);
            return $this->getToken(false);
          } else {
            print "Got 503!";
            printf('        Something went wrong.
              Contact admin and give them this info<ul>
                <li>Part : auth/token%</li>
                <li>Response : %s</li>
              </ul>', $response);
            exit;
          }
          break;
        default:
          print "Got 503!";
          printf('        Something went wrong.
              Contact admin and give them this info<ul>
                <li>Part : auth/token%</li>
                <li>Response : %s</li>
              </ul>', $response);
          exit;
          break;
      }
    } else {
      print "Error on request to auth-server";
      curl_close($ch);
      exit;
    }
  }

  /**
   * Sends a request to SCIM API
   *
   * @param string $method  PUT, POST or DELETE
   * @param string $part part of API to call
   * @param string $data Data to sent to API
   * @param array $extraHeaders Extra headers to include in request
   * @param bool $first If this is first request.
   *
   * @return bool|string
   */
  public function request($method, $part, $data, $extraHeaders = array(), $first = true) {
    $ch = curl_init();
    switch ($method) {
      case 'POST' :
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        break;
      case 'PUT' :
        curl_setopt($ch, CURLOPT_POST, 0);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        break;
      case 'DELETE' :
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        break;
      default :
        # GET
        curl_setopt($ch, CURLOPT_POST, 0);
        break;
    }
    $headers = array(
      'Accept: */*',
      'Content-Type: application/scim+json',
      'Authorization: Bearer ' . $this->token
    );
    curl_setopt($ch, CURLOPT_URL, $this->apiURL. htmlspecialchars($part));
    curl_setopt($ch, CURLOPT_PORT , 443);
    curl_setopt($ch, CURLOPT_VERBOSE, 0);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, $extraHeaders));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    $response = curl_exec($ch);

    if (curl_errno($ch) == 0) {
      if ($response ==
      '{"schemas":["urn:ietf:params:scim:api:messages:2.0:Error"],"detail":"Bearer token error","status":401}') {
        if ($first) {
          $this->getToken();
          return $this->request($method, $part, $data, $extraHeaders, false);
        } else {
          print "Fail to get Bearer token";
          exit;
        }
      } else {
        $info = curl_getinfo($ch);
        switch ($info['http_code']) {
          case 200 :
          case 201 :
          case 204 :
            // User removed
            return $response;
            break;
          case 503 :
            // Timeout at server
          case 504 :
            // Timeout at api.eduid.se
            // Some other server ?
            if ($first && $method != 'POST') {
              sleep(3);
              return $this->request($method, $part, $data, $extraHeaders, false);
            } else {
              print "Got 503/504!";
              printf('        Something went wrong.
              Contact admin and give them this info<ul>
                <li>Method : %s</li>
                <li>Part : %s</li>
                <li>Response : %s</li>
              </ul>', $method, htmlspecialchars($part), $response);
              exit;
            }
            break;
          default:
            printf('        Something went wrong.
            Contact admin and give them this info<ul>
              <li>Method : %s</li>
              <li>Part : %s</li>
              <li>Response : %s</li>
            </ul>', $method, htmlspecialchars($part), $response);
            exit;
            break;
        }
      }
    } else {
      print "Error";
      return false;
    }
  }

  /**
   * Return a list of all users
   *
   * @param int $status Status of user
   * @param bool $first first run after upgrade ?
   *
   * @return array
   */
  public function getAllUsers($status = 1, $first = true) {
    $rand = rand(0,20); # NOSONAR
    if ($rand == 0) {
      $this->refreshUsersSQL();
    }

    $userList = array();
    $userHandler = $this->db->prepare('SELECT `ePPN`, `externalId`, `scimId`, `name`
      FROM `users` WHERE `instance_id` = :Instance AND `status` = :Status
      ORDER BY `name`');
    $userHandler->execute(array(self::SQL_INSTANCE => $this->dbInstanceId, ':Status' => $status));
    while ($user = $userHandler->fetch(PDO::FETCH_ASSOC)) {
      $userList[$user['scimId']] = array('id' => $user['scimId'],
        'externalId' => $user['externalId'],
        'fullName' => $user['name'],
        'ePPN' => $user['ePPN']);
    }
    if (sizeof($userList) == 0 && $first) {
      # Refresh if first time since upgrade
      $this->refreshUsersSQL();
      $userList = $this->getAllUsers($status, false);
    }
    return $userList;
  }

  /**
   * Check if ePPN exists in database
   *
   * @param string $eduPersonPrincipalName
   *
   * @return bool
   */
  public function ePPNexists($eduPersonPrincipalName) {
    $checkUserHandler = $this->db->prepare('SELECT `instance_id` FROM `users` WHERE `instance_id` = :Instance AND `ePPN` = :EPPN AND `status` < 16');
    $checkUserHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
    $checkUserHandler->bindValue(self::SQL_EPPN, $eduPersonPrincipalName);
    $checkUserHandler->execute();
    return $checkUserHandler->fetch() ? true : false;
  }

  /**
   * Return user object
   *
   * @param string $id Id of user in SCIM
   *
   * @return bool|object
   */
  public function getId($id) {
    $user = $this->request('GET', self::SCIM_USERS . urlencode($id), '');
    return json_decode($user);
  }

  /**
   * Remove user
   *
   * @param string $id
   * @param string $version
   *
   * @return bool
   */
  public function removeUser($id, $version) {
    $updateUserHandler =  $this->db->prepare('UPDATE `users`
      SET `status` = 8
      WHERE `instance_id` = :Instance AND `scimId` = :ScimId');
    $updateUserHandler->execute(array(
      self::SQL_INSTANCE => $this->dbInstanceId,
      ':ScimId' => $id));
    return $this->request('DELETE', self::SCIM_USERS . urlencode($id), '', array('if-match: ' . $version));
  }

  /**
   * Return user id based on ePPN
   *
   * @param string $externalId
   *
   * @return string
   */
  public function getIdFromExternalId($externalId) {
    $request =
      sprintf('{"schemas": ["urn:ietf:params:scim:api:messages:2.0:SearchRequest"],
        "filter": "externalId eq \"%s\"", "startIndex": 1, "count": 1}',
        htmlspecialchars($externalId));
    $userInfo = $this->request('POST', self::SCIM_USERS.'.search', $request);
    $userArray = json_decode($userInfo);
    if ($userArray->totalResults == 1 && isset($userArray->Resources[0]->id)) {
      return $userArray->Resources[0]->id;
    } else {
      return false;
    }
  }

  /**
   * Update user in SCIM
   *
   * @param string $id
   * @param string $data
   * @param string $version
   *
   * @return string
   */
  public function updateId($id, $data, $version) {
    return $this->request('PUT', self::SCIM_USERS . urlencode($id), $data, array('if-match: ' . $version));
  }

  /**
   * Return SAML attribues to migrate
   *
   * @return array
   */
  public function getAttributes2migrate(){
    return $this->attributes2migrate;
  }

  /**
   * Return allowed scopes
   *
   * @return array
   */
  public function getAllowedScopes() {
    return $this->allowedScopes;
  }

  /**
   * Check if $scope is valid to use
   *
   * @param string $string Textstring ending in @<scope>
   *
   * @return bool
   */
  public function validScope($string) {
    $stringA = explode('@', $string, 2);
    if (count($stringA) > 1 && in_array($stringA[1], $this->allowedScopes)) {
      return true;
    }
    return false;
  }

  /**
   * Return Possibleaffiliations
   *
   * @return array
   */
  public function getPossibleAffiliations() {
    return $this->possibleAffiliations;
  }

  /**
   * Expand ePersonScopedAffilation
   *
   * If parent affiliation is missing add
   *
   * @param array $ePSA
   *
   * @return array
   */
  public function expandePSA($ePSA) {
    do {
      $added = false;
      foreach ($ePSA as $affiliation) {
        $affiliationArray = explode('@', $affiliation);
        $checkedAffiliation = $affiliationArray[0];
        $checkedScope = '@' . $affiliationArray[1];
        if (isset($this->possibleAffiliations[$checkedAffiliation]) &&
          $this->possibleAffiliations[$checkedAffiliation] <> '' &&
          ! in_array($this->possibleAffiliations[$checkedAffiliation].$checkedScope, $ePSA)) {
          # Add dependent affiliation
          $added = true;
          $ePSA[] = $this->possibleAffiliations[$checkedAffiliation].$checkedScope;
        }
      }
    } while ($added);
    return $ePSA;
  }

  /**
   * Check access for admin user
   *
   * @param string $adminUser
   *
   * @return bool
   */
  public function checkAccess($adminUser) {
    if (isset($this->adminUsers[$adminUser])) {
      $this->adminAccess = $this->adminUsers[$adminUser];
      return true;
    }
    return false;
  }

  /**
   * Return adminaccess for user
   *
   * @return int
   */
  public function getAdminAccess() {
    return $this->adminAccess;
  }

  /**
   * Validate structure of id
   *
   * @param string $id
   *
   * @return bool|string
   */
  public function validateID($id) {
    return filter_var($id, FILTER_VALIDATE_REGEXP,
      array("options"=>array("regexp"=>"/^[a-z,0-9]{8}-[a-z,0-9]{4}-[a-z,0-9]{4}-[a-z,0-9]{4}-[a-z,0-9]{12}$/")));
  }

  /**
   * Migrate user into SCIM
   *
   * @param string $migrateInfo info fetched from backendIdp to migrate
   * @param string $attributes SAML attributes from invite to migrate
   *
   * @return bool|string
   */
  public function migrate($migrateInfo, $attributes) {
    $migrateInfo = json_decode($migrateInfo);
    $attributes = json_decode($attributes);

    $userArray = new \stdClass();
    $userArray->externalId = $migrateInfo->eduPersonPrincipalName;
    $userArray->schemas[] = "urn:ietf:params:scim:schemas:core:2.0:User";
    $userArray->schemas[] = self::SCIM_NUTID_SCHEMA;
    $userArray->{self::SCIM_NUTID_SCHEMA} = new \stdClass();
    $userArray->{self::SCIM_NUTID_SCHEMA}->profiles = new \stdClass();
    $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp = new \stdClass();
    $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes = new \stdClass();

    foreach ($attributes as $key => $value) {
      if ($key == 'eduPersonPrincipalName' && $this->autoEPPN) {
        continue;
      }
      $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->$key = $value;
    }
    if ($this->autoEPPN) {
      $newEPPN = explode('@', $migrateInfo->eduPersonPrincipalName)[0];
      $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->eduPersonPrincipalName =
        $newEPPN . "@" . $this->scope;
    }

    $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->data = new \stdClass();
    $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->data->civicNo = $migrateInfo->norEduPersonNIN;

    $userArray->name = new \stdClass();
    $userArray->name->givenName = $migrateInfo->givenName;
    $userArray->name->familyName = $migrateInfo->sn;
    $userArray->name->formatted = $migrateInfo->givenName . ' ' . $migrateInfo->sn;

    if ($userInfo = $this->request('POST', self::SCIM_USERS, json_encode($userArray))) {
      $scimInfo = json_decode($userInfo);
      $checkUserHandler = $this->db->prepare('SELECT `instance_id` FROM `users` WHERE `instance_id` = :Instance AND `ePPN` = :EPPN AND `status` < 16');
      $addUserHandler = $this->db->prepare('INSERT INTO `users`
        (`instance_id`, `ePPN`, `externalId`, `name`, `scimId`, `personNIN`, `lastSeen`, `status`)
        VALUES (:Instance, :EPPN, :ExternalId, :Name, :ScimId, :PersonNIN, NOW(), 1)');
      $checkUserHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
      if (isset($attributes->eduPersonPrincipalName)) {
        $checkUserHandler->execute(array(self::SQL_INSTANCE => $this->dbInstanceId, self::SQL_EPPN => $attributes->eduPersonPrincipalName));
        if (! $checkUserHandler->fetch()) {
          $addUserHandler->execute(array(
            self::SQL_INSTANCE => $this->dbInstanceId,
            self::SQL_EPPN => $attributes->eduPersonPrincipalName,
            ':ExternalId' => $migrateInfo->eduPersonPrincipalName,
            ':Name' => $userArray->name->formatted,
            ':ScimId' => $scimInfo->id,
            ':PersonNIN' => $migrateInfo->norEduPersonNIN)
          );
        }
      }
    }
    return $userInfo;
  }

  /**
   * Check if scope exists in config
   *
   * @param string $scope
   *
   * @return bool
   */
  public function checkScopeExists($scope) {
    include __DIR__ . '/../config.php'; # NOSONAR
    return isset($instances[$scope]);
  }

  /**
   * Refresh userlist in SQL based on current users in SCIM
   *
   * @return void
   */
  public function refreshUsersSQL() {
    $errors = '';
    $userList = array();
    $scimList = array();
    $checkUserHandler = $this->db->prepare('SELECT `ePPN`, `externalId`, `scimId`, `status`
      FROM `users`
      WHERE `instance_id` = :Instance AND `status` < 16');
    $addUserHandler = $this->db->prepare('INSERT INTO `users`
      (`instance_id`, `ePPN`, `externalId`, `name`, `scimId`, `personNIN`, `lastSeen`, `status`)
      VALUES (:Instance, :EPPN, :ExternalId, :Name, :ScimId, :PersonNIN, NOW(), 1)');
    $updateUserHandler =  $this->db->prepare('UPDATE `users`
      SET `ePPN` = :EPPN, `externalId` = :ExternalId, `name` = :Name,
        `personNIN` = :PersonNIN, `lastSeen` = NOW(), `status` = 1
      WHERE `instance_id` = :Instance AND `scimId` = :ScimId');
    $disableUserHandler =  $this->db->prepare('UPDATE `users`
      SET `status` = :Status
      WHERE `instance_id` = :Instance AND `scimId` = :ScimId');
    $checkUserHandler->execute(array(self::SQL_INSTANCE => $this->dbInstanceId));
    while ($user = $checkUserHandler->fetch(PDO::FETCH_ASSOC)) {
      if ($user['ePPN'] != '') {
        $userList[$user['ePPN']] = array('eduID' => $user['externalId'], 'seen' => false);
      }
      $scimList[$user['scimId']] = array('ePPN' => $user['ePPN'], 'status' => $user['status'], 'seen' => false);
    }

    $totalResults = 500;
    $index = 1;
    while ($index < $totalResults) {
      $idList = $this->request('POST',
        self::SCIM_USERS.'.search','{"schemas": ["urn:ietf:params:scim:api:messages:2.0:SearchRequest"],
        "filter": "meta.lastModified ge \"1900-01-01\"", "startIndex": '. $index .', "count": 100}');
        $index += 100;

      $idListArray = json_decode($idList);
      if (isset($idListArray->schemas) &&
        $idListArray->schemas[0] == 'urn:ietf:params:scim:api:messages:2.0:ListResponse' ) {
        $totalResults = $idListArray->totalResults;
        foreach ($idListArray->Resources as $Resource) {
          $user = $this->request('GET', self::SCIM_USERS.$Resource->id, '');
          $userArray = json_decode($user);
          $fullName = isset($userArray->name->formatted) ? $userArray->name->formatted : '';
          $personNIN = '';
          $ePPN = isset($userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->eduPersonPrincipalName) ?
            $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->eduPersonPrincipalName : '';
          $personNIN = isset($userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->data->civicNo) ?
            $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->data->civicNo : '';

          if (isset($scimList[$Resource->id])) {
            #exists in DB
            $errors .= $scimList[$Resource->id]['ePPN'] == $ePPN ? '' : sprintf("ePPN was changed from %s to %s on %s\n", $scimList[$Resource->id]['ePPN'], $ePPN, $Resource->id);
            $updateUserHandler->execute(array(
              self::SQL_INSTANCE => $this->dbInstanceId,
              ':EPPN' => $ePPN,
              ':ExternalId' => $userArray->externalId,
              ':Name' => $fullName,
              ':ScimId' => $Resource->id,
              ':PersonNIN' => $personNIN));
              $scimList[$Resource->id]['seen'] = true;
          } else {
            # Add new row
            $addUserHandler->execute(array(
              self::SQL_INSTANCE => $this->dbInstanceId,
              ':EPPN' => $ePPN,
              ':ExternalId' => $userArray->externalId,
              ':Name' => $fullName,
              ':ScimId' => $Resource->id,
              ':PersonNIN' => $personNIN));
          }

          if ($ePPN != '') {
            $errors .= (isset($userList[$ePPN]['seen']) && $userList[$ePPN]['seen']) ? sprintf ("%s exists twice in SCIM.\n", $ePPN) : '';
            $userList[$ePPN]['seen'] = true;
          }
        }
      } else {
        $errors .= sprintf("Unknown schema : %s\n", $idListArray->schemas[0]);
        $index = 100000;
      }
    }
    foreach ($scimList as $scimId => $data ) {
      if (! $data['seen']) {
        # Not in SCIM
        switch ($data['status']) {
          case 1 :
            # Mark user as deleted
            $disableUserHandler->execute(array(
              self::SQL_INSTANCE => $this->dbInstanceId,
              ':Status' => 8,
              ':ScimId' => $scimId));
            $errors .= sprintf ("Disabled %s->%s since missing in SCIM.\n", $scimId, $data['ePPN']);
            break;
          case 8 :
            if (isset($userList[$data['ePPN']]) && $userList[$data['ePPN']]['seen']) {
              $disableUserHandler->execute(array(
                self::SQL_INSTANCE => $this->dbInstanceId,
                ':Status' => 16,
                ':ScimId' => $scimId));
              $errors .= sprintf ("Archived %s->%s since exists in scim again.\n", $scimId, $data['ePPN']);
            }
            break;
          default :
        }
      }

    }
    if ($errors != '' ) {
      printf('        <div class="row alert-danger" role="alert">%s</div>%s', str_ireplace("\n", "<br>", $errors), "\n");
    }
  }

  /**
   * Restore user into SCIM.
   *
   * @param string $id SCIM id
   *
   * @return string
   */
  public function restoreUser($id) {
    $userHandler =  $this->db->prepare('SELECT `ePPN`, `externalId`, `name`, `personNIN`
      FROM `users`
      WHERE `status` = 8 AND `instance_id` = :Instance AND `scimId` = :ScimId');
    $userHandler->execute(array(
      self::SQL_INSTANCE => $this->dbInstanceId,
      ':ScimId' => $id));
    if ($user = $userHandler->fetch(PDO::FETCH_ASSOC)) {
      $userArray = new \stdClass();
      $userArray->externalId = $user['externalId'];
      $userArray->schemas[] = "urn:ietf:params:scim:schemas:core:2.0:User";
      $userArray->schemas[] = self::SCIM_NUTID_SCHEMA;
      $userArray->{self::SCIM_NUTID_SCHEMA} = new \stdClass();
      $userArray->{self::SCIM_NUTID_SCHEMA}->profiles = new \stdClass();
      $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp = new \stdClass();
      $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes = new \stdClass();
      $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->eduPersonPrincipalName = $user['ePPN'];

      $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->data = new \stdClass();
      $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->data->civicNo = $user['personNIN'];

      $userArray->name = new \stdClass();
      $userArray->name->formatted = $user['name'];

      if ($userInfo = $this->request('POST', self::SCIM_USERS, json_encode($userArray))) {
        $scimInfo = json_decode($userInfo);

        $addUserHandler = $this->db->prepare('INSERT INTO `users`
          (`instance_id`, `ePPN`, `externalId`, `name`, `scimId`, `personNIN`, `lastSeen`, `status`)
          VALUES (:Instance, :EPPN, :ExternalId, :Name, :ScimId, :PersonNIN, NOW(), 1)');
        $updateUserHandler =  $this->db->prepare('UPDATE `users`
          SET `status` = 16
          WHERE `instance_id` = :Instance AND `scimId` = :ScimId');
        $addUserHandler->execute(array(
          self::SQL_INSTANCE => $this->dbInstanceId,
          self::SQL_EPPN => $user['ePPN'],
          ':ExternalId' => $user['externalId'],
          ':Name' => $user['name'],
          ':ScimId' => $scimInfo->id,
          ':PersonNIN' => $user['personNIN'])
        );
        $updateUserHandler->execute(array(
          self::SQL_INSTANCE => $this->dbInstanceId,
          ':ScimId' => $id));
        return $scimInfo->id;
      }
      printf ('Problem to restore user in SCIM!!');
      exit;
    } else {
      printf ('User was not removed. Can not restore!!');
      exit;
    }
  }

  /**
   * Returns auto ePPN from config
   *
   * false - admin have to give ePPN
   * true - ePPN created from eduid ePPN
   *
   * @return bool
   */
  public function autoEPPN() {
    return $this->autoEPPN;
  }

  /**
   * Returns translated SAML attribute
   *
   * @param string $attribute
   *
   * @return string
   */
  public function translatedSAML($attribute) {
    return isset($this->translateSAML[$attribute]) ? $this->translateSAML[$attribute] : $attribute;
  }
}
