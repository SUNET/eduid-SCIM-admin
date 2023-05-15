<?php
$baseDir = dirname($_SERVER['SCRIPT_FILENAME'], 2);
include $baseDir . '/config.php';

include $baseDir . '/include/Html.php';
$html = new HTML($Mode);

include $baseDir . '/include/SCIM.php';
$scim = new SCIM($baseDir);

include $baseDir . '/include/Invites.php';
$invites = new Invites($baseDir);

session_start();
$sessionID = $_COOKIE['PHPSESSID'];

if (isset($_GET['source'])) {
	if ($data = $invites->checkSourceData()) {
		$invites->updateInviteAttributes($sessionID, $data);
		$hostURL = "http".(!empty($_SERVER['HTTPS'])?"s":"")."://".$_SERVER['SERVER_NAME'];
		$redirectURL = $hostURL . '/' . $invites->getInstance() . '/?action=showMigrateFlow';
		header('Location: ' . $redirectURL);
	} else {
		print "Error while migrating";
	}
} elseif (isset($_GET['backend'])) {
	if ($EPPN = $invites->checkBackendData()) {
		if (! $id = $scim->getIdFromExternalId($EPPN)) {
			if (! $id = $scim->createIdFromExternalId($EPPN)) {
				print "Could not create user in SCIM";
				exit;
			}
		}

		$attributes = json_decode($invites->getInviteAttributes($sessionID));

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

		foreach ($attributes as $key => $value) {
			$userArray->{'https://scim.eduid.se/schema/nutid/user/v1'}->profiles->connectIdp->attributes->$key = $value;
		}

		if ($scim->updateId($id,json_encode($userArray),$version)) {
			$invites->removeInvite($sessionID);
			$hostURL = "http".(!empty($_SERVER['HTTPS'])?"s":"")."://".$_SERVER['SERVER_NAME'];
			$redirectURL = $hostURL . '/' . $invites->getInstance() . '/?action=migrateSuccess';
			header('Location: ' . $redirectURL);
		} else {
			print "Error while migrating (Could not update SCIM)";
		}
	} else {
		print "Error while migrating (Got no ePPN)";
	}
} else {
	print "No action requested";
}
