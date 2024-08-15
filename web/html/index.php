<?php
require_once 'vendor/autoload.php';

const HTML_LI_PROFILE = '            <li><a href="https://eduid.se/profile/">%s</a></li>%s';

$config = new scimAdmin\Configuration();

$html = new scimAdmin\HTML($config->mode());

if ($instance = $config->getInstance()) {
  $scim = new scimAdmin\SCIM();

  $invites = new scimAdmin\Invites();

  $localize = new scimAdmin\Localize();

  if (isset($_GET['action'])) {
    switch ($_GET['action']) {
      case 'startMigrate' :
        $html->setExtraURLPart('&action=startMigrate');
        $invites->startMigrateFromSourceIdP();
        break;
      case 'activateAccount' :
        $html->setExtraURLPart('&action=activateAccount');
        $invites->redirectToNewIdP('/migrate?backend', $config->forceMFA());
        break;
      case 'showInviteFlow' :
        $html->setExtraURLPart('&action=showInviteFlow');
        showInviteFlow();
        break;
      case 'showMigrateFlow' :
        $html->setExtraURLPart('&action=showMigrateFlow');
        showMigrateFlow();
        break;
      case 'migrateSuccess' :
        $html->setExtraURLPart('&action=migrateSuccess');
        showSuccess();
        break;
      default :
        if ( $instance['sourceIdP'] == '') {
          $html->setExtraURLPart('&action=showInviteFlow');
          showInviteFlow();
        } else {
          showStartPage();
        }
    }
  } else {
    if ( $instance['sourceIdP'] == '') {
      $html->setExtraURLPart('&action=showInviteFlow');
      showInviteFlow();
    } else {
      showStartPage();
    }
  }
} else {
  showMissingInstance();
}

function showInviteFlow() {
  global $html, $invites, $config;

  if (isset($_POST['code'])) {
    $sessionID = $_COOKIE['PHPSESSID'];
    if ($invites->updateInviteByCode($sessionID,$_POST['code']) ) {
      showMigrateFlow();
      exit;
    } else {
      $error = _('Wrong code');
    }
  } else {
    $error = '';
  }
  $html->showHeaders(_('Connect - Activate'));
  printf('        <div class="numberList">%s
          <ol>
            <li><a href="https://eduid.se/register">%s</a></li>
            <li><a href="https://eduid.se/profile/">%s</a></li>%s',
    _('To be able to make this connection, you need to have done the following:'),
    _('Create a personal identity on eduID.'),
    _('Verify your identity in eduID.'),
    "\n");
    if ($config->forceMFA()) {
      printf(HTML_LI_PROFILE,
        _('Add a security key to eduID for safer login.'), "\n");
    }
    printf('          </ol>
          <br>
          %s
        </div>
        <br>
          %s',
      _('When you have a personal identity in eduID, proceed by entering the one-time code and click the button.'),
      "\n");
  if ($error) {
    print $error;
  }
  printf('        <!-- input and submit button -->
        <form method="POST">
          <div class="invite-code-container">
            <label for="invite-code">%s </label>
          </div>
          <input type="text" name="code" />
          <div class="buttons">
            <button type="submit" class="btn btn-primary">%s</button>
          </div>
        </form>
        <!-- input and submit button -->%s',
    _('Invite code'), _('Next'), "\n");
  $html->showFooter(false);
}

function showMigrateFlow() {
  global $html, $invites, $config;
  $sessionID = $_COOKIE['PHPSESSID'];

  $html->showHeaders(_('Connect - Activate'));
  $invite = $invites->checkInviteBySession($sessionID);

  if ($invite && $invite['status'] == 1) {
    printf('        <p>%s<br><br>%s<ul>%s',
      _('You\'ve started the activation of your organisation identity in eduID.'),
      _('The following data will be added to your account:'), "\n");
    foreach (json_decode($invite['attributes']) as $SCIM => $attribute) {
      $attribute = is_array($attribute) ? implode(', ', $attribute) : $attribute;
      printf('          <li>%s - %s</li>%s', $SCIM, $attribute, "\n");
    }
    printf('    </ul></p>%s', "\n");
    printf('        <div class="buttons">
    <a class="btn btn-primary" href="?action=activateAccount">%s</a>%s  </div>%s', _('Activate account'), "\n", "\n");
  } else {
    printf('        <div class="numberList">%s
          <ol>
            <li><a href="https://eduid.se/register">%s</a></li>
            <li><a href="https://eduid.se/profile/">%s</a></li>%s',
    _('To be able to make this connection, you need to have done the following:'),
    _('Create a personal identity on eduID.'),
    _('Verify your identity in eduID.'),
    "\n");
    if ($config->forceMFA()) {
      printf(HTML_LI_PROFILE,
        _('Add a security key to eduID for safer login.'), "\n");
    }
    printf('          </ol>
          <br>
          %s
        </div>
        <div class="buttons">
          <a class="btn btn-primary" href="?action=startMigrate">%s</a>
        </div>%s',
      _('When you have a personal identity in eduID, proceed by click the button.'),
      _('Start onboarding with organisational login'),
      "\n");
  }
  $html->showFooter(false);
}

function showStartPage() {
  global $html, $config;
  $html->showHeaders(_('Connect - Onboard'));
  print "        <div>";
  printf(_('%s uses eduID for logging in to national and international web services. To be able to log in to these, you need to connect your personal eduID identity to your organisation. You can do this by following the instructions below.'), $config->getOrgName());
  printf('</div>
        <br>
        <div class="numberList">%s
          <ol>
            <li><a href="https://eduid.se/register">%s</a></li>
            <li><a href="https://eduid.se/profile/">%s</a></li>%s',
    _('To be able to make this connection, you need to have done the following:'),
    _('Create a personal identity on eduID.'),
    _('Verify your identity in eduID.'),
    "\n");
  if ($config->forceMFA()) {
    printf(HTML_LI_PROFILE,
      _('Add a security key to eduID for safer login.'), "\n");
  }
  printf('          </ol>
          <br>
          %s
        </div>
        <div class="buttons"><a class="btn btn-primary" href="?action=showInviteFlow">%s</a></div>
        <div class="buttons"><a class="btn btn-primary" href="?action=showMigrateFlow">%s</a></div>%s',
    _('When you have completed the above steps, proceed and click one of the buttons below. If you have received an invitation code via email from an administrator, use that option; otherwise, use login via the organisation\’s old login service.'),
    _('Onboard with Invite code'),
    _("Onboard with organisational login"),
    "\n");
  $html->showFooter(false);
}

function showSuccess() {
  global $html;
  $html->showHeaders(_('Connect - Onboard'));
  print _('You are now onboard :-)');
  print '<br>';
  $html->showFooter(array(),false);
}

function showMissingInstance() {
  global $html, $config;
  $html->showHeaders(_('Connect - Missing instance'));
  printf('         ' . _('instance[\'%s\'] is missing in config-file. Contact admin') . "<br>\n", $config->getScope());
  $html->showFooter(array(),false);
}
