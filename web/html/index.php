<?php
require_once './autoload.php';

$baseDir = dirname($_SERVER['SCRIPT_FILENAME'], 1);
include_once $baseDir . '/config.php'; # NOSONAR

$html = new scimAdmin\HTML($Mode);

$scim = new scimAdmin\SCIM($baseDir);

$invites = new scimAdmin\Invites($baseDir);

session_start();

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
      $error = 'Wrong code';
    }
  } else {
    $error = '';
  }
  $html->showHeaders('SCIM migrate');
  if ($error) {
    print $error;
  }
    ?>
      <!-- input and submit button -->
        <form method="POST">
          <div class="invite-code-container">
            <label for="invite-code">Invite code </label>
          </div>
          <input type="text" name="code" />
          <div class="buttons">
            <button type="submit" class="btn btn-primary">SUBMIT</button>
          </div>
        </form>
      <!-- input and submit button -->
<?php $html->showFooter(false);
}

function showMigrateFlow() {
  global $html, $invites;
  $sessionID = $_COOKIE['PHPSESSID'];

  $html->showHeaders('Connect - Migrate');
  $invite = $invites->checkInviteBySession($sessionID);

  if ($invite) {
    printf('        <p>You have stared migration.<br>Attribues to migrate : <ul>%s', "\n");
    foreach (json_decode($invite['attributes']) as $SCIM => $attribute) {
      $attribute = is_array($attribute) ? implode(', ', $attribute) : $attribute;
      printf('          <li>%s - %s</li>%s', $SCIM, $attribute, "\n");
    }
    printf('    </ul></p>%s', "\n");
    printf('        <a href="?action=finalizeMigrate">
      <button type="button" class="btn btn-primary">Finalize migration</button>%s        </a><br>%s',
      "\n", "\n");
  } else {
    printf('        <a href="?action=startMigrate">
      <button type="button" class="btn btn-primary">Start new migration from old IdP</button>%s        </a><br>%s',
      "\n", "\n");
  }
  $html->showFooter(false);
}

function showStartPage() {
  global $html;
  $html->showHeaders('Connect - Onboard');
  printf('        <div class="buttons">
    <a class="btn btn-primary" href="?action=showMigrateFlow">Migrate from Old IdP</a>%s  </div>%s', "\n", "\n");
  printf('        <div class="buttons">
    <a class="btn btn-primary" href="?action=showInviteFlow">Onboard with Invite-code</a>%s  </div>%s',"\n", "\n");
  $html->showFooter(false);
}

function showSuccess() {
  global $html;
  $html->showHeaders('Connect - Onboarded');
  print 'You are now onborded :-)';
  print '<br>';
  $html->showFooter(array(),false);
}
