<?php
namespace scimAdmin;

use PDO;
use scimAdmin\Configuration;

class SCIM {
  private $scope = false;
  private $authURL = '';
  private $keyName = '';
  private $certFile = '';
  private $keyFile = '';
  private $apiURL = '';
  private $attributes2migrate = '';
  private $allowedScopes = '';
  private $possibleAffiliations = '';
  private $adminUsers = array();
  private $adminAccess = 0;
  private $db;
  private $token;
  private $dbInstanceId = 0;

  const SCIM_USERS = 'Users/';

  const SQL_INSTANCE = ':Instance';
  const SQL_EPPN = ':EPPN';

  const SCIM_NUTID_SCHEMA = 'https://scim.eduid.se/schema/nutid/user/v1';

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
      $this->possibleAffiliations = $config->getPossibleAffiliations();
      $this->dbInstanceId = $config->getDbInstanceId();

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

  private function getToken() {
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
        case 404 :
          $result = json_decode($response);
          if ($result->detail == 'User not found') {
            return 'User didn\'t exists';
          } else {
            print_r($result);
            exit;
          }
          break;
        default:
          print "<pre>";
          print_r($info);
          print "</pre>";
          print $response;
          exit;
          break;
      }
    } else {
      print "Error on request to auth-server";
      curl_close($ch);
      exit;
    }
  }

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
            return $response;
          default:
            print "<pre>";
            print_r($info);
            print "</pre>";
            print $response;
            exit;
            break;
        }
      }
    } else {
      print "Error";
      return false;
    }
  }

  public function getAllUsers() {
    $rand = rand(0,20);
    if ($rand == 0) {
      $checkUserHandler = $this->db->prepare('SELECT `instance_id` FROM `users` WHERE `instance_id` = :Instance AND `ePPN` = :EPPN');
      $addUserHandler = $this->db->prepare('INSERT INTO `users` (`instance_id`, `ePPN`) VALUES (:Instance, :EPPN)');
      $checkUserHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
      $addUserHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
    }
    $userList = array();
    $idList = $this->request('POST',
      self::SCIM_USERS.'.search','{"schemas": ["urn:ietf:params:scim:api:messages:2.0:SearchRequest"],
      "filter": "meta.lastModified ge \"1900-01-01\"", "startIndex": 1, "count": 100}');
    $idListArray = json_decode($idList);
    if (isset($idListArray->schemas) &&
      $idListArray->schemas[0] == 'urn:ietf:params:scim:api:messages:2.0:ListResponse' ) {
      foreach ($idListArray->Resources as $Resource) {
        $user = $this->request('GET', self::SCIM_USERS.$Resource->id, '');
        $userArray = json_decode($user);
        $userList[$Resource->id] = array('id' => $Resource->id,
          'externalId' => $userArray->externalId,
          'fullName' => '', 'attributes' => '',
          'profile' => false, 'linked_accounts' => false);
        if (isset($userArray->name->formatted)) {
          $userList[$Resource->id]['fullName'] = $userArray->name->formatted;
        }
        if (isset ($userArray->{self::SCIM_NUTID_SCHEMA})) {
          $userList[$Resource->id] = $this->checkNutid(
            $userArray->{self::SCIM_NUTID_SCHEMA},$userList[$Resource->id]);
          if ($rand == 0 && isset($userList[$Resource->id]['attributes']) && isset($userList[$Resource->id]['attributes']->eduPersonPrincipalName)) {
            $checkUserHandler->bindValue(self::SQL_EPPN, $userList[$Resource->id]['attributes']->eduPersonPrincipalName);
            $checkUserHandler->execute();
            if (! $checkUserHandler->fetch()) {
              $addUserHandler->bindValue(self::SQL_EPPN, $userList[$Resource->id]['attributes']->eduPersonPrincipalName);
              $addUserHandler->execute();
            }
          }
        }
      }
      return $userList;
    } else {
      printf('Unknown schema : %s', $idListArray->schemas[0]);
      return false;
    }
  }

  public function ePPNexists($eduPersonPrincipalName) {
    $checkUserHandler = $this->db->prepare('SELECT `instance_id` FROM `users` WHERE `instance_id` = :Instance AND `ePPN` = :EPPN');
    $checkUserHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
    $checkUserHandler->bindValue(self::SQL_EPPN, $eduPersonPrincipalName);
    $checkUserHandler->execute();
    return $checkUserHandler->fetch() ? true : false;
  }

  private function checkNutid($nutid, $userList) {
    if (isset($nutid->profiles) && sizeof((array)$nutid->profiles) && isset($nutid->profiles->connectIdp)) {
      if (isset($nutid->profiles->connectIdp->attributes) ) {
        $userList['profile'] = true;
      }
      $userList['attributes'] = $nutid->profiles->connectIdp->attributes;
    }
    if (isset($nutid->linked_accounts) && sizeof((array)$nutid->linked_accounts)) {
      $userList['linked_accounts'] = true;
    }
    return $userList;
  }

  public function getId($id) {
    $user = $this->request('GET', self::SCIM_USERS.$id, '');
    return json_decode($user);
  }

  public function getIdFromExternalId($externalId) {
    $request =
      sprintf('{"schemas": ["urn:ietf:params:scim:api:messages:2.0:SearchRequest"],
        "filter": "externalId eq \"%s\"", "startIndex": 1, "count": 1}',
        $externalId);
    $userInfo = $this->request('POST', self::SCIM_USERS.'.search', $request);
    $userArray = json_decode($userInfo);
    if ($userArray->totalResults == 1 && isset($userArray->Resources[0]->id)) {
      return $userArray->Resources[0]->id;
    } else {
      return false;
    }
  }

  public function createIdFromExternalId($externalId) {
    $request = sprintf('{"schemas": ["urn:ietf:params:scim:schemas:core:2.0:User"], "externalId": "%s"}', $externalId);
    $userInfo = $this->request('POST', self::SCIM_USERS, $request);
    $userArray = json_decode($userInfo);
    if (isset($userArray->id)) {
      return $userArray->id;
    } else {
      return false;
    }
  }

  public function updateId($id, $data, $version) {
    return $this->request('PUT', self::SCIM_USERS.$id, $data, array('if-match: ' . $version));
  }

  public function getAttributes2migrate(){
    return $this->attributes2migrate;
  }

  public function getAllowedScopes() {
    return $this->allowedScopes;
  }

  public function getPossibleAffiliations() {
    return $this->possibleAffiliations;
  }

  public function checkAccess($adminUser) {
    if (isset($this->adminUsers[$adminUser])) {
      $this->adminAccess = $this->adminUsers[$adminUser];
      return true;
    }
    return false;
  }

  public function getAdminAccess() {
    return $this->adminAccess;
  }

  public function validateID($id) {
    return filter_var($id, FILTER_VALIDATE_REGEXP,
      array("options"=>array("regexp"=>"/^[a-z,0-9]{8}-[a-z,0-9]{4}-[a-z,0-9]{4}-[a-z,0-9]{4}-[a-z,0-9]{12}$/")));
  }

  public function migrate ($migrateInfo, $attributes) {
    $migrateInfo = json_decode($migrateInfo);
    $attributes = json_decode($attributes);

    $ePPN = $migrateInfo->eduPersonPrincipalName;
    if ((! $id = $this->getIdFromExternalId($ePPN)) && (! $id = $this->createIdFromExternalId($ePPN))) {
      print "Could not create user in SCIM";
      exit;
    }

    $userArray = $this->getId($id);

    $version = $userArray->meta->version;
    unset($userArray->meta);

    $schemaNutidFound = false;
    foreach ($userArray->schemas as $schema) {
      $schemaNutidFound = $schema == self::SCIM_NUTID_SCHEMA ? true : $schemaNutidFound;
    }
    if (! $schemaNutidFound) {$userArray->schemas[] = self::SCIM_NUTID_SCHEMA; }

    if (! isset($userArray->{self::SCIM_NUTID_SCHEMA})) {
      $userArray->{self::SCIM_NUTID_SCHEMA} = new \stdClass();
    }
    if (! isset($userArray->{self::SCIM_NUTID_SCHEMA}->profiles)) {
      $userArray->{self::SCIM_NUTID_SCHEMA}->profiles = new \stdClass();
    }
    if (! isset($userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdps)) {
      $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp = new \stdClass();
    }
    if (! isset($userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes)) {
      $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes = new \stdClass();
    }

    foreach ($attributes as $key => $value) {
      $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->$key = $value;
    }

    if (! isset($userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->data)) {
      $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->data = new \stdClass();
    }
    $userArray->{self::SCIM_NUTID_SCHEMA}->profiles->connectIdp->data->civicNo = $migrateInfo->norEduPersonNIN;

    if (! isset($userArray->{'name'})) {
      $userArray->name = new \stdClass();
    }
    $userArray->name->givenName = $migrateInfo->givenName;
    $userArray->name->familyName = $migrateInfo->sn;
    $userArray->name->formatted = $migrateInfo->givenName . ' ' . $migrateInfo->sn;

    return $this->updateId($id,json_encode($userArray),$version);
  }

  public function checkScopeExists($scope) {
    include __DIR__ . '/../config.php'; # NOSONAR
    return isset($instances[$scope]);
  }
}
