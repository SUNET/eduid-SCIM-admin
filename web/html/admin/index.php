<?php
const SCIM_NUTID_SCHEMA = 'https://scim.eduid.se/schema/nutid/user/v1';
const LI_ITEM = '                <li>%s - %s</li>%s';

require_once '../vendor/autoload.php';

$config = new scimAdmin\Configuration();

$html = new scimAdmin\HTML($config->mode(), _('Administration of your organisation identities.'));

$scim = new scimAdmin\SCIM();

$localize = new scimAdmin\Localize();

$errors = '';
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
    if ($subAssurance == 'http://www.swamid.se/policy/assurance/al3') {
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

if (isset($_POST['action'])) {
  switch ($_POST['action']) {
    case 'saveUser' :
      $id = isset($_POST['id']) ? $scim->validateID($_GET['id']) : false;
      if ( $scim->getAdminAccess() > 19 ) {
        if ($id) {
          saveUser($id);
        }
        showMenu();
        listUsers($id, true);
        listInvites();
      } else {
        showMenu();
        listUsers($id, true);;
      }
      break;
    case 'removeUser' :
      $id = isset($_POST['id']) ? $scim->validateID($_GET['id']) : false;
      if ( $scim->getAdminAccess() > 19 ) {
        if ($id) {
          $user = $scim->getId($id);
          $version = $user->meta->version;
          $scim->removeUser($id,$version);
        }
        showMenu();
        listUsers($id, true);
        listInvites();
      } else {
        showMenu();
        listUsers($id, true);;
      }
      break;
    case 'saveInvite' :
      $id = isset($_POST['id']) ? $invites->validateID($_POST['id']) : false;
      $parseErrors = '';
      if ( $scim->getAdminAccess() > 19 ) {
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
                $parseErrors .= sprintf(_('%s already have an account.'), $data) . '<br>';
              } elseif ($invites->ePPNexists($data)) {
                $parseErrors .= $invites->getInviteePPNid() == $id ? '' :
                  sprintf(_('%s already have an invite.'), $data) . '<br>';
              }
              if (! $scim->validScope($data)) {
                  $parseErrors .= sprintf(_('%s has an invalid scope.'), $data) . '<br>';
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
          showMenu(2);
          listUsers();
          listInvites($id, true);
        } else {
          $html->setExtraURLPart('&action=editInvite&id=' . $id);
          editInvite($id, $parseErrors);
        }
      } else {
        showMenu();
        listUsers('', true);
      }
      break;
    case 'deleteInvite' :
      $id = isset($_POST['id']) ? $invites->validateID($_POST['id']) : false;
      showMenu(2);
      if ( $scim->getAdminAccess() > 19 ) {
        if ($id) {
          $invites->removeInvite($id);
        }
        listUsers();
        listInvites($id, true);
      } else {
        listUsers('', true);
      }
      break;
    case 'addMultiInvite' :
      $html->setExtraURLPart('&action=addMultiInvite');
      if ( $scim->getAdminAccess() > 19) {
        multiInvite();
      } else {
        showMenu(2);
        listUsers('', true);
      }
      break;
    default:
      if ($scim->getAdminAccess() > 29) {
        printf('Missing what to do with action = %s in POST', $_POST['action']);
      }
  }
} elseif (isset($_GET['action'])) {
  switch ($_GET['action']) {
    case 'editUser' :
      $html->setExtraURLPart('&action=editUser');
      $id = isset($_GET['id']) ? $scim->validateID($_GET['id']) : false;
      if ( $scim->getAdminAccess() > 9 && $id) {
        editUser($id);
      } else {
        showMenu();
        listUsers($id, true);
        if ( $scim->getAdminAccess() > 19 ) {
          listInvites();
        }
      }
      break;
    case 'listUsers' :
      $html->setExtraURLPart('&action=listUsers');
      $id = isset($_GET['id']) ? $scim->validateID($_GET['id']) : false;
      showMenu();
      listUsers($id, true);
      if ( $scim->getAdminAccess() > 19 ) {
        listInvites();
      }
      break;
    case 'removeUser' :
      $id = isset($_GET['id']) ? $scim->validateID($_GET['id']) : false;
      if ( $scim->getAdminAccess() > 19 ) {
        if ($id) {
          removeUser($id);
        }
      } else {
        showMenu();
        listUsers($id, true);
      }
      break;
    case 'editInvite' :
      $id = isset($_GET['id']) ? $invites->validateID($_GET['id']) : false;
      if ( $scim->getAdminAccess() > 19 && $id) {
        $html->setExtraURLPart('&action=editInvite&id=' . $id);
        editInvite($id);
      } else {
        showMenu(2);
        if ( $scim->getAdminAccess() > 19 ) {
          listUsers();
          listInvites($id, true);
        } else {
          listUsers('', true);
        }
      }
      break;
    case 'resendInvite' :
      $id = isset($_GET['id']) ? $invites->validateID($_GET['id']) : false;
      if ( $scim->getAdminAccess() > 19 && $id) {
        $html->setExtraURLPart('&action=resendInvite&id=' . $id);
        resendInvite($id);
      } else {
        showMenu(2);
        if ( $scim->getAdminAccess() > 19 ) {
          listUsers();
          listInvites($id, true);
        } else {
          listUsers('', true);
        }
      }
      break;
    case 'listInvites' :
      $html->setExtraURLPart('&action=listInvites');
      $id = isset($_GET['id']) ? $invites->validateID($_GET['id']) : false;
      showMenu(2);
      if ( $scim->getAdminAccess() > 19 ) {
        listUsers();
        listInvites($id, true);
      } else {
        listUsers('', true);
      }
      break;
    case 'approveInvite' :
      $id = isset($_GET['id']) ? $invites->validateID($_GET['id']) : false;
      showMenu(2);
      if ( $scim->getAdminAccess() > 19 ) {
        if ($id) {
          $html->setExtraURLPart('&action=approveInvite&id=' . $id);
          approveInvite($id);
        }
        listUsers();
        listInvites($id, true);
      } else {
        listUsers('', true);
      }
      break;
    case 'addInvite' :
      $html->setExtraURLPart('&action=addInvite');
      if ( $scim->getAdminAccess() > 19) {
        editInvite(0);
      } else {
        showMenu(2);
        if ( $scim->getAdminAccess() > 19 ) {
          listUsers();
          listInvites($id, true);
        } else {
          listUsers('', true);
        }
      }
      break;
    case 'addMultiInvite' :
      $html->setExtraURLPart('&action=addMultiInvite');
      if ( $scim->getAdminAccess() > 19) {
        multiInvite();
      } else {
        showMenu(2);
        listUsers('', true);
      }
      break;
    case 'deleteInvite' :
      $id = isset($_GET['id']) ? $invites->validateID($_GET['id']) : false;
      if ( $scim->getAdminAccess() > 19) {
        $html->setExtraURLPart('&action=deleteInvite&id=' . $id);
        deleteInvite($id);
      } else {
        showMenu(2);
        if ( $scim->getAdminAccess() > 19 ) {
          listUsers();
          listInvites($id, true);
        } else {
          listUsers('', true);
        }
      }
      break;
    default:
      # listUsers
      showMenu();
      listUsers('', true);
      if ( $scim->getAdminAccess() > 19 ) {
        listInvites();
      }
      break;
  }
} else {
  showMenu();
  listUsers('', true);
  if ( $scim->getAdminAccess() > 19 ) {
    listInvites();
  }
}
print "        <br>\n";
$html->showFooter(true);

