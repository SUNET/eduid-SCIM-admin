<?php
const SCIM_NUTID_SCHEMA = 'https://scim.eduid.se/schema/nutid/user/v1';
$baseDir = dirname($_SERVER['SCRIPT_FILENAME'], 2);
include $baseDir . '/config.php';

include $baseDir . '/include/Html.php';
$html = new HTML($Mode);

include $baseDir . '/include/SCIM.php';
$scim = new SCIM($baseDir);

include $baseDir . '/include/Invites.php';
$invites = new Invites($baseDir);

session_start();
$sessionID = $_COOKIE['PHPSESSID'];

if (isset($_GET['source'])) {
  if ($data = $invites->checkSourceData()) {
    $invites->updateInviteAttributes($sessionID, $data);
    $hostURL = "http".(!empty($_SERVER['HTTPS'])?"s":"")."://".$_SERVER['SERVER_NAME'];
    $redirectURL = $hostURL . '/' . $invites->getInstance() . '/?action=showMigrateFlow';
    header('Location: ' . $redirectURL);
  } else {
    print "Error while migrating";
  }
} elseif (isset($_GET['backend'])) {
  if ($EPPN = $invites->checkBackendData()) {
    if (! $id = $scim->getIdFromExternalId($EPPN) && ! $id = $scim->createIdFromExternalId($EPPN)) {
      print "Could not create user in SCIM";
      exit;
    }

    $attributes = json_decode($invites->getInviteAttributes($sessionID));

    $userArray = $scim->getId($id);
    
    $version = $userArray->meta->version;
    unset($userArray->meta);

    $schemaNutidFound = false;
    foreach ($userArray->schemas as $schema) {
      $schemaNutidFound = $schema == SCIM_NUTID_SCHEMA ? true : $schemaNutidFound;
    }
    if (! $schemaNutidFound) {$userArray->schemas[] = SCIM_NUTID_SCHEMA; }

    if (! isset($userArray->{SCIM_NUTID_SCHEMA})) {
      $userArray->{SCIM_NUTID_SCHEMA} = new \stdClass();
    }
    if (! isset($userArray->{SCIM_NUTID_SCHEMA}->profiles)) {
      $userArray->{SCIM_NUTID_SCHEMA}->profiles = new \stdClass();
    }
    if (! isset($userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdps)) {
      $userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp = new \stdClass();
    }
    if (! isset($userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes)) {
      $userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes = new \stdClass();
    }

    foreach ($attributes as $key => $value) {
      $userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->$key = $value;
    }

    if (isset($_SERVER['givenName']) || isset($_SERVER['sn'])) {
      $fullName = '';
      if (! isset($userArray->{'name'})) {
        $userArray->name = new \stdClass();
      }
      if (isset($_SERVER['givenName'])) {
        $userArray->name->givenName = $_SERVER['givenName'];
        $fullName = $_SERVER['givenName'];
      }
      if (isset($_SERVER['sn'])) {
        $userArray->name->familyName = $_SERVER['sn'];
        $fullName .= ' ' . $_SERVER['sn'];
      }
      $userArray->name->formatted = $fullName;
    }

    if ($scim->updateId($id,json_encode($userArray),$version)) {
      $invites->removeInvite($sessionID);
      $hostURL = "http".(!empty($_SERVER['HTTPS'])?"s":"")."://".$_SERVER['SERVER_NAME'];
      $redirectURL = $hostURL . '/' . $invites->getInstance() . '/?action=migrateSuccess';
      header('Location: ' . $redirectURL);
    } else {
      print "Error while migrating (Could not update SCIM)";
    }
  } else {
    print "Error while migrating (Got no ePPN)";
  }
} else {
  print "No action requested";
}
