<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Connect</title>
    <link rel="stylesheet" href="css/index.css" type="text/css" media="all" />
    <link rel="icon" href="./assets/favicon.ico" type="image/x-icon" />
  </head>

  <body>
    <section class="banner">
      <header>
        <a href="https://update-connect.sunet.se/" area-label="eduID start" title="eduID start">
          <div class="eduid-connect-logo"></div>
        </a>
      </header>
      <div class="horizontal-content-margin">
<?php

$errorURL = isset($_GET['errorURL']) ?
  'For more info visit this <a href="' . $_GET['errorURL'] . '">support-page</a>.' : '';
$errorURL = str_replace(array('ERRORURL_TS'), array(time()), $errorURL);
$errorURL = isset($_GET['RelayState']) ?
  str_replace(array('ERRORURL_RP'), array($_GET['RelayState'].'shibboleth'), $errorURL) : $errorURL;
$errorURL = isset($_SERVER['Shib-Session-ID']) ?
  str_replace(array('ERRORURL_TID'), array($_SERVER['Shib-Session-ID']), $errorURL) : $errorURL;


switch ($_GET['errorType']) {
  case 'opensaml::saml2md::MetadataException' :
    showMetadataException();
    break;
  case 'opensaml::FatalProfileException' :
    if ($_GET['statusMessage'] == 'Request cancelled by user') {
      print '        <h1>Login cancelled</h1>
        <p>You seems to have cancelled your login. Please try to login again.';
    } else {
      if ($_GET['eventType'] == 'Login' &&
        $_GET['statusCode'] == 'urn:oasis:names:tc:SAML:2.0:status:Responder' &&
        isset($_GET['statusCode2']) &&
        $_GET['statusCode2'] == 'urn:oasis:names:tc:SAML:2.0:status:NoAuthnContext') {
                  //case 'urn:oasis:names:tc:SAML:2.0:status:AuthnFailed' :
                  //case 'urn:oasis:names:tc:SAML:2.0:status:NoPassive' :
                  //case 'urn:oasis:names:tc:SAML:2.0:status:RequestDenied' :
        $errorURL = str_replace(array('ERRORURL_CODE', 'ERRORURL_CTX'),
          array('AUTHENTICATION_FAILURE', 'https://refeds.org/profile/mfa'), $errorURL);
      }
      showFatalProfileException();
    }
    break;
  default :
    showInfo();
}
showFooter ();

function showMetadataException() {?>
        <h1>Unknown Identity Provider</h1>
        <p>To report this problem, please contact the site administrator at
        <a href="mailto:noc@sunet.se">noc@sunet.se</a>.
        </p>
        <p>Please include the following error message in any email:</p>
        <div class="alert-warning">
          <p class="error">Identity provider lookup failed at (<?=htmlspecialchars($_GET['requestURL'])?>)</p>
          <p><strong>EntityID:</strong> <?=htmlspecialchars($_GET['entityID'])?></p>
          <p><?=htmlspecialchars($_GET['errorType'])?>: <?=htmlspecialchars($_GET['errorText'])?></p>
        </div>
<?php }

function showFatalProfileException() {
    global $errorURL;?>
        <h1>Unusable Identity Provider</h1>
        <p>The identity provider supplying your login credentials does not support the necessary capabilities.</p>
        <p>To report this problem, please contact the IdP administrator. <?=$errorURL?><br>
          If your are the IdP administrator you can reach out to
          <a href="mailto:noc@sunet.se">noc@sunet.se</a>.
        </p>
        <p>Please include the following error message in any email:</p>
        <p class="error">Identity provider lookup failed at (<?=htmlspecialchars($_GET['requestURL'])?>)</p>
        <p><strong>EntityID:</strong> <?=htmlspecialchars($_GET['entityID'])?></p>
        <p><?=htmlspecialchars($_GET['errorType'])?>: <?=htmlspecialchars($_GET['errorText'])?></p><?php
    print isset($_GET['statusCode']) ?
      "\n        <p>statusCode : " . htmlspecialchars($_GET['statusCode']) . '</p>' : '';
    print isset($_GET['statusCode2']) ?
      "\n        <p>statusCode2 : " . htmlspecialchars($_GET['statusCode2']) . '</p>' : '';
    print isset($_GET['statusMessage']) ?
      "\n        <p>statusMessage : " . htmlspecialchars($_GET['statusMessage']) . '</p>' : '';
 }

function showInfo() { ?>
    <table>
      <caption>Values</caption>
      <tr><th>Key</th><th>Value</th></tr>
    <?php
    foreach ($_GET as $key => $value) {
      printf('<tr><td>%s = %s</td></tr>%s', $key, htmlspecialchars($value), "\n");
    }
    print "</table>";
    ?>
<?php }

###
# Print footer on webpage
###
function showFooter() { ?>

      </div>
    </section>

    <footer id="footer">
      <div class="logo-wrapper">
        <a href="https://www.sunet.se/" area-label="Sunet.se" title="Sunet.se">
          <div class="sunet-logo"></div>
        </a>
      </div>
    </footer>
  </body>
</html>
<?php }
