<?php
$baseDir = dirname($_SERVER['SCRIPT_FILENAME'], 1);
include $baseDir . '/config.php';

include $baseDir . '/include/Html.php';
$html = new HTML($Mode);

include $baseDir . '/include/SCIM.php';
$scim = new SCIM($baseDir);

include $baseDir . '/include/Invites.php';
$invites = new Invites($baseDir);

session_start();

if (isset($_GET['action'])) {
	switch ($_GET['action']) {
		case 'startMigrate' :
			$invites->startMigrateFromSourceIdP();
			break;
		case 'finalizeMigrate' :
			$invites->finalizeMigrateToNewIdP();
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
    <form method="POST">
      <input type="text" name="code">
      <input type="submit">
    </form>
<?php	$html->showFooter(array(),false);
}

function showMigrateFlow() {
	global $html, $invites;
	$sessionID = $_COOKIE['PHPSESSID'];

	$html->showHeaders('SCIM migrate');
	$invite = $invites->checkInviteBySession($sessionID);

	if ($invite) {
		printf('    <p>You have stared migration.<br>Attribues to migrate : <ul>%s', "\n");
		foreach (json_decode($invite['attributes']) as $SCIM => $attribute) {
			$attribute = is_array($attribute) ? implode(', ', $attribute) : $attribute;
			printf('          <li>%s - %s</li>%s', $SCIM, $attribute, "\n");
		}
		printf('    </ul></p>%s', "\n");
		printf('<a href="?action=finalizeMigrate"><button type="button" class="btn btn-primary">Finalize migration to new IdP</button></a><br>%s', "\n");
	} else {
		printf('<a href="?action=startMigrate"><button type="button" class="btn btn-primary">Start new migration from old IdP</button></a><br>%s', "\n");
	}
	print '<br>';
	$html->showFooter(array(),false);
}

function showStartPage() {
	global $html;
	$html->showHeaders('SCIM migrate');
	printf('<a href="?action=showMigrateFlow"><button type="button" class="btn btn-primary">Migrate from Old IdP</button></a><br>%s', "\n");
	print '<br>';
	printf('<a href="?action=showInviteFlow"><button type="button" class="btn btn-primary">Onboard with Invite-code</button></a><br>%s', "\n");
	print '<br>';
	$html->showFooter(array(),false);
}

function showSuccess() {
	global $html;
	$html->showHeaders('SCIM migrate');
	print 'You are now onborded :-)';
	print '<br>';
	$html->showFooter(array(),false);
}