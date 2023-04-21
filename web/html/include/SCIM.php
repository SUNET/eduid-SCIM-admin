<?php
Class SCIM {
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
			// Get token from DB. If no param exists create
			$paramsHandler = $this->Db->prepare('SELECT `value` FROM params WHERE `id` = :Id;');
			$paramsHandler->bindValue(':Id', 'token');
			$paramsHandler->execute();
			if ($param = $paramsHandler->fetch(PDO::FETCH_ASSOC)) {
				$this->token = $param['value'];
			} else {
				$this->Db->query("INSERT INTO params (`id`, `value`) VALUES ('token', '')");
				$this->getToken();
			}
		}
		if (method_exists($this,$f='__construct'.$i)) {
				call_user_func_array(array($this,$f),$a);
		}
	}

	private function __construct1() {
	}

	private function getToken() {
		$access = new \stdClass();
		$access->scope = 'sunet.se';
		$access->type = 'scim-api';

		$access_token = new \stdClass();
		$access_token->flags = array('bearer');
		$access_token->access = array($access);

		$data = new \stdClass();
		$data->access_token = array($access_token);
		$data->client = new \stdClass();
		$data->client->key = 'bjorn_test_1';

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "https://auth-test.sunet.se/transaction");
		curl_setopt($ch, CURLOPT_PORT , 443);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Accept: */*',
			'Content-Type: application/json'
		));

		curl_setopt($ch, CURLOPT_SSLCERT, $this->baseDir . "/auth/authcert.pem");
		  
		curl_setopt($ch, CURLOPT_SSLKEY, $this->baseDir . "/auth/authkey.pem");
		curl_setopt($ch, CURLOPT_CAINFO, "/etc/ssl/certs/ca-certificates.crt");
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

		$response = curl_exec($ch);
		curl_close($ch);
		$token = json_decode($response);
		$tokenValue = $token->access_token->value;

		$tokenHandler = $this->Db->prepare("UPDATE params SET `value` = :Token WHERE `id` = 'token'");
		$tokenHandler->bindValue(':Token', $tokenValue);
		$tokenHandler->execute();
		$this->token = $tokenValue;
	}

	public function request($method, $part, $data, $extraHeaders = array()) {
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
			case 'GET' :
				curl_setopt($ch, CURLOPT_POST, 0);
				break;
		}
		$headers = array(
			'Accept: */*',
			'Content-Type: application/scim+json',
			'Authorization: Bearer ' . $this->token
		);
		curl_setopt($ch, CURLOPT_URL, "https://api.dev.eduid.se/scim/test/$part");
		curl_setopt($ch, CURLOPT_PORT , 443);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, $extraHeaders));
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		
		$response = curl_exec($ch);

		if (curl_errno($ch) == 0) {
			if ($response == '{"schemas":["urn:ietf:params:scim:api:messages:2.0:Error"],"detail":"Bearer token error","status":401}') {
				$this->getToken();
				return $this->request($method, $part, $data);
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
			$this->error = 'Error while';
			return false;
		}
	}

	public function getAllUsers() {
		$this->error = '';
		$userList = array();
		$IdList = $this->request('POST', 'Users/.search','{"schemas": ["urn:ietf:params:scim:api:messages:2.0:SearchRequest"], "filter": "meta.lastModified ge \"1900-01-01\"", "startIndex": 1, "count": 100}');
		$IdListArray = json_decode($IdList);
		if (isset($IdListArray->schemas) && $IdListArray->schemas[0] == 'urn:ietf:params:scim:api:messages:2.0:ListResponse' ) {
			foreach ($IdListArray->Resources as $Resource) {
				$user = $this->request('GET','Users/'.$Resource->id, '');
				$userArray = json_decode($user);
				$userList[$Resource->id] = array('id' => $Resource->id, 'externalId' => $userArray->externalId, 'profiles' => false, 'linked_accounts' => false);
				if (isset ($userArray->{'https://scim.eduid.se/schema/nutid/user/v1'})) {
					$nutid = $userArray->{'https://scim.eduid.se/schema/nutid/user/v1'};
					if (isset($nutid->profiles) && sizeof((array)$nutid->profiles)) {
						$userList[$Resource->id]['profiles'] = true;
					}
					if (isset($nutid->linked_accounts) && sizeof((array)$nutid->linked_accounts)) {
						$userList[$Resource->id]['linked_accounts'] = true;
					}
					
					
				}
			}
			return ($userList);
		} else {
			printf('Unknown schema : %s', $userlistIDArray->schemas[0]);
			return false;
		}
	}

	public function getId($id) {
		$user = $this->request('GET','Users/'.$id, '');
		$userArray = json_decode($user);	
		return $user;
	}

	public function getIdFromExternalId($externalId) {
		$request = sprintf('{"schemas": ["urn:ietf:params:scim:api:messages:2.0:SearchRequest"], "filter": "externalId eq \"%s\"", "startIndex": 1, "count": 1}', $externalId);
		$userInfo = $this->request('POST', 'Users/.search', $request);
		$userArray = json_decode($userInfo);	
		if ($userArray->totalResults == 1 && isset($userArray->Resources[0]->id)) {
			return $userArray->Resources[0]->id;
		} else {
			return false;
		}
	}

	public function createIdFromExternalId($externalId) {
		$request = sprintf('{"schemas": ["urn:ietf:params:scim:schemas:core:2.0:User"], "externalId": "%s"}', $externalId);
		$userInfo = $this->request('POST', 'Users/', $request);
		$userArray = json_decode($userInfo);	
		if (isset($userArray->id)) {
			return $userArray->id;
		} else {
			return false;
		}
	}

	public function updateId($id, $data, $version) {
		$result = $this->request('PUT','Users/'.$id, $data, array('if-match: ' . $version));
		return $result;
	}
}