function listUsers($id='0-0', $shown = false) {
  global $scim;
  $users = $scim->getAllUsers();
  uasort($users, 'sortFullName');
  printf('        <table id="list-users-table" class="table table-striped table-bordered list-users"%s>
          <thead>
            <tr><th>ePPN</th><th>Name</th><th>eduID</tr>
          </thead>
          <tbody>%s', $shown ? '' : ' hidden', "\n");
  foreach ($users as $user) {
    showUser($user, $id);
  }
  printf('          <tbody>%s        </table>%s', "\n", "\n");
}
function sortFullName($a, $b) {
  if ($a['fullName'] == $b['fullName']) {
    return 0;
  }
  return ($a['fullName'] < $b['fullName']) ? -1 : 1;
}

function showUser($user, $id) {
  printf('            <tr class="collapsible" data-id="%s" onclick="showId(\'%s\')">
              <td>%s</td>
              <td>%s</td>
              <td>%s</td>
            </tr>
            <tr class="content" style="display: %s;">
              <td>
                <a a href="?action=editUser&id=%s"><button class="btn btn-primary btn-sm">%s</button></a><br>
                <a a href="?action=removeUser&id=%s"><button class="btn btn-primary btn-sm">%s</button></a>
              </td>
              <td colspan="3"><ul>%s',
    $user['externalId'], $user['externalId'],
    isset($user['attributes']->eduPersonPrincipalName) ? $user['attributes']->eduPersonPrincipalName : _('Missing'),
    $user['fullName'], $user['externalId'],
    $id == $user['id'] ? 'table-row' : 'none',
    $user['id'], _('Edit'),
    $user['id'], _('Delete'),
    "\n");
  if ($user['profile']) {
    foreach($user['attributes'] as $key => $value) {
      $value = is_array($value) ? implode(", ", $value) : $value;
      printf (LI_ITEM, $key, $value, "\n");
    }
  }
  printf('              </ul></td>%s            </tr>%s', "\n", "\n");
}

function editUser($id) {
  global $scim, $html;

  $html->setExtraURLPart('&action=editUser&id=' . $id);
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
            <button type="submit" class="btn btn-primary">%s</button>
          </div>
        </form>
        <div class="buttons">
          <a href="?action=listUsers&id=%s"><button class="btn btn-secondary">%s</button></a>
        </div>%s',
    _('Submit'), htmlspecialchars($id), _('Cancel'), "\n");
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
  printf('        <form method="POST">
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
            <button type="submit" class="btn btn-primary">%s</button>
          </div>
        </form>
        <div class="buttons">
          <a href="?action=listUsers&id=%s"><button class="btn btn-secondary">%s</button></a>
        </div>%s',
    $user->id,
    isset($user->name->formatted) ? $user->name->formatted : '',
    isset($user->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->eduPersonPrincipalName) ?
      $user->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->eduPersonPrincipalName : '',
    isset($user->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->mail) ?
      $user->{SCIM_NUTID_SCHEMA}->profiles->connectIdp->attributes->mail : '',
    _('Delete'), htmlspecialchars($id), _('Cancel'), "\n");
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
  global $scim, $result;
  printf ('        <label for="select">%s</label>
        <div class="select">
          <select id="selectList">
            <option value="List Users">%s</option>', _('Select a list'), _('Users'));
  if ( $scim->getAdminAccess() > 19 ) {
    printf('
            <option value="List invites"%s>%s</option>', $show == 2 ? ' selected' : '', _('Invites'));
  }
  print '
          </select>
        </div>' . "\n";
  printf('        <div class="result">%s</div>', $result);
  print "\n        <br>\n        <br>\n";
}

function listInvites($id = 0, $show = false) {
  global $invites;
  printf('        <table id="list-invites-table" class="table table-striped table-bordered list-invites"%s>
          <thead>
            <tr><th></th><th>%s</th><th>%s</th></tr>
          </thead>
          <tbody>
            <tr><td colspan="3">
              <a a href="?action=addInvite"><button class="btn btn-primary btn-sm">%s</button></a>
              <a a href="?action=addMultiInvite"><button class="btn btn-primary btn-sm">%s</button></a>
            </td></tr>%s',
    $show ? '' : ' hidden', _('Last modified'), _('Name'), _('Add Invite'), _('Add multiple Invites'), "\n");
  $oldStatus = 0;
  foreach ($invites->getInvitesList() as $invite) {
    if ($invite['status'] != $oldStatus) {
      printf('            <tr><td colspan="3"><b>%s</b></td></tr>%s',
        $invite['status'] == 1 ? _('Waiting for onboarding') : _('Waiting for approval'), "\n");
      $oldStatus = $invite['status'];
    }
    showInvite($invite, $id);
  }
  printf('          </tbody>%s       </table>%s', "\n", "\n");
}

function showInvite($invite, $id) {
  $inviteInfo = json_decode($invite['inviteInfo']);
  $migrateInfo = json_decode($invite['migrateInfo']);
  printf('            <tr class="collapsible" data-id="%s" onclick="showId(\'%s\')">
              <td></td>
              <td>%s</td>
              <td>%s</td>
            </tr>%s',
    $invite['id'], $invite['id'],
    $invite['modified'],
    $inviteInfo->givenName . ' ' . $inviteInfo->sn,
    "\n");
  if ($invite['status'] == 1) {
    printf('            <tr class="content" style="display: %s;">
                <td>
                  <a a href="?action=editInvite&id=%s">
                    <button class="btn btn-primary btn-sm">%s</button>
                  </a><br>
                  <a a href="?action=resendInvite&id=%s">
                    <button class="btn btn-primary btn-sm">%s</button>
                  </a><br>
                  <a a href="?action=deleteInvite&id=%s">
                    <button class="btn btn-primary btn-sm">%s</button>
                  </a>
                </td>
                <td>Attributes : <ul>%s',
        $id == $invite['id'] ? 'table-row' : 'none',
        $invite['id'], _('Edit'),
        $invite['id'], _('Resend'),
        $invite['id'], _('Delete'), "\n");
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
              <td>
                <a a href="?action=approveInvite&id=%s">
                  <button class="btn btn-primary btn-sm">%s</button>
                </a><br>
                <a a href="?action=editInvite&id=%s">
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
      $id == $invite['id'] ? 'table-row' : 'none',
      $invite['id'], _('Approve'),
      $invite['id'], _('Edit'),
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

  if ($id > 0) {
    $invite = $invites->getInvite($id);
  } else {
    $invite = array ('status' => 0, 'inviteInfo' => '{}', 'attributes' => '{}', 'lang' => '');
  }
  if ($invite['status'] == 2) {
    printf('        <div class="row alert alert-danger">%s</div>%s', _('You are editing an invite waiting for approval. If you submit it will be converted back to waiting for onboarding!'), "\n");
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
              <tr><th>%s</th><td><input type="text" name="givenName" value="%s"></td></tr>
              <tr><th>%s</th><td><input type="text" name="sn" value="%s"></td></tr>
              <tr><th>%s</th><td><input type="text" name="mail" value="%s"></td></tr>
              <tr><th>%s</th><td><input type="text" name="personNIN" value="%s"></td></tr>
              <tr><th>%s</th><td><select name="lang">
                <option value="sv">%s</option>
                <option value="en"%s>%s</option>
              </select></td></tr>%s',
      htmlspecialchars($id),
      _('Invite Info'),
      _('GivenName'), isset($inviteInfo->givenName) ? $inviteInfo->givenName : '',
      _('SurName'), isset($inviteInfo->sn) ? $inviteInfo->sn : '',
      _('Invite mail'), isset($inviteInfo->mail) ? $inviteInfo->mail : '',
      _('Swedish national identity number'), isset($inviteInfo->personNIN) ? $inviteInfo->personNIN : '',
      _('Language for invite'), _('Swedish'),
      $invite['lang'] == 'en' ? ' selected' : '', _('English'),
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
  printf('            </tbody>
          </table>
          <div class="buttons">
            <button type="submit" class="btn btn-primary">%s</button>
          </div>
        </form>
        <div class="buttons">
          <a href="?action=listInvites&id=%s"><button class="btn btn-secondary">%s</button></a>
        </div>%s',
    _('Submit'), htmlspecialchars($id), _('Cancel'), "\n");
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
  if ( $scim->getAdminAccess() > 19 ) {
    listUsers();
    listInvites($id, true);
  } else {
    listUsers('', true);
  }
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
  printf('            </tbody>
          </table>
          <div class="buttons">
            <button type="submit" class="btn btn-primary">%s</button>
          </div>
        </form>
        <div class="buttons">
          <a href="?action=listInvites&id=%s"><button class="btn btn-secondary">%s</button></a>
        </div>%s',
    _('Delete'), htmlspecialchars($id), _('Cancel'), "\n");
}

function multiInvite() {
  global $scim, $invites;
  $placeHolder = _('GivenName') . ';' . _('SurName') . ';' . _('Invite mail') . ';' . _('Swedish national identity number') . '/' . _('Birthdate') . ';sv/en';
  $attributes2Migrate = $scim->getAttributes2migrate();
  foreach ( $attributes2Migrate as $SCIM) {
    $placeHolder .= ';' . $SCIM;
  }

  printf('        <form id="create-invite-form" method="POST">
          <input type="hidden" name="action" value="addMultiInvite">
          <div class="buttons">
            <button type="submit" name="validateInvites" class="btn btn-primary">%s</button>
            <button type="submit" name="createInvites" class="btn btn-primary">%s</button>
          </div>
          <input type="checkbox" name="birthDate"%s> %s<br>
          <input type="checkbox" name="sendMail"%s> %s -
          %s <select name="lang">
            <option value="sv">%s</option>
            <option value="en"%s>%s</option>
          </select>
          <textarea id="inviteData" name="inviteData" rows="4" cols="100" placeholder="%s">%s</textarea>
        </form>
        <div class="buttons"><a href="./?action=listInvites"><button class="btn btn-secondary">%s</button></a></div>%s',
    _('Validate Invites'), _('Create Invites'),
    isset($_POST['birthDate']) ? ' checked' : '', _('Allow users without Swedish national identity number (requires Birthdate)'),
    isset($_POST['sendMail']) ? ' checked' : '', _('Send out invite mail'),
    _('Language for invite'), _('Swedish'),
    (isset($_POST['lang']) && $_POST['lang'] == 'en') ? ' selected' : '', _('English'),
    $placeHolder, isset ($_POST['inviteData']) ? $_POST['inviteData'] : '',
    _('Back'), "\n");
  if (isset($_POST['inviteData'])) {
    foreach (explode("\n", $_POST['inviteData']) as $line) {
      $params = explode(';', $line);
      $parseErrors = '';
      $fullInfo = '';
      $inviteArray = array();
      $attributeArray = array();

      if (isset($params[0]) && strlen($params[0])) {
        $fullInfo = $params[0];
        $inviteArray['givenName'] = $params[0];
      } else {
        $parseErrors .= sprintf('%s %s. ', _('GivenName'), _('missing'));
      }
      if (isset($params[1]) && strlen($params[1])) {
        $fullInfo .= ' ' . $params[1];
        $inviteArray['sn'] = $params[1];
      } else {
        $parseErrors .= sprintf('%s %s. ', _('SurName'), _('missing'));
      }
      if (isset($params[2]) && strlen($params[2])) {
        if ($invites->validateEmail($params[2])) {
          $fullInfo .= ' (' . $params[2] . ')' ;
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
                $parseErrors .= sprintf(_('%s already have an account.'), $ePPN);
              } elseif ($invites->ePPNexists($ePPN)) {
                $parseErrors .= sprintf(_('%s already have an invite.'), $ePPN);
              }
              if (! $scim->validScope($ePPN)) {
                $parseErrors .= sprintf(_('%s has an invalid scope.'), $ePPN);
              }
              $attributeArray['eduPersonPrincipalName'] = $ePPN;
              break;
            case 'eduPersonScopedAffiliation' :
              $ePSA = $params[$paramCounter];
              if (! $scim->validScope($ePSA)) {
                $parseErrors .= sprintf(_('%s has an invalid scope.'), $ePSA);
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
          printf('          <div class="row">%s %s</div>%s', $fullInfo, _('Invited'), "\n");
          $invites->updateInviteAttributesById(0, $attributeArray, $inviteArray, $_POST['lang'], isset($_POST['sendMail']));
        } else {
          printf('          <div class="row">%s OK</div>%s', $fullInfo, "\n");
        }
      } else {
        printf('          <div class="row alert-danger" role="alert">%s : %s</div>%s', $fullInfo, $parseErrors, "\n");
      }
    }
  }
}
