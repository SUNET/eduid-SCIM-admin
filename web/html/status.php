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
  curl_setopt($ch, CURLOPT_TIMEOUT, 5);

  $response = curl_exec($ch);
  $curl_errno = curl_errno($ch);
  switch ($curl_errno) {
    case 0:
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
      break;
    case 6:
      # Could not resolve host. The given remote host was not resolved.
      printf('{"status":"STATUS_FAIL_","reason":"Host unknown: %s"}', $url);
      exit;
    case 28:
      # Operation timeout. The specified time-out period was reached according to the conditions.
      # DNS timeout or timeout on request
      printf('{"status":"STATUS_FAIL_","reason":"Url %s : %s"}', $url, curl_error($ch));
      exit;
    default:
      printf('Curl error: %d/%s', $curl_errno, curl_error($ch));
      return false;
  }
}

if ($API = request($scim['apiUrl'].'status/healthy')) {
  $API_json = json_decode($API);
  $statusAPI = $API_json->status == 'STATUS_OK_scimapi_';
} else {
  $statusAPI = false;
}

if ($auth = request($scim['authUrl'].'status/healthy')) {
  $auth_json = json_decode($auth);
  $statusAuth = $auth_json->status == 'STATUS_OK_';
} else {
  $statusAuth = false;
}

if ($statusAPI) {
  if ($statusAuth) {
    print '{"status":"STATUS_OK_","reason":"API and AUTH tested OK"}';
  } else {
    print '{"status":"STATUS_FAIL_","reason":"AUTH Failed"}';
  }
} else {
  print '{"status":"STATUS_FAIL_","reason":"API Failed"}';
}
