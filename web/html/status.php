<?php
//Load composer's autoloader
require_once 'vendor/autoload.php';
$config = new scimAdmin\Configuration(false);
$scim = $config->getSCIM();
function request($url) {
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_PORT , 443);
  curl_setopt($ch, CURLOPT_VERBOSE, 0);
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

  $response = curl_exec($ch);

  if (curl_errno($ch) == 0) {
    $info = curl_getinfo($ch);
    if ($info['http_code'] == 200 ) {
      return $response;
    } else {
      print "<pre>";
      print_r($info);
      print "</pre><br><pre>";
      print $response;
      print "</pre>";
      exit;
    }
  } else {
    print "Error";
    return false;
  }
}

$API = request($scim['apiUrl'].'status/healthy');
$API_json = json_decode($API);
$statusAPI = $API_json->status == 'STATUS_OK_scimapi_';

$auth = request($scim['authUrl'].'status/healthy');
$auth_json = json_decode($auth);
$statusAuth = $auth_json->status == 'STATUS_OK_';

if ($statusAPI) {
  if ($statusAuth) {
    print '{"status":"STATUS_OK_","reason":"API and AUTH tested OK"}';
  } else {
    print '{"status":"STATUS_FAIL_","reason":"AUTH Failed"}';
  }
} else {
  print '{"status":"STATUS_FAIL_","reason":"API Failed"}';
}
