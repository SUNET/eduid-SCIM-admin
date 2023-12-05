<?php
const SWAMID_AL2 = 'http://www.swamid.se/policy/assurance/al2'; # NOSONAR
//Load composer's autoloader
require_once '../vendor/autoload.php';

$baseDir = dirname($_SERVER['SCRIPT_FILENAME'], 2);
include_once $baseDir . '/config.php'; # NOSONAR

$html = new scimAdmin\HTML($Mode);

$scim = new scimAdmin\SCIM($baseDir);

$invites = new scimAdmin\Invites($baseDir);

session_start();
$sessionID = $_COOKIE['PHPSESSID'];

if (isset($_GET['source'])) {
  if ($attributes = $invites->checkSourceData()) {
    $inviteInfo = $invites->getUserDataFromIdP();

    $attributes2Remove = array(
      'eduPersonPrincipalName', 'mailLocalAddress', 'norEduPersonNIN', 'schacDateOfBirth', 'eduPersonAssurance');
    #Remove unused parts and restructure
    if (strstr($inviteInfo['eduPersonAssurance'], SWAMID_AL2)) {
      # The info we got is on AL2 level
      $inviteInfo['personNIN'] = $inviteInfo['norEduPersonNIN'] = ''
        ? $inviteInfo['schacDateOfBirth'] : $inviteInfo['norEduPersonNIN'];
    } else {
      #Don't add full trust to attributes
      $inviteInfo['personNIN'] = '';
    }

    foreach ( $attributes2Remove as $part) {
      if (isset($inviteInfo[$part])) {
        unset($inviteInfo[$part]);
      }
    }
    $invites->updateInviteAttributes($sessionID, $attributes, $inviteInfo);
    $hostURL = "http".(!empty($_SERVER['HTTPS'])?"s":"")."://".$_SERVER['SERVER_NAME'];
    $redirectURL = $hostURL . '/' . $invites->getInstance() . '/?action=showMigrateFlow';
    header('Location: ' . $redirectURL);
  } else {
    print "Error while migrating";
  }
} elseif (isset($_GET['backend'])) {
  if ($migrateInfo = $invites->checkBackendData()) {
    if ($invites->checkALLevel(2)) {
      $inviteData = $invites->getInviteBySession($sessionID);
      $inviteInfo = json_decode($inviteData['inviteInfo']);

      if ($migrateInfo['norEduPersonNIN'] == $inviteInfo->personNIN) {
        # Match on full norEduPersonNIN. No need to check more
        migrate(json_encode($migrateInfo), $inviteData['attributes'], $inviteData['id']);
      } elseif (
        $migrateInfo['schacDateOfBirth'] == $inviteInfo->personNIN &&
        $migrateInfo['givenName'] == $inviteInfo->givenName &&
        $migrateInfo['sn'] == $inviteInfo->sn) {
        # Name and Birth date is OK. Now check if any mail matches
        $mailOK = $migrateInfo['mail'] == $inviteInfo->mail;
        foreach (explode (';',$migrateInfo['mailLocalAddress']) as $mailAddress) {
          $mailOK = $mailAddress == $inviteInfo->mail ? true : $mailOK;
        }
        if ($mailOK) {
          migrate(json_encode($migrateInfo), $inviteData['attributes'], $inviteData['id']);
        } else {
          # Manual check before migration!!!
          move2Manual($inviteData['id'],$migrateInfo);
        }
      } else {
        # Manual check before migration!!!
        move2Manual($inviteData['id'],$migrateInfo);
      }
    } else {
      showError('Account needs to be at least AL2');
    }
  } else {
    showError('Error while migrating (Got no ePPN or wrong IdP');
  }
} else {
  showError('No action requested');
}

function migrate($migrateInfo, $attributes, $id) {
  global $scim, $invites;
  if ($scim->migrate($migrateInfo, $attributes)) {
    $invites->removeInvite($id);
    $hostURL = "http".(!empty($_SERVER['HTTPS'])?"s":"")."://".$_SERVER['SERVER_NAME'];
    $redirectURL = $hostURL . '/' . $invites->getInstance() . '/?action=migrateSuccess';
    header('Location: ' . $redirectURL);
  } else {
    showError('Error while migrating (Could not update SCIM)');
  }
}

function move2Manual($id,$migrateInfo) {
  global $invites;
  $invites->move2Manual($id,json_encode($migrateInfo));
  showError('Some attributes did not match. Adding your request to queue for manual approval');
}

function showError($error, $exit = true) {
  global $html;

  $html->showHeaders('eduID Connect Self-service');
  print $error;
  if ($exit) {
    print"\n";
    $html->showFooter(false);
    exit;
  }
}
