<?php
$dbServername = "mariadb";
$dbUsername = "admin";
$dbPassword = "adminpwd";
$dbName = "scim";

$authUrl = 'https://auth-test.sunet.se/';             # URL to the auth server to get token
$keyName = '<Name of Key>';                           # Name of key in auth-server
$authCert = "<full path in OS>/authcert.pem";
$authKey = "<full path in OS>/authkey.pem";
$apiUrl = "https://api.dev.eduid.se/scim/test/";      # URL to the SCIM API

$smtpHost = 'smtp.xxx.se';
$saslUser = 'update-connect@smtp1.xxxxx.se';
$saslPassword = '<your SASL password>';               # NOSONAR
$mailFrom = 'no-reply@eduid.se';

$Mode = 'Lab';                                        # Lab or Prod

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

$instances = array (
  'sunet.se'=> array (
    'orgName' => 'Sunet',
    'forceMFA' => true,
    'sourceIdP' => 'https://idp.sunet.se/idp',
    'backendIdP' => 'https://login.idp.eduid.se/idp.xml',
    'allowedScopes' => array ('sunet.se'),

    # Array ('Shibb-name in apache' => 'name in satosa internal/SCIM')
    'attributes2migrate' => array (
      'eduPersonPrincipalName' => 'eduPersonPrincipalName',
      'eduPersonScopedAffiliation' => 'eduPersonScopedAffiliation',
      'mail' => 'mail',
    ),
    'adminUsers' => array (
      # user => level. 0-9 = view users 10 > Edit users,  20 > Invite new users
      'bjorn@sunet.se' => 20,
      'kazof-vagus@eduid.se' => 20,
      'jocar@sunet.se' => 20,
      'zacharias@sunet.se' => 20,
    ),
    # false - admin have to give ePPN
    # true - ePPN created from eduid ePPN
    'autoEPPN' => false,
  ),
);
