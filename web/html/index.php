<?php
//Load composer's autoloader
require_once 'vendor/autoload.php';

$baseDir = dirname($_SERVER['SCRIPT_FILENAME'], 1);
include_once $baseDir . '/config.php'; # NOSONAR

$html = new scimAdmin\HTML($Mode);

$scim = new scimAdmin\SCIM($baseDir);

$invites = new scimAdmin\Invites($baseDir);

$localize = new scimAdmin\Localize();

if (isset($_GET['action'])) {
  switch ($_GET['action']) {
    case 'startMigrate' :
      $invites->startMigrateFromSourceIdP();
      break;
    case 'finalizeMigrate' :
      $invites->redirectToNewIdP('/migrate?backend');
      break;
    case 'showInviteFlow' :
      showInviteFlow();
      break;
    case 'showMigrateFlow' :
      showMigrateFlow();
      break;
    case 'migrateSuccess' :
      showSuccess();
      break;
    default :
      showStartPage();
  }
} else {
  showStartPage();
}

function showInviteFlow() {
  global $html, $invites;

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
  $html->showHeaders(_('SCIM migrate'));
  print '<div class="numberList">';
  print _('To be able to make this connection, you need to have done the following:') . '<ol type="1">' . "\n";
  print '<li><a href="https://eduid.se/register">' . _('Create a personal identity on eduID.') . "</a></li>\n";
  print '<li><a href="https://eduid.se/profile/">' . _('Verify your identity in eduID.') . "</a></li>\n";
  print '<li><a href="https://eduid.se/profile/">' . _('Add a security key to eduID for safer login.') . "</a></li>\n";
  print "</ol><br>\n";
  print _('When you have a personal identity in eduID, proceed by entering the one-time code and click the button.');
  print "</div><br>";
  if ($error) {
    print $error;
  }
    ?>
      <!-- input and submit button -->
        <form method="POST">
          <div class="invite-code-container">
            <label for="invite-code"><?=_('Invite code')?> </label>
          </div>
          <input type="text" name="code" />
          <div class="buttons">
            <button type="submit" class="btn btn-primary"><?=_('Next')?></button>
          </div>
        </form>
      <!-- input and submit button -->
<?php $html->showFooter(false);
}

function showMigrateFlow() {
  global $html, $invites;
  $sessionID = $_COOKIE['PHPSESSID'];

  $html->showHeaders(_('Connect - Migrate'));
  $invite = $invites->checkInviteBySession($sessionID);

  if ($invite && $invite['status'] == 1) {
    printf('        <p>%s<br><br>%s<ul>%s',
      _('You\'ve started the migration from the old login service to the eduID organisation login service.'),
      _('The following data will be migrated:'), "\n");
    foreach (json_decode($invite['attributes']) as $SCIM => $attribute) {
      $attribute = is_array($attribute) ? implode(', ', $attribute) : $attribute;
      printf('          <li>%s - %s</li>%s', $SCIM, $attribute, "\n");
    }
    printf('    </ul></p>%s', "\n");
    printf('        <div class="buttons">
    <a class="btn btn-primary" href="?action=finalizeMigrate">%s</a>%s  </div>%s', _('Finalize migration'), "\n", "\n");
  } else {
    print '<div class="numberList">';
    print _('To be able to make this connection, you need to have done the following:') . '<ol type="1">' . "\n";
    print '<li><a href="https://eduid.se/register">' . _('Create a personal identity on eduID.') . "</a></li>\n";
    print '<li><a href="https://eduid.se/profile/">' . _('Verify your identity in eduID.') . "</a></li>\n";
    print '<li><a href="https://eduid.se/profile/">' . _('Add a security key to eduID for safer login.') . "</a></li>\n";
    print "</ol><br>\n";
    print _('When you have a personal identity in eduID, proceed by click the button.');
    print "</div>";
    printf('        <div class="buttons">
    <a class="btn btn-primary" href="?action=startMigrate">%s</a>%s  </div>%s', _('Start new migration from old IdP'), "\n", "\n");
  }
  $html->showFooter(false);
}

function showStartPage() {
  global $html;
  $html->showHeaders(_('Connect - Onboard'));
  print "<div>";
  printf (_('%s uses eduID for logging in to national and international web services. To be able to log in to these, you need to connect your personal eduID identity to your organisation. You can do this by following the instructions below.'), 'Sunet');
  print '</div><br>
  <div class="numberList">' . "\n";
  print _('To be able to make this connection, you need to have done the following:') . '<ol type="1">' . "\n";
  print '<li><a href="https://eduid.se/register">' . _('Create a personal identity on eduID.') . "</a></li>\n";
  print '<li><a href="https://eduid.se/profile/">' . _('Verify your identity in eduID.') . "</a></li>\n";
  print '<li><a href="https://eduid.se/profile/">' . _('Add a security key to eduID for safer login.') . "</a></li>\n";
  print "</ol><br>\n";
  print _('When you have completed the above steps, proceed and click one of the buttons below. If you have received an invitation code via email from an administrator, use that option; otherwise, use login via the organisation\’s old login service.');
  print "</div>";
  printf('        <div class="buttons">
    <a class="btn btn-primary" href="?action=showMigrateFlow">%s</a>%s  </div>%s', _("Migrate from Old IdP"), "\n", "\n");
  printf('        <div class="buttons">
    <a class="btn btn-primary" href="?action=showInviteFlow">%s</a>%s  </div>%s', _('Onboard with Invite-code'), "\n", "\n");
  $html->showFooter(false);
}

function showSuccess() {
  global $html;
  $html->showHeaders(_('Connect - Onboarded'));
  print _('You are now onborded :-)');
  print '<br>';
  $html->showFooter(array(),false);
}
