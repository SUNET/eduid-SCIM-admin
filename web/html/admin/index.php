<?php
const SCIM_NUTID_SCHEMA = 'https://scim.eduid.se/schema/nutid/user/v1';
const LI_ITEM = '                <li>%s - %s</li>%s';
const HTML_CHECKED = ' checked';
const HTML_SELECTED = ' selected';

require_once '../vendor/autoload.php';

$config = new scimAdmin\Configuration();

$html = new scimAdmin\HTML(_('Administration of your organisation identities.'));

$scim = new scimAdmin\SCIM();

$localize = new scimAdmin\Localize();

$errors = '';
$collapse = false;
$errorURL = isset($_SERVER['Meta-errorURL']) ?
  '<a href="' . $_SERVER['Meta-errorURL'] . '">Mer information</a><br>' : '<br>';
$errorURL = str_replace(array('ERRORURL_TS', 'ERRORURL_RP', 'ERRORURL_TID'),
  array(time(), 'https://'. $_SERVER['SERVER_NAME'] . '/shibboleth', $_SERVER['Shib-Session-ID']), $errorURL);

if (isset($_SERVER['Meta-Assurance-Certification'])) {
  $AssuranceCertificationFound = false;
  foreach (explode(';',$_SERVER['Meta-Assurance-Certification']) as $AssuranceCertification) {
    if ($AssuranceCertification == 'http://www.swamid.se/policy/assurance/al3') { # NOSONAR
      $AssuranceCertificationFound = true;
    }
  }
  if (! $AssuranceCertificationFound) {
    $errors .= sprintf('%s has no AssuranceCertification (http://www.swamid.se/policy/assurance/al3) ',
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

if (isset($_SERVER['eduPersonAssurance'])) {
  $acceptedAssurance = false;
  foreach (explode(';', $_SERVER['eduPersonAssurance']) as $subAssurance) {
    if ($subAssurance == 'http://www.swamid.se/policy/assurance/al3') { # NOSONAR
      $acceptedAssurance = true;
    }
  }
  if (! $acceptedAssurance) {
    $errors .= _('Account must be at least AL3!');
  }
} else {
  $errors .= _('Missing eduPersonAssurance in SAML response ') .
    str_replace(array('ERRORURL_CODE', 'ERRORURL_CTX'),
    array('IDENTIFICATION_FAILURE', 'eduPersonAssurance'), $errorURL);
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
  $html->showHeaders('SCIM Admin - Problem');
  printf('%s    <div class="row alert alert-danger" role="alert">
      <div class="col">%s        <b>Errors:</b><br>%s        %s%s      </div>%s    </div>%s',
    "\n", "\n", "\n", str_ireplace("\n", "<br>", $errors), "\n", "\n","\n");
  printf('    <div class="row alert alert-info" role="info">%s      <div class="col">
        ' . _('Logged into wrong IdP ?<br> You are trying with <b>%s</b>.<br>Click <a href="%s">here</a> to logout.') .'
      </div>%s    </div>%s',
    "\n", $_SERVER['Shib-Identity-Provider'],
    'https://' . $_SERVER['SERVER_NAME'] . '/Shibboleth.sso/Logout?return=' . urlencode('https://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI']),
    "\n", "\n");
  $html->showFooter(false);
  exit;
}

$invites = new scimAdmin\Invites();

$displayName = '<div> Logged in as : <br> ' . $fullName . ' (' . $AdminUser .')</div>';
$html->setDisplayName($displayName);
$html->showHeaders('SCIM Admin');

$viewAccess = $scim->getAdminAccess() > 9;
$editAccess = $scim->getAdminAccess() > 19;

if (isset($_POST['action'])) {
  switch ($_POST['action']) {
    case 'saveUser' :
      $id = isset($_POST['id']) ? $scim->validateID($_POST['id']) : false;
      if ( $editAccess && $id && isset($_POST['save'])) {
          saveUser($id);
      }
      showLists(1);
      break;
    case 'removeUser' :
      $id = isset($_POST['id']) ? $scim->validateID($_POST['id']) : false;
      if ( $editAccess && $id && isset($_POST['delete'])) {
          $user = $scim->getId($id);
          $version = $user->meta->version;
          $scim->removeUser($id,$version);
      }
      showLists(1);
      break;
    case 'saveInvite' :
      $id = isset($_POST['id']) ? $invites->validateID($_POST['id']) : false;
      $parseErrors = '';
      if ( $editAccess && isset($_POST['save']) ) {
        if (strlen($_POST['givenName']) == 0) {
          $parseErrors .= sprintf('%s %s.', _('GivenName'), _('missing')) . '<br>';
        }
        if (strlen($_POST['sn']) == 0) {
          $parseErrors .= sprintf('%s %s. ', _('SurName'), _('missing')) . '<br>';
        }
        if (strlen($_POST['mail']) == 0) {
          $parseErrors .= sprintf('%s %s. ', _('Invite mail'), _('missing')) . '<br>';
        } elseif (! $invites->validateEmail($_POST['mail'])) {
          $parseErrors .= sprintf('%s %s. ', _('Invite mail'), _('have wrong format')) . '<br>';
        }
        if (strlen($_POST['personNIN']) == 0) {
          $parseErrors .= sprintf('%s %s. ', _('Swedish national identity number'), _('missing')) . '<br>';
        } elseif (!$invites->validateSSN($_POST['personNIN'], true)) {
          $parseErrors .= sprintf('%s %s. ', _('Swedish national identity number'), _('have wrong format')) . '<br>';
        }
        foreach ($_POST['saml'] as $part => $data) {
          switch($part) {
            case 'eduPersonPrincipalName' :
              if ($scim->ePPNexists($data)) {
                $parseErrors .= sprintf(_('%s already have an account.'), htmlspecialchars($data)) . '<br>';
              } elseif ($invites->ePPNexists($data)) {
                $parseErrors .= $invites->getInviteePPNid() == $id ? '' :
                  sprintf(_('%s already have an invite.'), htmlspecialchars($data)) . '<br>';
              }
              if (! $scim->validScope($data)) {
                  $parseErrors .= sprintf(_('%s has an invalid scope.'), htmlspecialchars($data)) . '<br>';
              }
              break;
            case 'mail' :
              if (! $invites->validateEmail($data)) {
                $parseErrors .= sprintf('%s %s. ', _('Organisation mail'), _('have wrong format')) . '<br>';
              }
              break;
            default :
          }
        }
        if ($parseErrors == '' ) {
          if ($id == 0 || $id) {
            saveInvite($id);
          }
          showLists(2, $id);
        } else {
          $html->setExtraURLPart('&action=editInvite&id=' . $id);
          editInvite($id, $parseErrors);
        }
      } else {
        showLists(2, $id);
      }
      break;
    case 'approveInvite' :
      $id = isset($_POST['id']) ? $invites->validateID($_POST['id']) : false;
      if ( $editAccess && $id) {
          approveInvite($id);
      }
      showLists(2, $id);
      break;
    case 'deleteInvite' :
      $id = isset($_POST['id']) ? $invites->validateID($_POST['id']) : false;
      if ( $editAccess && $id && isset($_POST['delete'])) {
          $invites->removeInvite($id);
      }
      showLists(2, $id);
      break;
    case 'addMultiInvite' :
      $html->setExtraURLPart('&action=addMultiInvite');
      if ( $editAccess) {
        multiInvite();
      } else {
        showLists(2, $id);
      }
      break;
    default:
      if ($scim->getAdminAccess() > 29) {
        printf('Missing what to do with action = %s in POST', htmlspecialchars($_POST['action']));
      }
  }
} elseif (isset($_GET['action'])) {
  switch ($_GET['action']) {
    case 'editUser' :
      $html->setExtraURLPart('&action=editUser');
      $id = isset($_GET['id']) ? $scim->validateID($_GET['id']) : false;
      if ( $viewAccess && $id) {
        editUser($id);
      } else {
        showLists(1);
      }
      break;
    case 'listUsers' :
      $html->setExtraURLPart('&action=listUsers');
      showLists(1);
      break;
    case 'removeUser' :
      $id = isset($_GET['id']) ? $scim->validateID($_GET['id']) : false;
      if ( $editAccess ) {
        if ($id) {
          removeUser($id);
        }
      } else {
        showLists(1);
      }
      break;
    case 'editInvite' :
      $id = isset($_GET['id']) ? $invites->validateID($_GET['id']) : false;
      if ( $viewAccess && $id) {
        $html->setExtraURLPart('&action=editInvite&id=' . $id);
        editInvite($id);
      } else {
        showLists(2, $id);
      }
      break;
    case 'resendInvite' :
      $id = isset($_GET['id']) ? $invites->validateID($_GET['id']) : false;
      if ( $editAccess && $id) {
        $html->setExtraURLPart('&action=resendInvite&id=' . $id);
        resendInvite($id);
      } else {
        showLists(2, $id);
      }
      break;
    case 'listInvites' :
      $html->setExtraURLPart('&action=listInvites');
      $id = isset($_GET['id']) ? $invites->validateID($_GET['id']) : false;
      showLists(2, $id);
      break;
    case 'approveInvite' :
      $id = isset($_GET['id']) ? $invites->validateID($_GET['id']) : false;
      if ( $editAccess && $id) {
        $html->setExtraURLPart('&action=approveInvite&id=' . $id);
        showApproveInviteForm($id);
      } else {
        showLists(2, $id);
      }
      break;
    case 'addInvite' :
      $html->setExtraURLPart('&action=addInvite');
      if ( $editAccess) {
        editInvite(0);
      } else {
        showLists(2, $id);
      }
      break;
    case 'addMultiInvite' :
      $html->setExtraURLPart('&action=addMultiInvite');
      if ( $editAccess) {
        multiInvite();
      } else {
        showLists(2, $id);
      }
      break;
    case 'deleteInvite' :
      $id = isset($_GET['id']) ? $invites->validateID($_GET['id']) : false;
      if ( $editAccess) {
        $html->setExtraURLPart('&action=deleteInvite&id=' . $id);
        deleteInvite($id);
      } else {
        showLists(2, $id);
      }
      break;
    case 'refreshUsers' :
      $scim->refreshUsersSQL();
      # listUsers
      showLists(1);
      break;
    case 'restoreUser' :
      $id = isset($_GET['id']) ? $scim->validateID($_GET['id']) : false;
      if ($editAccess && $id) {
        $newId = $scim->restoreUser($id);
        $html->setExtraURLPart('&action=editUser');
        editUser($newId);
      } else {
        showLists(3, $id);
      }
      break;
    default:
      # listUsers
      showLists(1);
      break;
  }
} else {
  showLists(1);
}
print "        <br>\n";
$html->showFooter($collapse);

function showLists($list, $id = '') {
  showMenu($list);
  listUsers($list == 1);
  listInvites($list == 2 ? $id : '', $list == 2);
  listDeletedUsers($list == 3 ? $id : '', $list == 3);
}

function listUsers($shown = false) {
  global $scim, $html;
  $editAccess = $scim->getAdminAccess() > 19;
  $users = $scim->getAllUsers();
  printf('        <table id="list-users-table" class="table table-striped table-bordered list-users"%s>
          <thead>
            <tr><th>ePPN</th><th>Name</th><th>eduID<a href=".?action=refreshUsers"><i class="fa-solid fa-arrows-rotate"></i></a></th><th>&nbsp;</th></tr>
          </thead>
          <tbody>%s', $shown ? '' : ' hidden', "\n");
  foreach ($users as $user) {
    printf('            <tr>
              <td>%s</td>
              <td>%s</td>
              <td>%s</td>
              <td>
                <a a href="?action=editUser&id=%s"><i class="fa fa-pencil-alt"></i></a>%s',
      $user['ePPN'] == '' ? _('Missing') : $user['ePPN'] ,
      $user['fullName'], $user['externalId'],
      $user['id'], "\n");
    if ($editAccess) {
      printf('                <a a href="?action=removeUser&id=%s"><i class="fas fa-trash"></i></a>%s',
        $user['id'], "\n");
    }
    printf('              </td>
            </tr>%s', "\n", "\n");

  }
  printf('          <tbody>%s        </table>%s', "\n", "\n");
  $html->addTableSort('list-users-table');
}

function listDeletedUsers($id='0-0', $shown = false) {
  global $scim;
  $editAccess = $scim->getAdminAccess() > 19;
  $users = $scim->getAllUsers(8);
  printf('        <table id="list-deletedUsers-table" class="table table-striped table-bordered list-users"%s>
          <thead>
            <tr><th>ePPN</th><th>Name</th><th>eduID</tr>
          </thead>
          <tbody>%s', $shown ? '' : ' hidden', "\n");
  foreach ($users as $user) {
    printf('            <tr class="collapsible" data-id="%s" onclick="showId(\'%s\')">
              <td>%s</td>
              <td>%s</td>
              <td>%s</td>
            </tr>
            <tr class="content" style="display: %s;">
              <td colspan="3">
                <a a href="?action=restoreUser&id=%s"><button class="btn btn-primary btn-sm">%s</button></a>%s',
      $user['id'], $user['id'],
      $user['ePPN'] == '' ? _('Missing') : $user['ePPN'] ,
      $user['fullName'], $user['externalId'],
      $id == $user['id'] ? 'table-row' : 'none',
      $user['id'], $editAccess ? _('Restore') : _('View'),
      "\n");
    printf('              </td>
            </tr>%s', "\n", "\n");
  }
  printf('          <tbody>%s        </table>%s', "\n", "\n");
}

function editUser($id) {
  global $scim, $html;

  $editAccess = $scim->getAdminAccess() > 19;

  $html->setExtraURLPart('&action=editUser&id=' . $id);
  $userArray = $scim->getId($id);
  printf('        <h2>%s</h2>
        <p>%s</p>
        <form method="POST">
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
    _('Update User'),
    _('Update information below and hit Save.'),
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
        showEduPersonScopedAffiliationInput(array(), $scim->getAllowedScopes(), $scim->getPossibleAffiliations(), $editAccess);
      } else {
        printf('              <tr><th>%s</th><td><input type="text" name="saml[%s]" value=""%s></td></tr>%s',
          $attribute, $attribute, $editAccess ? '' : ' readonly', "\n");
      }
    }
  }
  printf('            </tbody>
          </table>%s', "\n");
  if ($editAccess) {
    printf('          <div class="buttons">
            <a href="?action=listUsers&id=%s"><button class="btn btn-secondary">%s</button></a>
            <button type="submit" name="save" class="btn btn-primary">%s</button>
          </div>%s',
      htmlspecialchars($id), _('Cancel'),
      _('Save'), "\n");
  }
  printf('        </form>%s',
     "\n");
  if (isset($_GET['debug'])) {
    print "<pre>";
    print_r($userArray);
    print "</pre>";
  }
}

function getSamlAttributesSCIM($userArray){
  global $scim;

  $editAccess = $scim->getAdminAccess() > 19;

  # Set up a list of allowed/expected attributes to be able to show unused attribute in edit-form
  $samlAttributes = array();
  foreach ($scim->getAttributes2migrate() as $saml => $SCIM) {
    $samlAttributes[$saml] =false;
  }
  if (isset($userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp)) {
    foreach($userArray->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes
      as $key => $value) {
      if ($key == 'eduPersonScopedAffiliation') {
        showEduPersonScopedAffiliationInput($value, $scim->getAllowedScopes(), $scim->getPossibleAffiliations(), $editAccess);
      } elseif ($key == 'eduPersonPrincipalName') {
        printf ('              <tr><th>eduPersonPrincipalName</th><td>%s</td></tr>%s',
          $value, "\n");
      } else {
        $value = is_array($value) ? implode(", ", $value) : $value;
        printf ('              <tr><th>%s</th><td><input type="text" name="saml[%s]" value="%s"%s></td></tr>%s',
          $key, $key, $value, $editAccess ? '' : ' readonly', "\n");
      }
      $samlAttributes[$key] = true;
    }
  }
  return $samlAttributes;
}

function getSamlAttributesDB($attributes){
  global $scim;

  $editAccess = $scim->getAdminAccess() > 19;
  # Set up a list of allowed/expected attributes to be able to show unused attribute in edit-form
  $samlAttributes = array();
  foreach ($scim->getAttributes2migrate() as $saml => $SCIM) {
    $samlAttributes[$saml] =false;
  }
  foreach(json_decode($attributes) as $key => $value) {
    if ($key == 'eduPersonScopedAffiliation') {
      showEduPersonScopedAffiliationInput($value, $scim->getAllowedScopes(), $scim->getPossibleAffiliations(), $editAccess);
    } else {
      $value = is_array($value) ? implode(", ", $value) : $value;
      printf ('              <tr><th>%s</th><td><input type="text" name="saml[%s]" value="%s"%s></td></tr>%s',
        $key, $key, $value, $editAccess ? '' : ' readonly', "\n");
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
        parseEduPersonScopedAffiliation($value) :
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

function removeUser($id) {
  global $scim;
  $user = $scim->getId($id);
  printf('        <h2>%s</h2>
        <p>%s</p>
        <form method="POST">
          <input type="hidden" name="action" value="removeUser">
          <input type="hidden" name="id" value="%s">
          <table id="entities-table" class="table table-striped table-bordered">
            <tbody>
              <tr><th colspan="2">User Info</th></tr>
              <tr><th>Name</th><td>%s</td></tr>
              <tr><th>ePPN</th><td>%s</td></tr>
              <tr><th>Mail</th><td>%s</td></tr>
            </tbody>
          </table>
          <div class="buttons">
            <a href="?action=listUsers&id=%s"><button class="btn btn-secondary">%s</button></a>
            <button type="submit" name="delete" class="btn btn-primary">%s</button>
          </div>
        </form>%s',
    _('Remove User'),
    _('Do you want to remove the user shown below ?'),
    $user->id,
    isset($user->name->formatted) ? $user->name->formatted : '',
    isset($user->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->eduPersonPrincipalName) ?
      $user->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->eduPersonPrincipalName : '',
    isset($user->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->mail) ?
      $user->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->mail : '',
    htmlspecialchars($id), _('Cancel'),
    _('Delete'), "\n");
}

function showEduPersonScopedAffiliationInput($values, $allowedScopes, $possibleAffiliations, $editAccess) {
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
  printf ('              <tr>
                <th>eduPersonScopedAffiliation</th>
                <td>%s', "\n");
  foreach ($allowedScopes as $scope) {
    printf ('                  <h5>Scope : %s</h5>%s', $scope, "\n");
    foreach ($possibleAffiliations as $affiliation => $depend) {
      printf ('                  <input type="checkbox"%s name="saml[eduPersonScopedAffiliation][%s]"%s> %s<br>%s',
        $existingAffiliation[$scope][$affiliation] ? HTML_CHECKED : '', $affiliation . '@' . $scope, $editAccess ? '' : ' disabled', $affiliation,
        "\n");
    }
  }
  printf ('                </td>
              </tr>%s', "\n");
}

function parseEduPersonScopedAffiliation($value) {
  global $scim;
  $returnArray = array();
  foreach ($value as $affiliation => $on) {
    $affiliationArray = explode('@', $affiliation);
    if (in_array($affiliationArray[1], $scim->getAllowedScopes())) {
      $returnArray[] = $affiliation;
    }
  }
  return $scim->expandePSA($returnArray);
}

function showMenu($show = 1) {
  global $result, $collapse;
  $collapse = true;
  printf ('        <h2>%s</h2>
        <p>%s</p>
        <label for="selectList">%s</label>
        <div class="select">
          <select id="selectList">
            <option value="List Users">%s</option>
            <option value="List invites"%s>%s</option>
            <option value="List Deleted Users"%s>%s</option>
          </select>
        </div>%s',
    _('Handle users'),
    _('Select a view in controller below; Users if you want to see, edit or delete a user, or Invites if you want to add one or more users at the same time.'),
    _('Select a list'),
    _('Users') ,
    $show == 2 ? HTML_SELECTED : '', _('Invites'),
    $show == 3 ? HTML_SELECTED : '', _('Deleted Users'),
    "\n");

  printf('        <div class="result">%s</div>', $result);
  print "\n        <br>\n        <br>\n";
}

function listInvites($id = 0, $show = false) {
  global $invites, $scim;
  $editAccess = $scim->getAdminAccess() > 19;
  printf('        <table id="list-invites-table" class="table table-striped table-bordered list-invites"%s>%s', $show ? '' : ' hidden', "\n");
  if ($editAccess) {
    printf('          <thead>
            <tr><td colspan="3">
              <a a href="?action=addInvite"><button class="btn btn-primary btn-sm">%s</button></a>
              <a a href="?action=addMultiInvite"><button class="btn btn-primary btn-sm">%s</button></a>
            </td></tr>
          </thead>%s',
   _('Add Invite'), _('Add multiple Invites'), "\n");
  }
  printf('          <thead>
            <tr><th></th><th>%s</th><th>%s</th></tr>
          </thead>
          <tbody>%s',
    _('Last modified'), _('Name'), "\n");
  $oldStatus = 0;
  foreach ($invites->getInvitesList() as $invite) {
    if ($invite['status'] != $oldStatus) {
      printf('            <tr><td colspan="3"><b>%s</b></td></tr>%s',
        $invite['status'] == 1 ? _('Waiting for onboarding') : _('Waiting for approval'), "\n");
      $oldStatus = $invite['status'];
    }
    showInvite($invite, $id, $editAccess);
  }
  printf('          </tbody>%s        </table>%s', "\n", "\n");
}

function showInvite($invite, $id, $editAccess) {
  $inviteInfo = json_decode($invite['inviteInfo']);
  $migrateInfo = json_decode($invite['migrateInfo']);
  printf('            <tr class="collapsible" data-id="%s" onclick="showId(\'%s\')">
              <td></td>
              <td>%s</td>
              <td>%s</td>
            </tr>
            <tr class="content" style="display: %s;">%s',
    $invite['id'], $invite['id'],
    $invite['modified'],
    $inviteInfo->givenName . ' ' . $inviteInfo->sn,
    $id == $invite['id'] ? 'table-row' : 'none',
    "\n");
  if ($invite['status'] == 1) {
    printf('              <td>
                <a a href="?action=editInvite&id=%s">
                  <button class="btn btn-primary btn-sm">%s</button>
                </a>',
      $invite['id'], $editAccess ? _('Edit') : _('View'));
    if ($editAccess) {
      printf('<br>
                <a a href="?action=resendInvite&id=%s">
                  <button class="btn btn-primary btn-sm">%s</button>
                </a><br>
                <a a href="?action=deleteInvite&id=%s">
                  <button class="btn btn-primary btn-sm">%s</button>
                </a>',
        $invite['id'], _('Resend'),
        $invite['id'], _('Delete'));
    }
    printf('%s              </td>
              <td>Attributes : <ul>%s', "\n", "\n");
    foreach(json_decode($invite['attributes']) as $key => $value) {
      $value = is_array($value) ? implode(", ", $value) : $value;
      printf (LI_ITEM, $key, $value, "\n");
    }
    printf('              </ul></td>%s              <td>InviteInfo : <ul>%s', "\n", "\n");
    foreach($inviteInfo as $key => $value) {
      $value = is_array($value) ? implode(", ", $value) : $value;
      printf (LI_ITEM, $key, $value, "\n");
    }
    printf('              </ul></td>%s            </tr>%s', "\n", "\n");
  } else {
      printf('              <td>%s', "\n");
    if ($editAccess) {
      printf('                <a a href="?action=approveInvite&id=%s">
                  <button class="btn btn-primary btn-sm">%s</button>
                </a><br>%s',
        $invite['id'], _('Approve'), "\n");
    }
    printf('                <a a href="?action=editInvite&id=%s">
                    <button class="btn btn-primary btn-sm">%s</button>
                </a>
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
      $invite['id'], $editAccess ? _('Edit') : _('View'),
      $inviteInfo->personNIN,
      $migrateInfo->norEduPersonNIN == '' ? $migrateInfo->schacDateOfBirth: $migrateInfo->norEduPersonNIN,
      $inviteInfo->givenName, $migrateInfo->givenName,
      $inviteInfo->sn, $migrateInfo->sn,
      $inviteInfo->mail, $migrateInfo->mail, implode('<br>',explode(';', $migrateInfo->mailLocalAddress)),
      "\n");
    }
}

function editInvite($id, $error = '') {
  global $scim, $invites;

  $editAccess = $scim->getAdminAccess() > 19;

  if ($id > 0) {
    $invite = $invites->getInvite($id);
  } else {
    $invite = array ('status' => 0, 'inviteInfo' => '{}', 'attributes' => '{}', 'lang' => '');
  }
  printf('        <h2>%s</h2>
        <p>%s</p>%s',
      $id == 0 ? _('Add Invite') : _('Update Invite'),
      _('Update information below and hit Save.'),
      "\n"
    );
  if ($invite['status'] == 2 && $editAccess) {
    printf('        <div class="row alert alert-danger">%s</div>%s', _('You are editing an invite waiting for approval. If you save it will be converted back to waiting for onboarding!'), "\n");
  }
  $inviteInfo = json_decode($invite['inviteInfo']);
  # If POST exists some error occurred, save posted data to be edited
  if (isset($_POST['lang'])) {
    $invite['lang'] = $_POST['lang'];
  }
  foreach (array('givenName', 'sn', 'mail', 'personNIN') as $part) {
    if (isset($_POST[$part])) {
      $inviteInfo->$part = $_POST[$part];
    }
  }
  if (isset($_POST['saml'])) {
    $attributeArray = array();
    foreach ($_POST['saml'] as $key => $value) {
      $value = $key == 'eduPersonScopedAffiliation' ?
        parseEduPersonScopedAffiliation($value) :
        $value;
      $attributeArray[$key] = $value;
    }
    $invite['attributes'] = json_encode($attributeArray);
  }
  if ($error != '' ) {
    printf('        <div class="row alert-danger" role="alert">%s</div>%s', $error, "\n");
  }
  printf('        <form method="POST">
          <input type="hidden" name="action" value="saveInvite">
          <input type="hidden" name="id" value="%s">
          <table id="entities-table" class="table table-striped table-bordered">
            <tbody>
              <tr><th colspan="2">%s</th></tr>
              <tr><th>%s</th><td><input type="text" name="givenName" value="%s"%s></td></tr>
              <tr><th>%s</th><td><input type="text" name="sn" value="%s"%s></td></tr>
              <tr><th>%s</th><td><input type="text" name="mail" value="%s"%s></td></tr>
              <tr><th>%s</th><td><input type="text" name="personNIN" value="%s"%s></td></tr>
              <tr>
                <th>%s</th>
                <td>
                  <div class="select">
                    <select name="lang"%s>
                      <option value="sv">%s</option>
                      <option value="en"%s>%s</option>
                    </select>
                  </div>
                </td>
              </tr>%s',
      htmlspecialchars($id),
      _('Invite Info'),
      _('GivenName'), isset($inviteInfo->givenName) ? $inviteInfo->givenName : '', $editAccess ? '' : ' readonly',
      _('SurName'), isset($inviteInfo->sn) ? $inviteInfo->sn : '', $editAccess ? '' : ' readonly',
      _('Invite mail'), isset($inviteInfo->mail) ? $inviteInfo->mail : '', $editAccess ? '' : ' readonly',
      _('Swedish national identity number'), isset($inviteInfo->personNIN) ? $inviteInfo->personNIN : '', $editAccess ? '' : ' readonly',
      _('Language for invite'), $editAccess ? '' : ' disabled', _('Swedish'),
      $invite['lang'] == 'en' ? HTML_SELECTED : '', _('English'),
      "\n");

  printf('              <tr><th colspan="2">SAML Attributes</th></tr>%s', "\n");
  $samlAttributes = getSamlAttributesDB($invite['attributes']);

  foreach ($samlAttributes as $attribute => $found) {
    if (! $found) {
      if ($attribute == 'eduPersonScopedAffiliation') {
        showEduPersonScopedAffiliationInput(array(), $scim->getAllowedScopes(), $scim->getPossibleAffiliations(), $editAccess);
      } else {
        printf('              <tr><th>%s</th><td><input type="text" name="saml[%s]" value=""%s></td></tr>%s',
          $attribute, $attribute, $editAccess ? '' : ' readonly', "\n");
      }
    }
  }
  printf('            </tbody>
          </table>%s', "\n");
  if ($editAccess) {
    printf('          <div class="buttons">
            <a href="?action=listInvites&id=%s"><button class="btn btn-secondary">%s</button></a>
            <button type="submit" name="save" class="btn btn-primary">%s</button>
          </div>%s',
      htmlspecialchars($id), _('Cancel'),
      _('Save'), "\n");
  }
  printf('        </form>
        <div class="buttons">
        </div>%s',
     "\n");
}

function resendInvite($id) {
  global $invites, $scim, $result;
  if ($id > 0) {
    $invites->sendNewInviteCode($id);
    $invite = $invites->getInvite($id);
    $inviteInfo = json_decode($invite['inviteInfo']);
    $result = _('New code sent to') . ' ' . $inviteInfo->mail;
  }
  showMenu(2);
  listUsers();
  listInvites($id, true);
  listDeletedUsers();
}

function saveInvite($id) {
  global $invites;
  $inviteArray = array();
  $attributeArray = array();
  $inviteArray['givenName'] = isset($_POST['givenName']) ? $_POST['givenName'] : '';
  $inviteArray['sn'] = isset($_POST['sn']) ? $_POST['sn'] : '';
  $inviteArray['mail'] = isset($_POST['mail']) ? $_POST['mail'] : '';
  $inviteArray['personNIN'] = isset($_POST['personNIN']) ? $_POST['personNIN'] : '';
  $lang = (isset($_POST['lang']) && $_POST['lang'] == 'en') ? 'en' : 'sv';
  if (isset($_POST['saml'])) {
    foreach ($_POST['saml'] as $key => $value) {
      $value = $key == 'eduPersonScopedAffiliation' ?
        parseEduPersonScopedAffiliation($value) :
        $value;
      $attributeArray[$key] = $value;
    }
  }
  $invites->updateInviteAttributesById($id, $attributeArray, $inviteArray, $lang);
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

function showApproveInviteForm ($id) {
  global $invites;
  $invite = $invites->getInvite($id);
  $inviteInfo = json_decode($invite['inviteInfo']);
  $migrateInfo = json_decode($invite['migrateInfo']);
  printf('        <div>%s<br>%s</div>%s',
    _("Please verify that it's the same person logged in from eduID that was invited."),
    _("personNIN from eduID (12 numbers) should match what's shown on their identification documents OR birth date (8 numbers) + GivenName, SurName"),
     "\n");
  printf('        <form method="POST">
          <input type="hidden" name="action" value="approveInvite">
          <input type="hidden" name="id" value="%s">
          <table id="entities-table" class="table table-striped table-bordered">
            <tbody>
              <tr><td>&nbsp;</td><td>Invite data</td><td>From eduID</td></tr>
              <tr><td>personNIN</td><td>%s</td><td>%s</td></tr>
              <tr><td>%s</td><td>%s</td><td>%s</td></tr>
              <tr><td>%s</td><td>%s</td><td>%s</td></tr>
              <tr><td>mail</td><td>%s</td><td>%s<br>%s</td></tr>
            </tbody>
          </table>
          <div class="buttons">
            <button type="submit" class="btn btn-primary">%s</button>
          </div>
        </form>
        <div class="buttons">
          <a href="?action=listInvites&id=%s"><button class="btn btn-secondary">%s</button></a>
        </div>%s',
    $invite['id'],
    $inviteInfo->personNIN,
    $migrateInfo->norEduPersonNIN == '' ? $migrateInfo->schacDateOfBirth: $migrateInfo->norEduPersonNIN,
    _('GivenName'), $inviteInfo->givenName, $migrateInfo->givenName,
    _('SurName'), $inviteInfo->sn, $migrateInfo->sn,
    $inviteInfo->mail, $migrateInfo->mail, implode('<br>',explode(';', $migrateInfo->mailLocalAddress)),
    _('Approve'), $invite['id'], _('Cancel'), "\n");
}

function deleteInvite($id)  {
  global $invites;

  if ($id > 0) {
    $invite = $invites->getInvite($id);
  } else {
    $invite = array ('inviteInfo' => '{}', 'attributes' => '{}');
  }
  $inviteInfo = json_decode($invite['inviteInfo']);
  printf('                <h2>%s</h2>
        <p>%s</p>
        <form method="POST">
          <input type="hidden" name="action" value="deleteInvite">
          <input type="hidden" name="id" value="%s">
          <table id="entities-table" class="table table-striped table-bordered">
            <tbody>
              <tr><th colspan="2">Invite Info</th></tr>
              <tr><th>givenName</th><td><input type="text" name="givenName" value="%s"></td></tr>
              <tr><th>sn</th><td><input type="text" name="sn" value="%s"></td></tr>
              <tr><th>invite mail</th><td><input type="text" name="mail" value="%s"></td></tr>
              <tr><th>personNIN</th><td><input type="text" name="personNIN" value="%s"></td></tr>%s',
      _('Remove Invite'),
      _('Do you want to remove the invite shown below ?'),
      htmlspecialchars($id),
      isset($inviteInfo->givenName) ? $inviteInfo->givenName : '',
      isset($inviteInfo->sn) ? $inviteInfo->sn : '',
      isset($inviteInfo->mail) ? $inviteInfo->mail : '',
      isset($inviteInfo->personNIN) ? $inviteInfo->personNIN : '',
      "\n");
  printf('            </tbody>
          </table>
          <div class="buttons">
            <a href="?action=listInvites&id=%s"><button class="btn btn-secondary">%s</button></a>
            <button type="submit" name="delete" class="btn btn-primary">%s</button>
          </div>
        </form>%s',
    htmlspecialchars($id), _('Cancel'),
    _('Delete'), "\n");
}

function multiInvite() {
  global $scim, $invites;
  $placeHolder = _('GivenName') . ';' . _('SurName') . ';' . _('Invite mail') . ';' . _('Swedish national identity number') . '/' . _('Birthdate') . ';sv/en';
  $attributes2Migrate = $scim->getAttributes2migrate();
  foreach ( $attributes2Migrate as $SCIM) {
    $placeHolder .= ';' . $SCIM;
  }

  printf('        <h2>%s</h2>
        <p>%s</p>
        <code>%s</code>
        <form id="create-invite-form" method="POST">
          <input type="hidden" name="action" value="addMultiInvite">
          <input type="checkbox" name="birthDate"%s> %s<br>
          <input type="checkbox" name="sendMail"%s> %s
          <textarea id="inviteData" name="inviteData" rows="4" cols="100" placeholder="%s">%s</textarea>
          <div class="buttons">
            <button type="submit" name="validateInvites" class="btn btn-primary">%s</button>
            <button type="submit" name="createInvites" class="btn btn-primary">%s</button>
          </div>
        </form>%s',
    _('Add multiple Invites'),
    _('Add users in the textfield according to template below, separate each attribute with a semicolon. When you are finished hit the button Validate Invites and then Create Invites when you have fixed all errors.'),
    $placeHolder,
    isset($_POST['birthDate']) ? HTML_CHECKED : '', _('Allow users without Swedish national identity number (requires Birthdate)'),
    isset($_POST['sendMail']) ? HTML_CHECKED : '', _('Send out invite mail'),
    $placeHolder, isset ($_POST['inviteData']) ? htmlspecialchars($_POST['inviteData']) : '',
    _('Validate Invites'), _('Create Invites'),
    "\n");
  if (isset($_POST['inviteData'])) {
    foreach (explode("\n", $_POST['inviteData']) as $line) {
      $params = explode(';', rtrim($line));
      $parseErrors = '';
      $fullInfo = '';
      $inviteArray = array();
      $attributeArray = array();

      if (isset($params[0]) && strlen($params[0]) > 1) {
        if (strlen($params[0])) {
          $fullInfo = htmlspecialchars($params[0]);
          $inviteArray['givenName'] = $params[0];
        } else {
          $parseErrors .= sprintf('%s %s. ', _('GivenName'), _('missing'));
        }
        if (isset($params[1]) && strlen($params[1])) {
          $fullInfo .= ' ' . htmlspecialchars($params[1]);
          $inviteArray['sn'] = $params[1];
        } else {
          $parseErrors .= sprintf('%s %s. ', _('SurName'), _('missing'));
        }
        if (isset($params[2]) && strlen($params[2])) {
          if ($invites->validateEmail($params[2])) {
            $fullInfo .= ' (' . htmlspecialchars($params[2]) . ')' ;
            $inviteArray['mail'] = $params[2];
          } else {
            $parseErrors .= sprintf('%s %s. ', _('Invite mail'), _('have wrong format'));
          }
        } else {
          $parseErrors .= sprintf('%s %s. ', _('Invite mail'), _('missing'));
        }
        if (isset($params[3]) && strlen($params[3])) {
          if ($invites->validateSSN($params[3], isset($_POST['birthDate']))) {
            $inviteArray['personNIN'] = $params[3];
          } else {
            $parseErrors .= sprintf('%s %s. ', _('Swedish national identity number'), _('have wrong format'));
          }
        } else {
          $parseErrors .= sprintf('%s %s. ', _('Swedish national identity number'), _('missing'));
        }
        if (isset($params[4]) && strlen($params[4])) {
          $lang = $params[4];
          if (! ($lang == 'sv' || $lang == 'en')) {
            $parseErrors .= sprintf('%s %s. ', _('Language'), _('should be sv or en'));
          }
        } else {
          $parseErrors .= sprintf('%s %s. ', _('Language'), _('missing'));
        }

        $paramCounter = 4;
        foreach ( $attributes2Migrate as $SCIM) {
          $paramCounter++;
          if (isset($params[$paramCounter]) && strlen($params[$paramCounter])) {
            switch ($SCIM) {
              case 'eduPersonPrincipalName' :
                $ePPN = $params[$paramCounter];
                if ($scim->ePPNexists($ePPN)) {
                  $parseErrors .= sprintf(_('%s already have an account.'), htmlspecialchars($ePPN));
                } elseif ($invites->ePPNexists($ePPN)) {
                  $parseErrors .= sprintf(_('%s already have an invite.'), htmlspecialchars($ePPN));
                }
                if (! $scim->validScope($ePPN)) {
                  $parseErrors .= sprintf(_('%s has an invalid scope.'), htmlspecialchars($ePPN));
                }
                $attributeArray['eduPersonPrincipalName'] = $ePPN;
                break;
              case 'eduPersonScopedAffiliation' :
                $ePSA = $params[$paramCounter];
                if (! $scim->validScope($ePSA)) {
                  $parseErrors .= sprintf(_('%s has an invalid scope.'), htmlspecialchars($ePSA));
                }
                $attributeArray['eduPersonScopedAffiliation'] = $scim->expandePSA(array($ePSA));
                break;
              case 'mail' :
                if ($invites->validateEmail($params[$paramCounter])) {
                  $attributeArray['mail'] = $params[$paramCounter];
                } else {
                  $parseErrors .= sprintf('%s %s. ', _('Organisation mail'), _('have wrong format'));
                }
                break;
              default :
                $attributeArray[$SCIM] = $params[$paramCounter];
            }
          } else {
            $parseErrors .= sprintf('%s %s %s. ', _('SAML value for'), $SCIM, _('missing'));
          }

        }

        if ($parseErrors == '') {
          if (isset($_POST['createInvites']) ){
            printf('          <div class="row"><i class="fas fa-check"></i> %s %s</div>%s', $fullInfo, _('Invited'), "\n");
            $invites->updateInviteAttributesById(0, $attributeArray, $inviteArray, $lang, isset($_POST['sendMail']));
          } else {
            printf('          <div class="row"><i class="fas fa-check"></i> %s OK</div>%s', $fullInfo, "\n");
          }
        } else {
          printf('          <div class="row alert-danger" role="alert"><i class="fas fa-exclamation"></i> %s : %s</div>%s', $fullInfo, $parseErrors, "\n");
        }
      }
    }
  }
  printf('        <div class="buttons"><a href="./?action=listInvites"><button class="btn btn-secondary">%s</button></a></div>%s', _('Back'), "\n");
}
