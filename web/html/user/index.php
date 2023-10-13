<?php
const SCIM_NUTID_SCHEMA = 'https://scim.eduid.se/schema/nutid/user/v1';
const SCIM_USER_SCHEMA = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

require_once '../autoload.php';

$baseDir = dirname($_SERVER['SCRIPT_FILENAME'], 2);
include_once $baseDir . '/config.php'; # NOSONAR

$html = new scimAdmin\HTML($Mode);

$scim = new scimAdmin\SCIM($baseDir);

$invites = new scimAdmin\Invites($baseDir);

if ($invites->checkCorrectBackendIdP()) {
  if ($migrateInfo = $invites->checkBackendData()) {
    $ePPN = $migrateInfo['eduPersonPrincipalName'];
    $html->showHeaders('eduID Connect Self-service');
    if (! $id = $scim->getIdFromExternalId($ePPN)) {
      showError('        Could not find user in SCIM.<br>Please contact your admin.');
    }
    if (! $invites->checkALLevel(2)) {
      showError('        User or IdP is not at http://www.swamid.se/policy/assurance/al2.');
    }
    $userArray = $scim->getId($id);
    $version = $userArray->meta->version;
    unset($userArray->meta);

    if (strlen($migrateInfo['givenName'] . $migrateInfo['sn']) > 1) {
      $fullName = '';
      if (! isset($userArray->{'name'})) {
        $userArray->name = new \stdClass();
      }
      if (strlen($migrateInfo['givenName']) > 1) {
        $userArray->name->givenName = $migrateInfo['givenName'];
        $fullName = $migrateInfo['givenName'];
      }
      if (strlen($migrateInfo['sn']) > 1) {
        $userArray->name->familyName = $migrateInfo['sn'];
        $fullName .= ' ' . $migrateInfo['sn'];
      }
      $userArray->name->formatted = $fullName;
    }

    if (strlen($migrateInfo['norEduPersonNIN'] . $migrateInfo['schacDateOfBirth']) > 1) {
      if (strlen($migrateInfo['norEduPersonNIN']) > 1 ) {
        $userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->data->civicNo = $migrateInfo['norEduPersonNIN'];
      } elseif (strlen($migrateInfo['schacDateOfBirth']) > 1) {
        $userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->data->civicNo = $migrateInfo['schacDateOfBirth'];
      }
    }

    if (! $scim->updateId($id,json_encode($userArray),$version)) {
      showError('        Error while update recovery info (Could not update SCIM)');
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
    $html->showHeaders('eduID Connect Self-service');
    showError('        Did not get any ePPN from IdP!');
  }
} else {
  $invites->redirectToNewIdP('/user');
}

function showError($error, $exit = true) {
  global $html;

  print $error;
  if ($exit) {
    print"\n";
    $html->showFooter(false);
    exit;
  }
}
