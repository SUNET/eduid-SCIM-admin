<?php
const SCIM_NUTID_SCHEMA = 'https://scim.eduid.se/schema/nutid/user/v1';

require_once '../autoload.php';

$baseDir = dirname($_SERVER['SCRIPT_FILENAME'], 2);
include_once $baseDir . '/config.php';

$html = new scimAdmin\HTML($Mode);

$scim = new scimAdmin\SCIM($baseDir);

$invites = new scimAdmin\Invites($baseDir);

$errors = '';
$errorURL = isset($_SERVER['Meta-errorURL']) ?
  '<a href="' . $_SERVER['Meta-errorURL'] . '">Mer information</a><br>' : '<br>';
$errorURL = str_replace(array('ERRORURL_TS', 'ERRORURL_RP', 'ERRORURL_TID'),
  array(time(), 'https://'. $_SERVER['SERVER_NAME'] . '/shibboleth', $_SERVER['Shib-Session-ID']), $errorURL);

if (isset($_SERVER['Meta-Assurance-Certification'])) {
  $AssuranceCertificationFound = false;
  foreach (explode(';',$_SERVER['Meta-Assurance-Certification']) as $AssuranceCertification) {
    if ($AssuranceCertification == 'http://www.swamid.se/policy/assurance/al1') {
      $AssuranceCertificationFound = true;
    }
  }
  if (! $AssuranceCertificationFound) {
    $errors .= sprintf('%s has no AssuranceCertification (http://www.swamid.se/policy/assurance/al1) ',
      $_SERVER['Shib-Identity-Provider']);
  }
}

if (isset($_SERVER['eduPersonPrincipalName'])) {
  $AdminUser = $_SERVER['eduPersonPrincipalName'];
} elseif (isset($_SERVER['subject-id'])) {
  $AdminUser = $_SERVER['subject-id'];
} else {
  $errors .= 'Missing eduPersonPrincipalName in SAML response ' .
    str_replace(array('ERRORURL_CODE', 'ERRORURL_CTX'),
    array('IDENTIFICATION_FAILURE', 'eduPersonPrincipalName'), $errorURL);
}

if (! $scim->checkAccess($AdminUser)) {
  $errors .= $AdminUser . ' is not allowed to login to this page';
}

if (isset($_SERVER['displayName'])) {
  $fullName = $_SERVER['displayName'];
} elseif (isset($_SERVER['givenName'])) {
  $fullName = $_SERVER['givenName'];
  if(isset($_SERVER['sn'])) {
    $fullName .= ' ' .$_SERVER['sn'];
  }
} else {
  $fullName = '';
}

if ($errors != '') {
  $html->showHeaders('Metadata SWAMID - Problem');
  printf('%s    <div class="row alert alert-danger" role="alert">
      <div class="col">%s        <b>Errors:</b><br>%s        %s%s      </div>%s    </div>%s',
    "\n", "\n", "\n", str_ireplace("\n", "<br>", $errors), "\n", "\n","\n");
  printf('    <div class="row alert alert-info" role="info">%s      <div class="col">
        Logged into wrong IdP ?<br> You are trying with <b>%s</b>.<br>Click <a href="%s">here</a> to logout.
      </div>%s    </div>%s',
     "\n", $_SERVER['Shib-Identity-Provider'],
     'https://'. $_SERVER['SERVER_NAME'] . '/Shibboleth.sso/Logout', "\n", "\n");
  $html->showFooter(false);
  exit;
}

$displayName = '<div> Logged in as : <br> ' . $fullName . ' (' . $AdminUser .')</div>';
$html->setDisplayName($displayName);
$html->showHeaders('SCIM Admin');

