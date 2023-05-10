<?php
$dbServername = "mariadb";
$dbUsername = "admin";
$dbPassword = "adminpwd";
$dbName = "SCIM";

$Mode = 'Lab';

$sourceIdP = 'https://idp.sunet.se/idp';
$backendIdP = 'https://login.idp.eduid.se/idp.xml';

# Array ('Shibb-name in apache' => 'name in satosa internal/SCIM')
$attibutes2migrate = array (
	'eduPersonPrincipalName' => 'eduPersonPrincipalName',
	'eduPersonScopedAffiliation' => 'eduPersonScopedAffiliation',
	'mail' => 'mail',
	'localMailAddress' => 'localMailAddress'
);

$allowedScopes = array ('sunet.se', 'test.se');
# Array ('affiliation' => 'depening on affilation')
$possibleAffiliations = array(
	'faculty' => 'employee',
	'staff' => 'employee',
	'employee' => 'member',
	'student' => 'member',
	'alum' => '',
	'member' => '',
	'affiliate' => '',
	'library-walk-in' => '',
);
