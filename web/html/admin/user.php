<?php
const SCIM_NUTID_SCHEMA = 'https://scim.eduid.se/schema/nutid/user/v1';
const SCIM_USER_SCHEMA = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

require_once '../autoload.php';

$baseDir = dirname($_SERVER['SCRIPT_FILENAME'], 2);
include_once $baseDir . '/config.php';

$html = new scimAdmin\HTML($Mode);

$scim = new scimAdmin\SCIM($baseDir);

$invites = new scimAdmin\Invites($baseDir);

if ($EPPN = $invites->checkBackendData()) {
  $html->showHeaders('eduID Connect Self-service');
  if (! $id = $scim->getIdFromExternalId($EPPN)) {
    showError('        Could not find user in SCIM.<br>Please contact your admin.');
  }
  if (! $invites->checkALLevel(2)) {
    showError('        User or IdP is not at http://www.swamid.se/policy/assurance/al2.');
  }
  $userArray = $scim->getId($id);
  $version = $userArray->meta->version;
  unset($userArray->meta);

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

  if (isset($_SERVER['norEduPersonNIN']) || isset($_SERVER['schacDateOfBirth'])) {
    if (isset($_SERVER['norEduPersonNIN'])) {
      $userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->data->civicNo = $_SERVER['norEduPersonNIN'];
    } elseif (isset($_SERVER['schacDateOfBirth'])) {
      $userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->data->civicNo = $_SERVER['schacDateOfBirth'];
    }
  }
  
  if (! $scim->updateId($id,json_encode($userArray),$version)) {
    showError('Error while migrating (Could not update SCIM)');
  }

  printf ('        <table id="entities-table" class="table table-striped table-bordered">
          <tbody>
            <tr>
              <th colspan="2">
                <h3>User Attributes</h3>
                When you log into a service via your organization identity provider most personal data is retrieved
                from your eduID account but the organization profile contains the organizational information.<br>
                To update the personal data in your eduID account please go to the
                <a href="https://dashboard.eduid.se/">eduID Dashboard</a> and to update the organizational
                profile please contact your home organization service desk or IT support.
              </th>
            </tr>%s',
    "\n");
  if (isset($userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes)) {
    foreach($userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes
      as $key => $value) {

      $value = is_array($value) ? implode(", ", $value) : $value;
      printf ('            <tr><th>%s</th><td>%s</td></tr>%s',
        $key, $value, "\n");
    }
    
  }
  printf ('            <tr><th colspan="2"></th></tr>
            <tr>
              <th colspan="2">
                <h3>Recovery info</h3>
                The recovery info is only used to be able to reconnect the organization profile to you as a person
                if you need to change what eduID account is used for the profile.<br>
                To update the recovery info first update the information in your
                <a href="https://dashboard.eduid.se/">eduID account</a> and thereafter
                automatically update them in this service by logging in again.
              </th>
            </tr>
            <tr><th>Name</th><td>%s</td></tr>
            <tr><th>ID-number</th><td>%s</td></tr>%s',
    isset($userArray->name->formatted) ? $userArray->name->formatted : '',
    isset($userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->data->civicNo) ?
      $userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->data->civicNo : '',
    "\n");

  print "          </tbody>
        </table>\n";
  $html->showFooter(false);
} else {
  $invites->redirectToNewIdP('/admin/user.php');
}

function showError($error, $exit = true) {
  global $html;

  print $error;
  if ($exit) {
    $html->showFooter(false);
    exit;
  }
}