if (isset($_POST['action'])) {
  if ($_POST['action'] == 'saveUser' && $id) {
    $id = isset($_POST['id']) ? $scim->validateID($_POST['id']) : false;
    saveUser($id);
    showMenu();
    listUsers($id);
    if ( $scim->getAdminAccess() > 19 ) {
      listInvites($id, true);
    }
  }
} elseif (isset($_GET['action'])) {
  switch ($_GET['action']) {
    case 'editUser' :
      $id = isset($_GET['id']) ? $scim->validateID($_GET['id']) : false;
      if ( $scim->getAdminAccess() > 9 && $id) {
        editUser($id);
      } else {
        showMenu();
        listUsers($id);
        if ( $scim->getAdminAccess() > 19 ) {
          listInvites($id, true);
        }
      }
      break;
    case 'editInvites' :
      break;
    default:
      # listUsers
      showMenu();
      listUsers($id);
      if ( $scim->getAdminAccess() > 19 ) {
        listInvites($id, true);
      }
      break;
  }
} else {
  showMenu();
  listUsers();
  if ( $scim->getAdminAccess() > 19 ) {
    listInvites(0, true);
  }
}
print "    <br>\n";
$html->showFooter(true);

function listUsers($id='0-0', $hidden = false) {
  global $scim;
  $users = $scim->getAllUsers();
  printf('        <table id="list-users-table" class="table table-striped table-bordered list-users"%s>
          <thead>
            <tr><th>externalId</th><th>Name</th><th>Profile</th><th>Linked account</th></tr>
          </thead>
          <tbody>%s', $hidden ? ' hidden' : '', "\n");
  foreach ($users as $user) {
    showUser($user, $id);
  }
  printf('          <tbody>%s        </table>%s', "\n", "\n");
}

function showUser($user, $id) {
  printf('            <tr class="collapsible" data-id="%s" onclick="showId(\'%s\')">
              <td>%s</td>
              <td>%s</td>
              <td>%s</td>
              <td>%s</td>
            </tr>%s',
  $user['externalId'], $user['externalId'], $user['externalId'], $user['fullName'],
  $user['profile'] ? 'X' : '', $user['linked_accounts'] ? 'X' : '', "\n");
  printf('            <tr class="content" style="display: %s;">
              <td><a a href="?action=editUser&id=%s"><button class="btn btn-primary btn-sm">edit user</button></a></td>
              <td colspan="3"><ul>%s', $id == $user['id'] ? 'table-row' : 'none', $user['id'], "\n");
  if ($user['profile']) {
    foreach($user['attributes'] as $key => $value) {
      $value = is_array($value) ? implode(", ", $value) : $value;
      printf ('                <li>%s - %s</li>%s', $key, $value, "\n");
    }
  }
  printf('              </ul></td>%s            </tr>%s', "\n", "\n");
}

function editUser($id) {
  global $scim;

  $userArray = $scim->getId($id);
  printf('    <form method="POST">
      <input type="hidden" name="action" value="saveUser">
      <input type="hidden" name="id" value="%s">', htmlspecialchars($id));
  printf('<table id="entities-table" class="table table-striped table-bordered">%s', "\n");
  printf('      <tbody>%s', "\n");
  printf('        <tr><th>Id</th><td>%s</td></tr>%s', htmlspecialchars($id), "\n");
  printf('        <tr><th>externalId</th><td>%s</td></tr>%s', $userArray->externalId, "\n");
  printf('        <tr><th colspan="2">SAML Attributes</th></tr>%s', "\n");
  $samlAttributes = getSamlAttributes($userArray);

  foreach ($samlAttributes as $attribute => $found) {
    if (! $found) {
      if ($attribute == 'eduPersonScopedAffiliation') {
        showEduPersonScopedAffiliationInput(array(), $scim->getAllowedScopes(), $scim->getPossibleAffiliations());
      } else {
        printf('        <tr><th>%s</th><td><input type="text" name="saml[%s]" value=""></td></tr>%s',
          $attribute, $attribute, "\n");
      }
    }
  }
  printf('      </tbody>%s    </table>
    <div class="buttons">
      <button type="submit" class="btn btn-primary">Submit</button>
      <a href="?action=listUsers&id=%s"><button class="btn btn-secondary">Cancel</button></a>
    </div>%s    </form>%s',
    "\n", htmlspecialchars($id), "\n", "\n");
  if (isset($_GET['debug'])) {
    print "<pre>";
    print_r($userArray);
    print "</pre>";
  }
}

function getSamlAttributes($userArray){
  global $scim;

  # Set up a list of allowd/expected attributes to be able to show unused atribute in edit-form
  $samlAttributes = array();
  foreach ($scim->getAttributes2migrate() as $saml => $SCIM) {
    $samlAttributes[$saml] =false;
  }
  if (isset($userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp)) {
    foreach($userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes
      as $key => $value) {
      if ($key == 'eduPersonScopedAffiliation') {
        showEduPersonScopedAffiliationInput($value, $scim->getAllowedScopes(), $scim->getPossibleAffiliations());
      } else {
        $value = is_array($value) ? implode(", ", $value) : $value;
        printf ('        <tr><th>%s</th><td><input type="text" name="saml[%s]" value="%s"></td></tr>%s',
          $key, $key, $value, "\n");
      }
      $samlAttributes[$key] = true;
    }
  }
  return $samlAttributes;
}

function saveUser($id) {
  if (isset($_POST['saml'])) {
    global $scim;
    $userArray = $scim->getId($id);
    
    $version = $userArray->meta->version;
    unset($userArray->meta);

    $schemaNutidFound = false;
    foreach ($userArray->schemas as $schema) {
      $schemaNutidFound = $schema == SCIM_NUTID_SCHEMA ? true : $schemaNutidFound;
    }
    if (! $schemaNutidFound) {
      $userArray->schemas[] = SCIM_NUTID_SCHEMA;
    }

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

    foreach ($_POST['saml'] as $key => $value) {
      $value = $key == 'eduPersonScopedAffiliation' ?
        parseEduPersonScopedAffiliation($value, $scim->getAllowedScopes(), $scim->getPossibleAffiliations()) :
        $value;
      if ($value == '') {
        if (isset($userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->$key)) {
          unset($userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->$key);
        }
      } else {
        $userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->$key = $value;
      }
    }
    $scim->updateId($id,json_encode($userArray),$version);
  }
}

function showEduPersonScopedAffiliationInput($values, $allowedScopes, $possibleAffiliations) {
  foreach ($allowedScopes as $scope) {
    $existingAffiliation[$scope] = array();
    foreach ($possibleAffiliations as $affiliation => $depend) {
      $existingAffiliation[$scope][$affiliation] = false;
    }
  }

  foreach ($values as $affiliation) {
    $affiliationArray = explode('@', $affiliation);
    $scope = isset($affiliationArray[1]) ? $affiliationArray[1] : 'unset';
    $existingAffiliation[$scope][$affiliationArray[0]] = true;
  }
  printf ('        <tr><th>eduPersonScopedAffiliation</th><td>%s', "\n");
  foreach ($allowedScopes as $scope) {
    printf ('          <h5>Scope : %s</h5>%s', $scope, "\n");
    foreach ($possibleAffiliations as $affiliation => $depend) {
      printf ('          <input type="checkbox"%s name="saml[eduPersonScopedAffiliation][%s]"> %s<br>%s',
        $existingAffiliation[$scope][$affiliation] ? ' checked' : '', $affiliation . '@' . $scope, $affiliation,
        "\n");
    }
  }
  printf ('        </td></tr>%s', "\n");
}

function parseEduPersonScopedAffiliation($value, $allowedScopes, $possibleAffiliations) {
  $returnArray = array();
  foreach ($value as $affiliation => $on) {
    $affiliationArray = explode('@', $affiliation);
    if (in_array($affiliationArray[1], $allowedScopes)) {
      $returnArray[] = $affiliation;
    }
  }

  do {
    $added = false;
    foreach ($returnArray as $affiliation) {
      $affiliationArray = explode('@', $affiliation);
      $checkedAffiliation = $affiliationArray[0];
      $checkedScope = '@' . $affiliationArray[1];
      if ($possibleAffiliations[$checkedAffiliation] <> '' &&
        ! in_array($possibleAffiliations[$checkedAffiliation].$checkedScope, $returnArray)) {
        # Add dependent affilaiation
        $added = true;
        $returnArray[] = $possibleAffiliations[$checkedAffiliation].$checkedScope;
      }
    }
  } while ($added);
  return $returnArray;
}

function showMenu() {
  global $scim;
  print '        <label for="select">Select a list</label>
        <div class="select">
          <select id="selectList">
            <option value="List Users">List Users</option>';
  if ( $scim->getAdminAccess() > 19 ) {
    print '
            <option value="List invites">List invites</option>';
  }
  print '
          </select>
        </div>' . "\n";
  print '        <div class="result"></div>';
  print "\n        <br>\n        <br>\n";
}

function listInvites ($id = 0, $hidden = false) {
  global $invites;
  printf('        <table id="list-invites-table" class="table table-striped table-bordered list-invites"%s>
          <thead>
            <tr><th>Active</th><th>Last modified</th><th>Name</th></tr>
          </thead>
          <tbody>%s', $hidden ? ' hidden' : '', "\n");
  foreach ($invites->getInvitesList() as $invite) {
    showInvite($invite, $id);
  }
  printf('          </tbody>%s       </table>%s', "\n", "\n");
}

function showInvite($invite, $id) {
  #print_r($invite);
  $inviteInfo = json_decode($invite['inviteInfo']);
  printf('            <tr class="collapsible" data-id="%s" onclick="showId(\'%s\')">
              <td>%s</td>
              <td>%s</td>
              <td>%s</td>
            </tr>%s',
    $invite['id'], $invite['id'],
    $invite['session'] == '' ? '' : 'X',
    $invite['modified'],
    $inviteInfo->givenName . ' ' . $inviteInfo->sn,
    "\n");
  if ($invite['status'] == 1) {
    printf('            <tr class="content" style="display: %s;">
                <td><a a href="?action=editInvite&id=%s">
                  <button class="btn btn-primary btn-sm">edit invite</button></a>
                </td>
                <td>Attributes : <ul>%s', $id == $invite['id'] ? 'table-row' : 'none', $invite['id'], "\n");
    foreach(json_decode($invite['attributes']) as $key => $value) {
      $value = is_array($value) ? implode(", ", $value) : $value;
      printf ('                <li>%s - %s</li>%s', $key, $value, "\n");
    }
    printf('              </ul></td>%s              <td>InviteInfo : <ul%s', "\n", "\n");
    foreach($inviteInfo as $key => $value) {
      $value = is_array($value) ? implode(", ", $value) : $value;
      printf ('                <li>%s - %s</li>%s', $key, $value, "\n");
    }
  } else {

  }
  printf('              </ul></td>%s            </tr>%s', "\n", "\n");
    /*Array ( 
      [id] => 5 
      [instance] => sunet.se 
      [hash] => 
      [status] => 
      [session] => ufgd1lnnt99ciori8qkr2fdvfp 
      [modified] => 2023-10-06 12:41:02 
      [attributes] => {"eduPersonPrincipalName":"bjorn@sunet.se","eduPersonScopedAffiliation":["employee@sunet.se","member@sunet.se"],"mail":"bjorn@sunet.se"} 
      [inviteInfo] => {"givenName":"Bj\u00f6rn","mail":"bjorn@sunet.se","sn":"Mattsson","personNIN":""} 
      [migrateInfo] => )*/

  /*foreach (json_decode($invite['attributes']) as $SCIM => $attribute) {
    $attribute = is_array($attribute) ? implode(", ", $attribute) : $attribute;
    printf('<li>%s - %s</li>', $SCIM, $attribute);
  }*/
}
