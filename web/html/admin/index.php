<?php
$baseDir = dirname($_SERVER['SCRIPT_FILENAME'], 2);
include $baseDir . '/config.php';

include $baseDir . '/include/Html.php';
$html = new HTML($Mode);

include $baseDir . '/include/SCIM.php';
$scim = new SCIM($baseDir);

include $baseDir .'/include/Invites.php';
$invites = new Invites($baseDir);

$errors = '';
$errorURL = isset($_SERVER['Meta-errorURL']) ? '<a href="' . $_SERVER['Meta-errorURL'] . '">Mer information</a><br>' : '<br>';
$errorURL = str_replace(array('ERRORURL_TS', 'ERRORURL_RP', 'ERRORURL_TID'), array(time(), 'https://'. $_SERVER['SERVER_NAME'] . '/shibboleth', $_SERVER['Shib-Session-ID']), $errorURL);

if (isset($_SERVER['Meta-Assurance-Certification'])) {
	$AssuranceCertificationFound = false;
	foreach (explode(';',$_SERVER['Meta-Assurance-Certification']) as $AssuranceCertification) {
		if ($AssuranceCertification == 'http://www.swamid.se/policy/assurance/al1')
			$AssuranceCertificationFound = true;
	}
	if (! $AssuranceCertificationFound) {
		$errors .= sprintf('%s has no AssuranceCertification (http://www.swamid.se/policy/assurance/al1) ', $_SERVER['Shib-Identity-Provider']);
	}
}

if (isset($_SERVER['eduPersonPrincipalName'])) {
	$AdminUser = $_SERVER['eduPersonPrincipalName'];
} elseif (isset($_SERVER['subject-id'])) {
	$AdminUser = $_SERVER['subject-id'];
} else {
	$errors .= 'Missing eduPersonPrincipalName in SAML response ' . str_replace(array('ERRORURL_CODE', 'ERRORURL_CTX'), array('IDENTIFICATION_FAILURE', 'eduPersonPrincipalName'), $errorURL);
}

if (! $scim->checkAccess($AdminUser)) {
	$errors .= $AdminUser . ' is not allowed to login to this page';
}

if ( isset($_SERVER['mail'])) {
	$mailArray = explode(';',$_SERVER['mail']);
	$mail = $mailArray[0];
} else {
	$errors .= 'Missing mail in SAML response ' . str_replace(array('ERRORURL_CODE', 'ERRORURL_CTX'), array('IDENTIFICATION_FAILURE', 'mail'), $errorURL);
}

if (isset($_SERVER['displayName'])) {
	$fullName = $_SERVER['displayName'];
} elseif (isset($_SERVER['givenName'])) {
	$fullName = $_SERVER['givenName'];
	if(isset($_SERVER['sn']))
		$fullName .= ' ' .$_SERVER['sn'];
} else
	$fullName = '';


	if ($errors != '') {
	$html->showHeaders('Metadata SWAMID - Problem');
	printf('%s    <div class="row alert alert-danger" role="alert">%s      <div class="col">%s        <b>Errors:</b><br>%s        %s%s      </div>%s    </div>%s', "\n", "\n", "\n", "\n", str_ireplace("\n", "<br>", $errors), "\n", "\n","\n");
	printf('    <div class="row alert alert-info" role="info">%s      <div class="col">%s        Logged into wrong IdP ?<br> You are trying with <b>%s</b>.<br>Click <a href="%s">here</a> to logout.%s      </div>%s    </div>%s', "\n", "\n", $_SERVER['Shib-Identity-Provider'], 'https://'. $_SERVER['SERVER_NAME'] . '/Shibboleth.sso/Logout', "\n", "\n", "\n");
	$html->showFooter(array());
	exit;
}

$displayName = '<div> Logged in as : <br> ' . $fullName . ' (' . $AdminUser .')</div>';
$html->setDisplayName($displayName);
$html->showHeaders('SCIM Admin');

$id = isset($_GET['id']) ? $_GET['id'] : false;
if (isset($_POST['action'])) {
	switch ($_POST['action']) {
		case 'saveId' :
			saveId($id);
			$menuActive = 'showId';
			showMenu($id);
			showId($id);
			break;
	}
} elseif (isset($_GET['action'])) {
	switch ($_GET['action']) {
		case 'listUsers' :
			$menuActive = 'listUsers';
			showMenu($id);
			listUsers();
			break;
		case 'showId' :
			$menuActive = 'showId';
			showMenu($id);
			if ($id) showId($_GET['id']);
			break;
		case 'editId' :
			if ( $scim->getAdminAccess() > 9 ) {
				$menuActive = 'editId';
				showMenu($id);
				if ($id) showId($_GET['id'], true);
								
			}
			break;
		case 'listInvites' :
			if ( $scim->getAdminAccess() > 19 ) {
				$menuActive = 'listInvites';
				showMenu($id);
				listInvites();
			}
	}
} else {
	$menuActive = 'listUsers';
	showMenu();
	listUsers();
}
print "    <br>\n";
$html->showFooter(array(),true);

