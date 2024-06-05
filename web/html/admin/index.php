<?php
const SCIM_NUTID_SCHEMA = 'https://scim.eduid.se/schema/nutid/user/v1';
const LI_ITEM = '                <li>%s - %s</li>%s';

require_once '../vendor/autoload.php';

$config = new scimAdmin\Configuration();

$html = new scimAdmin\HTML($config->mode());

$scim = new scimAdmin\SCIM();

$errors = '';
$errorURL = isset($_SERVER['Meta-errorURL']) ?
  '<a href="' . $_SERVER['Meta-errorURL'] . '">Mer information</a><br>' : '<br>';
$errorURL = str_replace(array('ERRORURL_TS', 'ERRORURL_RP', 'ERRORURL_TID'),
  array(time(), 'https://'. $_SERVER['SERVER_NAME'] . '/shibboleth', $_SERVER['Shib-Session-ID']), $errorURL);

if (isset($_SERVER['Meta-Assurance-Certification'])) {
  $AssuranceCertificationFound = false;
  foreach (explode(';',$_SERVER['Meta-Assurance-Certification']) as $AssuranceCertification) {
    if ($AssuranceCertification == 'http://www.swamid.se/policy/assurance/al1') { # NOSONAR
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

if ($config->scopeConfigured()) {
  if (! $scim->checkAccess($AdminUser)) {
    $errors .= $AdminUser . ' is not allowed to login to this page.';
  }
} else {
  $userScope = preg_replace('/(.+)@/i', '', $AdminUser);
  if ($scim->checkScopeExists($userScope)) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . '/' . $userScope . '/admin/');
    exit;
  } else {
    $errors .= $userScope . ' is not configured for this service.';
  }
}

if ($errors != '') {
  $html->showHeaders('Metadata SWAMID - Problem');
  printf('%s    <div class="row alert alert-danger" role="alert">
      <div class="col">%s        <b>Errors:</b><br>%s        %s%s      </div>%s    </div>%s',
    "\n", "\n", "\n", str_ireplace("\n", "<br>", $errors), "\n", "\n","\n");
  printf('    <div class="row alert alert-info" role="info">%s      <div class="col">
        ' . _('Logged into wrong IdP ?<br> You are trying with <b>%s</b>.<br>Click <a href="%s">here</a> to logout.') .'
      </div>%s    </div>%s',
     "\n", $_SERVER['Shib-Identity-Provider'],
     'https://'. $_SERVER['SERVER_NAME'] . '/Shibboleth.sso/Logout', "\n", "\n");
  $html->showFooter(false);
  exit;
}

$invites = new scimAdmin\Invites();

$displayName = '<div> Logged in as : <br> ' . $fullName . ' (' . $AdminUser .')</div>';
$html->setDisplayName($displayName);
$html->showHeaders('SCIM Admin');

if (isset($_POST['action'])) {
  $id = isset($_POST['id']) ? $invites->validateID($_POST['id']) : false;
  switch ($_POST['action']) {
    case 'saveUser' :
      if ( $scim->getAdminAccess() > 19 ) {
        if ($id) {
          saveUser($id);
        }
        showMenu();
        listUsers($id);
        listInvites($id, true);
      } else {
        showMenu();
        listUsers($id);
      }
      break;
    case 'saveInvite' :
      if ( $scim->getAdminAccess() > 19 && ($id == 0 || $id)) {
        saveInvite($id);
      }
      showMenu(2);
      listUsers('', true);
      if ( $scim->getAdminAccess() > 19 ) {
        listInvites($id);
      }
      break;
    case 'deleteInvite' :
      if ( $scim->getAdminAccess() > 19 ) {
        if ($id) {
          $invites->removeInvite($id);
        }
        showMenu();
        listUsers($id, true);
        listInvites($id);
      } else {
        showMenu();
        listUsers($id);
      }
      break;
    default:
  }
} elseif (isset($_GET['action'])) {
  switch ($_GET['action']) {
    case 'editUser' :
      $actionURL='&action=deleteInvite';
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
    case 'listUsers' :
      $id = isset($_GET['id']) ? $scim->validateID($_GET['id']) : false;
      showMenu();
      listUsers($id);
      if ( $scim->getAdminAccess() > 19 ) {
        listInvites($id, true);
      }
      break;
    case 'editInvite' :
      $id = isset($_GET['id']) ? $invites->validateID($_GET['id']) : false;
      if ( $scim->getAdminAccess() > 19 && $id) {
        editInvite($id);
      } else {
        showMenu(2);
        listUsers('', true);
        if ( $scim->getAdminAccess() > 19 ) {
          listInvites($id);
        }
      }
      break;
    case 'resendInvite' :
      $id = isset($_GET['id']) ? $invites->validateID($_GET['id']) : false;
      if ( $scim->getAdminAccess() > 19 && $id) {
        resendInvite($id);
      } else {
        showMenu(2);
        listUsers('', true);
        if ( $scim->getAdminAccess() > 19 ) {
          listInvites($id);
        }
      }
      break;
    case 'listInvites' :
      $id = isset($_GET['id']) ? $invites->validateID($_GET['id']) : false;
      showMenu(2);
      if ( $scim->getAdminAccess() > 19 ) {
        listUsers('', true);
        listInvites($id);
      } else {
        listUsers('');
      }
      break;
    case 'approveInvite' :
      $id = isset($_GET['id']) ? $invites->validateID($_GET['id']) : false;
      showMenu(2);
      if ( $scim->getAdminAccess() > 19 ) {
        if ($id) {
          approveInvite($id);
        }
        listUsers('', true);
        listInvites($id);
      } else {
        listUsers('');
      }
      break;
    case 'addInvite' :
      if ( $scim->getAdminAccess() > 19) {
        editInvite(0);
      } else {
        showMenu(2);
        listUsers('', true);
        if ( $scim->getAdminAccess() > 19 ) {
          listInvites($id);
        }
      }
      break;
    case 'deleteInvite' :
      $id = isset($_GET['id']) ? $invites->validateID($_GET['id']) : false;
      if ( $scim->getAdminAccess() > 19) {
        deleteInvite($id);
      } else {
        showMenu(2);
        listUsers('', true);
        if ( $scim->getAdminAccess() > 19 ) {
          listInvites($id);
        }
      }
      break;
    default:
      # listUsers
      showMenu();
      listUsers();
      if ( $scim->getAdminAccess() > 19 ) {
        listInvites(0, true);
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
print "        <br>\n";
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
            </tr>
            <tr class="content" style="display: %s;">
              <td><a a href="?action=editUser&id=%s"><button class="btn btn-primary btn-sm">edit user</button></a></td>
              <td colspan="3"><ul>%s',
    $user['externalId'], $user['externalId'], $user['externalId'], $user['fullName'],
    $user['profile'] ? 'X' : '', $user['linked_accounts'] ? 'X' : '',
    $id == $user['id'] ? 'table-row' : 'none', $user['id'], "\n");
  if ($user['profile']) {
    foreach($user['attributes'] as $key => $value) {
      $value = is_array($value) ? implode(", ", $value) : $value;
      printf (LI_ITEM, $key, $value, "\n");
    }
  }
  printf('              </ul></td>%s            </tr>%s', "\n", "\n");
}

function editUser($id) {
  global $scim;

  $userArray = $scim->getId($id);
  printf('        <form method="POST">
          <input type="hidden" name="action" value="saveUser">
          <input type="hidden" name="id" value="%s">
          <table id="entities-table" class="table table-striped table-bordered">
            <tbody>
              <tr><th>Id</th><td>%s</td></tr>
              <tr><th>externalId</th><td>%s</td></tr>
              <tr><th colspan="2">Recovery Information</th></tr>
              <tr><th>Name</th><td>%s</td></tr>
              <tr><th>Pnr</th><td>%s</td></tr>
              <tr><th colspan="2">SAML Attributes</th></tr>%s',
    htmlspecialchars($id), htmlspecialchars($id), $userArray->externalId,
    isset($userArray->name->formatted) ? $userArray->name->formatted : 'Not set!!!',
    isset($userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->data->civicNo) ?
      $userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->data->civicNo :
      'Not set!!!',
    "\n");
  $samlAttributes = getSamlAttributesSCIM($userArray);

  foreach ($samlAttributes as $attribute => $found) {
    if (! $found) {
      if ($attribute == 'eduPersonScopedAffiliation') {
        showEduPersonScopedAffiliationInput(array(), $scim->getAllowedScopes(), $scim->getPossibleAffiliations());
      } else {
        printf('              <tr><th>%s</th><td><input type="text" name="saml[%s]" value=""></td></tr>%s',
          $attribute, $attribute, "\n");
      }
    }
  }
  printf('            </tbody>
          </table>
          <div class="buttons">
            <button type="submit" class="btn btn-primary">Submit</button>
          </div>
        </form>
        <div class="buttons">
          <a href="?action=listUsers&id=%s"><button class="btn btn-secondary">Cancel</button></a>
        </div>%s',
    htmlspecialchars($id), "\n");
  if (isset($_GET['debug'])) {
    print "<pre>";
    print_r($userArray);
    print "</pre>";
  }
}

function getSamlAttributesSCIM($userArray){
  global $scim;

  # Set up a list of allowed/expected attributes to be able to show unused attribute in edit-form
  $samlAttributes = array();
  foreach ($scim->getAttributes2migrate() as $saml => $SCIM) {
    $samlAttributes[$saml] =false;
  }
  if (isset($userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp)) {
    foreach($userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes
      as $key => $value) {
      if ($key == 'eduPersonScopedAffiliation') {
        showEduPersonScopedAffiliationInput($value, $scim->getAllowedScopes(), $scim->getPossibleAffiliations());
      } elseif ($key == 'eduPersonPrincipalName') {
        printf ('              <tr><th>eduPersonPrincipalName</th><td>%s</td></tr>%s',
          $value, "\n");
      } else {
        $value = is_array($value) ? implode(", ", $value) : $value;
        printf ('              <tr><th>%s</th><td><input type="text" name="saml[%s]" value="%s"></td></tr>%s',
          $key, $key, $value, "\n");
      }
      $samlAttributes[$key] = true;
    }
  }
  return $samlAttributes;
}

function getSamlAttributesDB($attributes){
  global $scim;

  # Set up a list of allowed/expected attributes to be able to show unused attribute in edit-form
  $samlAttributes = array();
  foreach ($scim->getAttributes2migrate() as $saml => $SCIM) {
    $samlAttributes[$saml] =false;
  }
  foreach(json_decode($attributes) as $key => $value) {
    if ($key == 'eduPersonScopedAffiliation') {
      showEduPersonScopedAffiliationInput($value, $scim->getAllowedScopes(), $scim->getPossibleAffiliations());
    } else {
      $value = is_array($value) ? implode(", ", $value) : $value;
      printf ('              <tr><th>%s</th><td><input type="text" name="saml[%s]" value="%s"></td></tr>%s',
        $key, $key, $value, "\n");
    }
    $samlAttributes[$key] = true;
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
    if (! isset($userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp)) {
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
  printf ('              <tr><th>eduPersonScopedAffiliation</th><td>%s', "\n");
  foreach ($allowedScopes as $scope) {
    printf ('                <h5>Scope : %s</h5>%s', $scope, "\n");
    foreach ($possibleAffiliations as $affiliation => $depend) {
      printf ('                <input type="checkbox"%s name="saml[eduPersonScopedAffiliation][%s]"> %s<br>%s',
        $existingAffiliation[$scope][$affiliation] ? ' checked' : '', $affiliation . '@' . $scope, $affiliation,
        "\n");
    }
  }
  printf ('              </td></tr>%s', "\n");
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
        # Add dependent affiliation
        $added = true;
        $returnArray[] = $possibleAffiliations[$checkedAffiliation].$checkedScope;
      }
    }
  } while ($added);
  return $returnArray;
}

function showMenu($show = 1) {
  global $scim, $result;
  print '        <label for="select">Select a list</label>
        <div class="select">
          <select id="selectList">
            <option value="List Users">List Users</option>';
  if ( $scim->getAdminAccess() > 19 ) {
    printf('
            <option value="List invites"%s>List invites</option>', $show == 2 ? ' selected' : '');
  }
  print '
          </select>
        </div>' . "\n";
  printf('        <div class="result">%s</div>', $result);
  print "\n        <br>\n        <br>\n";
}

function listInvites ($id = 0, $hidden = false) {
  global $invites;
  printf('        <table id="list-invites-table" class="table table-striped table-bordered list-invites"%s>
          <thead>
            <tr><th>Active</th><th>Last modified</th><th>Name</th></tr>
          </thead>
          <tbody>
            <tr><td colspan="3">
              <a a href="?action=addInvite"><button class="btn btn-primary btn-sm">Add Invite</button></a>
            </td></tr>%s', $hidden ? ' hidden' : '', "\n");
  foreach ($invites->getInvitesList() as $invite) {
    showInvite($invite, $id);
  }
  printf('          </tbody>%s       </table>%s', "\n", "\n");
}

function showInvite($invite, $id) {
  $inviteInfo = json_decode($invite['inviteInfo']);
  $migrateInfo = json_decode($invite['migrateInfo']);
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
                <td>
                  <a a href="?action=editInvite&id=%s">
                    <button class="btn btn-primary btn-sm">edit invite</button>
                  </a><br>
                  <a a href="?action=resendInvite&id=%s">
                    <button class="btn btn-primary btn-sm">resend invite</button>
                  </a><br>
                  <a a href="?action=deleteInvite&id=%s">
                    <button class="btn btn-primary btn-sm">delete invite</button>
                  </a>
                </td>
                <td>Attributes : <ul>%s', $id == $invite['id'] ? 'table-row' : 'none', $invite['id'], $invite['id'], $invite['id'], "\n");
    foreach(json_decode($invite['attributes']) as $key => $value) {
      $value = is_array($value) ? implode(", ", $value) : $value;
      printf (LI_ITEM, $key, $value, "\n");
    }
    printf('              </ul></td>%s              <td>InviteInfo : <ul%s', "\n", "\n");
    foreach($inviteInfo as $key => $value) {
      $value = is_array($value) ? implode(", ", $value) : $value;
      printf (LI_ITEM, $key, $value, "\n");
    }
    printf('              </ul></td>%s            </tr>%s', "\n", "\n");
  } else {
    printf('            <tr class="content" style="display: %s;">
              <td><a a href="?action=approveInvite&id=%s">
                <button class="btn btn-primary btn-sm">approve invite</button></a>
              </td>
              <td colspan="2">
                <div class="row">
                  <div class="col3"></div>
                  <div class="col3">Invite data</div>
                  <div class="col3">From IdP</div>
                </div>
                <div class="row">
                  <div class="col3">personNIN</div>
                  <div class="col3">%s</div>
                  <div class="col3">%s</div>
                </div>
                <div class="row">
                  <div class="col3">givenName</div>
                  <div class="col3">%s</div>
                  <div class="col3">%s</div>
                </div>
                <div class="row">
                  <div class="col3">sn</div>
                  <div class="col3">%s</div>
                  <div class="col3">%s</div>
                </div>
                <div class="row">
                  <div class="col3">mail</div>
                  <div class="col3">%s</div>
                  <div class="col3">%s<br>%s</div>
                </div>
              </td>
            </tr>%s',
      $id == $invite['id'] ? 'table-row' : 'none',
      $invite['id'],
      $inviteInfo->personNIN,
      $migrateInfo->norEduPersonNIN == '' ? $migrateInfo->schacDateOfBirth: $migrateInfo->norEduPersonNIN,
      $inviteInfo->givenName, $migrateInfo->givenName,
      $inviteInfo->sn, $migrateInfo->sn,
      $inviteInfo->mail, $migrateInfo->mail, implode('<br>',explode(';', $migrateInfo->mailLocalAddress)),
      "\n");
    }
}

function editInvite($id) {
  global $scim, $invites;

  if ($id > 0) {
    $invite = $invites->getInvite($id);
  } else {
    $invite = array ('inviteInfo' => '{}', 'attributes' => '{}');
  }
  $inviteInfo = json_decode($invite['inviteInfo']);
  printf('        <form method="POST">
          <input type="hidden" name="action" value="saveInvite">
          <input type="hidden" name="id" value="%s">
          <table id="entities-table" class="table table-striped table-bordered">
            <tbody>
              <tr><th colspan="2">Invite Info</th></tr>
              <tr><th>givenName</th><td><input type="text" name="givenName" value="%s"></td></tr>
              <tr><th>sn</th><td><input type="text" name="sn" value="%s"></td></tr>
              <tr><th>invite mail</th><td><input type="text" name="mail" value="%s"></td></tr>
              <tr><th>personNIN</th><td><input type="text" name="personNIN" value="%s"></td></tr>%s',
      htmlspecialchars($id),
      isset($inviteInfo->givenName) ? $inviteInfo->givenName : '',
      isset($inviteInfo->sn) ? $inviteInfo->sn : '',
      isset($inviteInfo->mail) ? $inviteInfo->mail : '',
      isset($inviteInfo->personNIN) ? $inviteInfo->personNIN : '',
      "\n");

  printf('              <tr><th colspan="2">SAML Attributes</th></tr>%s', "\n");
  $samlAttributes = getSamlAttributesDB($invite['attributes']);

  foreach ($samlAttributes as $attribute => $found) {
    if (! $found) {
      if ($attribute == 'eduPersonScopedAffiliation') {
        showEduPersonScopedAffiliationInput(array(), $scim->getAllowedScopes(), $scim->getPossibleAffiliations());
      } else {
        printf('              <tr><th>%s</th><td><input type="text" name="saml[%s]" value=""></td></tr>%s',
          $attribute, $attribute, "\n");
      }
    }
  }
  printf('            </tbody>%s          </table>
          <div class="buttons">
            <button type="submit" class="btn btn-primary">Submit</button>
          </div>
        </form>
        <div class="buttons">
          <a href="?action=listInvites&id=%s"><button class="btn btn-secondary">Cancel</button></a>
        </div>%s',
    "\n", htmlspecialchars($id), "\n", "\n");
}

function resendInvite($id) {
  global $invites, $scim, $result;
  if ($id > 0) {
    $invites->sendNewInviteCode($id);
    $invite = $invites->getInvite($id);
    $inviteInfo = json_decode($invite['inviteInfo']);
    $result = 'New code sent to ' . $inviteInfo->mail;
  }
  showMenu(2);
  listUsers('', true);
  if ( $scim->getAdminAccess() > 19 ) {
    listInvites($id);
  }
}

function saveInvite($id) {
  global $scim, $invites;
  $inviteArray = array();
  $attributeArray = array();
  $inviteArray['givenName'] = isset($_POST['givenName']) ? $_POST['givenName'] : '';
  $inviteArray['sn'] = isset($_POST['sn']) ? $_POST['sn'] : '';
  $inviteArray['mail'] = isset($_POST['mail']) ? $_POST['mail'] : '';
  $inviteArray['personNIN'] = isset($_POST['personNIN']) ? $_POST['personNIN'] : '';
  if (isset($_POST['saml'])) {
    foreach ($_POST['saml'] as $key => $value) {
      $value = $key == 'eduPersonScopedAffiliation' ?
        parseEduPersonScopedAffiliation($value, $scim->getAllowedScopes(), $scim->getPossibleAffiliations()) :
        $value;
      $attributeArray[$key] = $value;
    }
  }
  $invites->updateInviteAttributesById($id, $attributeArray, $inviteArray);
}

function approveInvite($id) {
  global $scim, $invites;
  $inviteData = $invites->getInvite($id);
  $migrateInfo =$inviteData['migrateInfo'];
  $attributes = $inviteData['attributes'];

  if ($scim->migrate($migrateInfo, $attributes)) {
    $invites->removeInvite($id);
  }
}

function deleteInvite($id)  {
  global $invites;

  if ($id > 0) {
    $invite = $invites->getInvite($id);
  } else {
    $invite = array ('inviteInfo' => '{}', 'attributes' => '{}');
  }
  $inviteInfo = json_decode($invite['inviteInfo']);
  printf('        <form method="POST">
          <input type="hidden" name="action" value="deleteInvite">
          <input type="hidden" name="id" value="%s">
          <table id="entities-table" class="table table-striped table-bordered">
            <tbody>
              <tr><th colspan="2">Invite Info</th></tr>
              <tr><th>givenName</th><td><input type="text" name="givenName" value="%s"></td></tr>
              <tr><th>sn</th><td><input type="text" name="sn" value="%s"></td></tr>
              <tr><th>invite mail</th><td><input type="text" name="mail" value="%s"></td></tr>
              <tr><th>personNIN</th><td><input type="text" name="personNIN" value="%s"></td></tr>%s',
      htmlspecialchars($id),
      isset($inviteInfo->givenName) ? $inviteInfo->givenName : '',
      isset($inviteInfo->sn) ? $inviteInfo->sn : '',
      isset($inviteInfo->mail) ? $inviteInfo->mail : '',
      isset($inviteInfo->personNIN) ? $inviteInfo->personNIN : '',
      "\n");
  printf('            </tbody>%s          </table>
      <div class="buttons">
        <button type="submit" class="btn btn-primary">Delete</button>
      </div>
    </form>
    <div class="buttons">
      <a href="?action=listInvites&id=%s"><button class="btn btn-secondary">Cancel</button></a>
    </div>%s',
    "\n", htmlspecialchars($id), "\n", "\n");
}

