<?php
const SWAMID_AL2 = 'http://www.swamid.se/policy/assurance/al2'; # NOSONAR

require_once '../vendor/autoload.php';

$config = new scimAdmin\Configuration();

$html = new scimAdmin\HTML($config->mode());

$scim = new scimAdmin\SCIM();

$invites = new scimAdmin\Invites();

$localize = new scimAdmin\Localize();

$sessionID = $_COOKIE['PHPSESSID'];

if (isset($_GET['source'])) {
  $html->setExtraURLPart('&source');
  if ($attributes = $invites->checkSourceData()) {
    $inviteInfo = $invites->getUserDataFromIdP();

    if (isset($inviteInfo['eduPersonPrincipalName'])) {
      if ($scim->ePPNexists($inviteInfo['eduPersonPrincipalName'])) {
        showError(sprintf(_('%s have already have an account.'), $inviteInfo['eduPersonPrincipalName']));
      } elseif ($invites->ePPNexists($inviteInfo['eduPersonPrincipalName'])) {
        showError(sprintf(_('%s have already have an invite, please ask your admin for a resend of invite-code.'), $inviteInfo['eduPersonPrincipalName']));
      }
    }
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
    showError(_('Error while migrating'));
  }
} elseif (isset($_GET['backend'])) {
  $html->setExtraURLPart('&backend');
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
      showError(_('Account needs to be at verified.'));
    }
  } else {
    showError(_('Error while adding account (Got no ePPN or wrong IdP)'));
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
    showError(_('Error while adding account (Could not update SCIM)'));
  }
}

function move2Manual($id,$migrateInfo) {
  global $invites;
  $invites->move2Manual($id,json_encode($migrateInfo));
  showError(_('Some attributes did not match. Adding your request to queue for manual approval. Please contact your admin to activate your account.'));
}

function showError($error, $exit = true) {
  global $html, $config;

  $html->showHeaders(_('eduID Connect Self-service'));
  printf('        %s
        <div class="buttons">
          <a class="btn btn-primary" href="/%s/">%s</a>
        </div>',
    $error, $config->getScope(), _('Back'));
  if ($exit) {
    print"\n";
    $html->showFooter(false);
    exit;
  }
}