function listUsers() {
	global $scim;
	$users = $scim->getAllUsers();
	printf('    <table id="entities-table" class="table table-striped table-bordered">%s', "\n");
	printf('      <thead><tr><th>externalId</th><th>Profile</th><th>Linked account</th></tr></thead>%s', "\n");
	printf('      <tbody>%s', "\n");
	foreach ($users as $user) {
		printf('        <tr><td><a href="?action=showId&id=%s">%s</td><td>%s</td><td>%s</td></tr>%s', $user['id'], $user['externalId'], $user['profiles'] ? 'X' : '', $user['linked_accounts'] ? 'X' : '', "\n");
	}
	printf('      <tbody>%s    </table>%s', "\n", "\n");
}

function showId($id, $edit=false) {
	global $scim;

	if ( $edit ) {
		# Set up a list of allowd/expected attributes to be able to show unused atribute in edit-form
		$samlAttributes = array();
		foreach ($scim->getAttibutes2migrate() as $saml => $SCIM) {
			$samlAttributes[$saml] =false;
		}
	}
	$user = $scim->getId($id);
	$userArray = (json_decode($user));
	if ($edit) {
		printf('    <form method="POST"><input type="hidden" name="action" value="saveId"><input type="hidden" name="id" value="%s">', $id);
	} else {
		print "    ";
	}
	printf('<table id="entities-table" class="table table-striped table-bordered">%s', "\n");
	printf('      <tbody>%s', "\n");
	printf('        <tr><th>Id</th><td>%s</td></tr>%s', $id, "\n");
	printf('        <tr><th>externalId</th><td>%s</td></tr>%s', $userArray->externalId, "\n");
	printf('        <tr><th colspan="2">SAML Attributes</th></tr>%s', "\n");
	if (isset($userArray->{'https://scim.eduid.se/schema/nutid/user/v1'}->profiles->connectIdp)) {
		foreach($userArray->{'https://scim.eduid.se/schema/nutid/user/v1'}->profiles->connectIdp->attributes as $key => $value) {
			if ( $edit ) {
				if ($key == 'eduPersonScopedAffiliation') {
					showEduPersonScopedAffiliationInput($value, $scim->getAllowedScopes(), $scim->getPossibleAffiliations());
				} else {
					$value = is_array($value) ? implode(", ", $value) : $value;
					printf ('        <tr><th>%s</th><td><input type="text" name="saml[%s]" value="%s"></td></tr>%s', $key, $key, $value, "\n");
				}
				$samlAttributes[$key] = true;
			} else {
				$value = is_array($value) ? implode(", ", $value) : $value;
				printf ('        <tr><th>%s</th><td>%s</td></tr>%s', $key, $value, "\n");
			}
		}
	}
	if ($edit) {
		foreach ($samlAttributes as $attribute => $found) {
			if (! $found) {
				if ($attribute == 'eduPersonScopedAffiliation') {
					showEduPersonScopedAffiliationInput(array(), $scim->getAllowedScopes(), $scim->getPossibleAffiliations());
				} else {
					printf('        <tr><th>%s</th><td><input type="text" name="saml[%s]" value=""></td></tr>%s', $attribute, $attribute, "\n");
				}
			}
		}
		printf('      </tbody>%s    </table>%s    <input type="submit">%s    </form>%s', "\n", "\n", "\n", "\n");
	} else {
		printf('      </tbody>%s    </table>%s', "\n", "\n");
	}
	print "<pre>";
	print_r($userArray);
	#print_r($userArray->{'https://scim.eduid.se/schema/nutid/user/v1'}->profiles->connectIdp);
	print "</pre>";
}

function saveId($id) {
	if (isset($_POST['saml'])) {
		global $scim;
		$user = $scim->getId($id);
		$userArray = (json_decode($user));
		
		$version = $userArray->meta->version;
		unset($userArray->meta);
		
		$schemaNutidFound = false;
		foreach ($userArray->schemas as $schema) {
			$schemaNutidFound = $schema == 'https://scim.eduid.se/schema/nutid/user/v1' ? true : $schemaNutidFound;
		}
		if (! $schemaNutidFound) $userArray->schemas[] = 'https://scim.eduid.se/schema/nutid/user/v1';

		if (! isset($userArray->{'https://scim.eduid.se/schema/nutid/user/v1'})) {
			$userArray->{'https://scim.eduid.se/schema/nutid/user/v1'} = new \stdClass();
		}
		if (! isset($userArray->{'https://scim.eduid.se/schema/nutid/user/v1'}->profiles)) {
			$userArray->{'https://scim.eduid.se/schema/nutid/user/v1'}->profiles = new \stdClass();
		}
		if (! isset($userArray->{'https://scim.eduid.se/schema/nutid/user/v1'}->profiles->connectIdps)) {
			$userArray->{'https://scim.eduid.se/schema/nutid/user/v1'}->profiles->connectIdp = new \stdClass();
		}
		if (! isset($userArray->{'https://scim.eduid.se/schema/nutid/user/v1'}->profiles->connectIdp->attributes)) {
			$userArray->{'https://scim.eduid.se/schema/nutid/user/v1'}->profiles->connectIdp->attributes = new \stdClass();
		}
		
		foreach ($_POST['saml'] as $key => $value) {
			$value = $key == 'eduPersonScopedAffiliation' ? parseEduPersonScopedAffiliation($value, $scim->getAllowedScopes(), $scim->getPossibleAffiliations()) : $value;
			if ($value == '') {
				if (isset($userArray->{'https://scim.eduid.se/schema/nutid/user/v1'}->profiles->connectIdp->attributes->$key)) {
					unset($userArray->{'https://scim.eduid.se/schema/nutid/user/v1'}->profiles->connectIdp->attributes->$key);
				}
			} else {
				$userArray->{'https://scim.eduid.se/schema/nutid/user/v1'}->profiles->connectIdp->attributes->$key = $value;
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
			printf ('          <input type="checkbox"%s name="saml[eduPersonScopedAffiliation][%s]"> %s<br>%s', $existingAffiliation[$scope][$affiliation] ? ' checked' : '', $affiliation . '@' . $scope, $affiliation, "\n");
		}
	}
	printf ('        </td></tr>%s', "\n");
}

function parseEduPersonScopedAffiliation($value, $allowedScopes, $possibleAffiliations) {
	$returnArray = array();
	foreach ($value as $affiliation => $on) {
		$returnArray[] = $affiliation;
	}
	
	do {
		$added = false;
		foreach ($returnArray as $affiliation) {
			$affiliationArray = explode('@', $affiliation);
			$checkedAffiliation = $affiliationArray[0];
			$checkedScope = '@' . $affiliationArray[1];
			if ($possibleAffiliations[$checkedAffiliation] <> '') {
				# Check dependencies
				if (! in_array($possibleAffiliations[$checkedAffiliation].$checkedScope, $returnArray)) {
					# Add dependent affilaiation
					$added = true;
					$returnArray[] = $possibleAffiliations[$checkedAffiliation].$checkedScope;
				}
			}
		}
	} while ($added);
	return $returnArray;
}

function showMenu($id = '') {
	global $scim, $menuActive;
	$filter = $id ? '&id=' . $id : '';
	
	print "\n    ";
	printf('<a href="?action=listUsers%s"><button type="button" class="btn btn%s-primary">List Users</button></a>', $filter, $menuActive == 'listUsers' ? '' : '-outline');
	if ($menuActive == 'showId' || $menuActive == 'editId') {
		printf('<a href="?action=showId%s"><button type="button" class="btn btn%s-primary">Show User</button></a>', $filter, $menuActive == 'showId' ? '' : '-outline');
		if ( $scim->getAdminAccess() > 9 ) {
			printf('<a href="?action=editId%s"><button type="button" class="btn btn%s-primary">Edit User</button></a>', $filter, $menuActive == 'editId' ? '' : '-outline');
		}
	}
	if ( $scim->getAdminAccess() > 19 ) {
		printf('<a href="?action=listInvites%s"><button type="button" class="btn btn%s-primary">List invites</button></a>', $filter, $menuActive == 'listInvites' ? '' : '-outline');
	}
	print "\n    <br>\n    <br>\n";
}

function listInvites () {
	global $invites;
	printf('    <table id="entities-table" class="table table-striped table-bordered">%s', "\n");
	printf('      <thead>%s', "\n");
	printf('        <tr><th>Invited</th><th>Active</th><th>Last modified</th><th>Values</th></tr>%s', "\n");

	printf('      </thead>%s', "\n");
	printf('      <tbody>%s', "\n");
	foreach ($invites->getInvitesList() as $invite) {
		printf('        <tr><td>%s</td><td>%s</td><td>%s</td><td><ul>', $invite['hash'] == '' ? '' : 'X', $invite['session'] == '' ? '' : 'X', $invite['modified']);
		foreach (json_decode($invite['attributes']) as $SCIM => $attribute) {
			$attribute = is_array($attribute) ? implode(", ", $attribute) : $attribute;
			printf('<li>%s - %s</li>', $SCIM, $attribute);
		}
		printf('</ul></td></tr>%s', "\n");
	}
	printf('      </tbody>%s    </table>%s', "\n", "\n");	
}
