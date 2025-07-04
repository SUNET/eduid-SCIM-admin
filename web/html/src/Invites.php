<?php
namespace scimAdmin;

use PDO;

/**
 * Class to handle Invites to be added to SCIM
 */
class Invites {
  private $scope = '';
  private $sourceIdP = '';
  private $backendIdP = '';
  private $attributes2migrate = array();
  private $forceMFA = false;
  private $orgName = '';
  private $ePPN_id = 0;

  private $smtpHost = '';
  private $saslUser = '';
  private $saslPassword = '';
  private $mailFrom = '';

  private $db;
  private $dbInstanceId = 0;

  const SQL_INVITELIST = 'SELECT *  FROM invites WHERE `instance_id` = :Instance
    ORDER BY `status` DESC, `hash`, `session`';
  const SQL_INVITE = 'SELECT *  FROM invites WHERE `instance_id` = :Instance AND `id` = :Id';
  const SQL_SPECIFICINVITE = 'SELECT *  FROM invites WHERE `session` = :Session AND `instance_id` = :Instance';

  const SQL_INSTANCE = ':Instance';
  const SQL_ID = ':Id';
  const SQL_MIGRATEINFO = ':MigrateInfo';
  const SQL_SESSION = ':Session';
  const SQL_ATTRIBUTES =':Attributes';
  const SQL_INVITEINFO = ':InviteInfo';
  const SQL_HASH = ':Hash';
  const SQL_LANG = ':Lang';

  const SWAMID_AL = 'http://www.swamid.se/policy/assurance/al'; # NOSONAR

  public function __construct() {
    $config = new Configuration();
    $this->db = $config->getDb();

    if ($instance = $config->getInstance()) {
      $this->scope = $config->getScope();
      $this->forceMFA = $config->forceMFA();
      $this->orgName = $config->orgName();
      $this->sourceIdP = $instance['sourceIdP'];
      $this->backendIdP = $instance['backendIdP'];
      $this->attributes2migrate = $instance['attributes2migrate'];
      $this->dbInstanceId = $config->getDbInstanceId();
    }

    if ($smtp = $config->getSMTP()) {
      $this->smtpHost = $smtp['Host'];
      $this->saslUser = $smtp['User'];
      $this->saslPassword = $smtp['Password'];
      $this->mailFrom = $smtp['From'];
    }
  }

  public function getInvitesList() {
    $invitesHandler = $this->db->prepare(self::SQL_INVITELIST);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
    $invitesHandler->execute();
    return $invitesHandler->fetchAll(PDO::FETCH_ASSOC);
  }

  public function getInvite($id) {
    $invitesHandler = $this->db->prepare(self::SQL_INVITE);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
    $invitesHandler->bindValue(self::SQL_ID, $id);
    $invitesHandler->execute();
    return $invitesHandler->fetch(PDO::FETCH_ASSOC);
  }

  public function validateID($id) {
    $invitesHandler = $this->db->prepare(self::SQL_INVITE);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
    $invitesHandler->bindValue(self::SQL_ID, $id);
    $invitesHandler->execute();
    if ($result = $invitesHandler->fetch(PDO::FETCH_ASSOC)) {
      return $result['id'];
    }
    return false;
  }

  public function checkInviteBySession($session) {
    $invitesHandler = $this->db->prepare(self::SQL_SPECIFICINVITE);
    $invitesHandler->bindParam(self::SQL_SESSION, $session);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
    $invitesHandler->execute();
    if ($invite = $invitesHandler->fetch(PDO::FETCH_ASSOC)) {
      return $invite;
    } else {
      return false;
    }
  }

  public function startMigrateFromSourceIdP() {
    $hostURL = "http".(!empty($_SERVER['HTTPS'])?"s":"")."://".$_SERVER['SERVER_NAME'];
    $redirectURL = sprintf('%s/Shibboleth.sso/Login?entityID=%s&target=%s&forceAuthn=true',
      $hostURL, $this->sourceIdP, urlencode($hostURL . '/' . $this->scope . '/migrate/?source'));
    header('Location: ' . $redirectURL);
  }

  public function checkSourceData() {
    $migrate = array();
    if ($_SERVER['Shib-Identity-Provider'] == $this->sourceIdP) {
      foreach ($this->attributes2migrate as $attribute => $SCIM) {
        if (isset($_SERVER[$attribute])) {
          $value = strpos($_SERVER[$attribute], ';') ? explode(";", $_SERVER[$attribute]) : $_SERVER[$attribute];
          $migrate[$SCIM] = $value;
        }
      }
      return $migrate;
    } else {
      return false;
    }
  }

  public function checkCorrectBackendIdP() {
    return $_SERVER['Shib-Identity-Provider'] == $this->backendIdP;
  }

  public function getUserDataFromIdP() {
    $migrate = array();
    $attributes= array('eduPersonPrincipalName','givenName', 'mail', 'mailLocalAddress',
      'norEduPersonNIN', 'schacDateOfBirth', 'sn', 'eduPersonAssurance');
    foreach ($attributes as $attribute) {
      $migrate[$attribute] = isset($_SERVER[$attribute]) ? $_SERVER[$attribute] : '';
    }
    return $migrate;
  }

  public function checkBackendData() {
    if ($this->checkCorrectBackendIdP()) {
      if (isset($_SERVER['eduPersonPrincipalName'])) {
        $migrate = $this->getUserDataFromIdP();
      } else {
        # No ePPN no need to continue
        return false;
      }
      return $migrate;
    } else {
      return false;
    }
  }

  public function checkALLevel($level) {
    $idpACFound = false;
    $userACFound = false;
    if (isset($_SERVER['Meta-Assurance-Certification'])) {
      foreach (explode(';', $_SERVER['Meta-Assurance-Certification']) as $AC) {
        $idpACFound = ($AC == self::SWAMID_AL . $level) ? true : $idpACFound;
      }
    }
    if (isset($_SERVER['eduPersonAssurance'])) {
      foreach (explode(';', $_SERVER['eduPersonAssurance']) as $AC) {
        $userACFound = ($AC == self::SWAMID_AL . $level) ? true : $userACFound;
      }
    }
    return $idpACFound && $userACFound;
  }

  public function ePPNexists($eduPersonPrincipalName) {
    $inviteHandler = $this->db->prepare(
      'SELECT `id`, `status`, `attributes`
      FROM `invites`
      WHERE `instance_id` = :Instance');
    $inviteHandler->execute(array(self::SQL_INSTANCE => $this->dbInstanceId));
    while ($invite = $inviteHandler->fetch(PDO::FETCH_ASSOC)) {
      $json = json_decode($invite['attributes']);
      if (isset($json->eduPersonPrincipalName) && $json->eduPersonPrincipalName == $eduPersonPrincipalName) {
        $this->ePPN_id = $invite['id'];
        return $invite['status'];
      }
    }
    return false;
  }

  public function updateInviteAttributes($session, $attributes, $inviteInfo) {
    $invitesHandler = $this->db->prepare(self::SQL_SPECIFICINVITE);
    $invitesHandler->bindParam(self::SQL_SESSION, $session);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
    $invitesHandler->execute();
    if ($invitesHandler->fetch(PDO::FETCH_ASSOC)) {
      # session exists in DB
      $updateHandler = $this->db->prepare('UPDATE invites
        SET `attributes` = :Attributes, `modified` = NOW(), status = 1, `inviteInfo` = :InviteInfo
        WHERE `session` = :Session AND `instance_id` = :Instance');
      $updateHandler->bindParam(self::SQL_SESSION, $session);
      $updateHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
      $updateHandler->bindValue(self::SQL_ATTRIBUTES, json_encode($attributes));
      $updateHandler->bindValue(self::SQL_INVITEINFO, json_encode($inviteInfo));
      return $updateHandler->execute();
    } else {
      # No session exists, create a new
      $insertHandler = $this->db->prepare('INSERT INTO invites
        (`instance_id`, `session`, `modified`, `attributes`, `status`, `inviteInfo`)
        VALUES (:Instance, :Session, NOW(), :Attributes, 1, :InviteInfo)');
      $insertHandler->bindParam(self::SQL_SESSION, $session);
      $insertHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
      $insertHandler->bindValue(self::SQL_ATTRIBUTES, json_encode($attributes));
      $insertHandler->bindValue(self::SQL_INVITEINFO, json_encode($inviteInfo));
      return $insertHandler->execute();
    }
  }

  public function updateInviteSession($session) {
    $updateHandler = $this->db->prepare('UPDATE invites
      SET `modified` = NOW(),  `session` = :Session
      WHERE `id`= :Id AND status = 1 AND `instance_id` = :Instance');
    $updateHandler->bindParam(self::SQL_SESSION, $session);
    $updateHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
    $updateHandler->bindValue(self::SQL_ID, $this->ePPN_id);
    return $updateHandler->execute();
  }

  public function sendNewInviteCode($id) {
    $invite = $this->getInvite($id);
    $inviteInfo = json_decode($invite['inviteInfo']);
    $code = hash_hmac('md5','HashCode',time()); // NOSONAR

    if ($invite['lang'] == 'sv') {
      setlocale(LC_MESSAGES, 'sv_SE');
    } else {
      setlocale(LC_MESSAGES, 'en');
    }
    bindtextdomain("SCIM", __DIR__ . '/../locale');
    textdomain("SCIM");

    $updateHandler = $this->db->prepare('UPDATE invites
      SET `hash` = :Hash, `modified` = NOW(), `session` = ""
      WHERE `instance_id` = :Instance AND `id` = :Id');
    $updateHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
    $updateHandler->bindValue(self::SQL_ID, $id);
    $updateHandler->bindValue(self::SQL_HASH, hash('sha256', $code));
    $updateHandler->execute();

    $hostURL = "http" . (!empty($_SERVER['HTTPS'])?"s":"") . "://" . $_SERVER['SERVER_NAME'] . '/' . $this->scope . '/';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->CharSet = "UTF-8";
    $mail->Host = $this->smtpHost;
    $mail->SMTPAuth = true;
    $mail->SMTPAutoTLS = true;
    $mail->Port = 587;
    $mail->SMTPAuth = true;
    $mail->Username = $this->saslUser;
    $mail->Password = $this->saslPassword;
    $mail->SMTPSecure = 'tls';

    //Recipients
    $mail->setFrom($this->mailFrom, _('Connect - Admin'));
    $mail->addAddress($inviteInfo->mail);
    //Content
    $mail->isHTML(true);
    $mail->Body = sprintf('      <!DOCTYPE html>
      <html lang="en">
        <head>
          <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        </head>
        <body>
          <p>%s</p>
          <p>%s %s</p>
          <div class="numberList">%s
            <ol>
              <li><a href="https://eduid.se/register">%s</a></li>
              <li><a href="https://eduid.se/profile/">%s</a></li>%s',
      _('Hi.'),
      $this->orgName,
      _('uses eduID for login into national and international web-services. To be able to use this service you need to connect your personal eduID-account to your organisation. This is done by following the instructions below.'),
      _('To be able to make this connection, you need to have done the following:'),
      _('Create a personal identity on eduID.'),
      _('Verify your identity in eduID.'),
      "\n");
    if ($this->forceMFA) {
      $mail->Body .= sprintf('              <li><a href="https://eduid.se/profile/">%s</a></li>%s',
        _('Add a security key to eduID for safer login.'), "\n");
    }
    $mail->Body .= sprintf('            </ol>
          </div>
          <p>%s <a href="%s?action=showInviteFlow">%s?action=showInviteFlow</a> %s</p>
          <p>%s <b>%s</b></p>
          <p>%s</p>
        </body>
      </html>',
      _('When you have a personal identity in eduID, proceed to this web-page'),
      $hostURL, $hostURL,
      _('enter the follwing code and press the button.'),
      _('Invitecode:'),
      $code,
      _('Welcome'));
    $mail->AltBody = sprintf('%s
      %s %s

      %s

      1. %s, https://eduid.se/register
      2. %s, https://eduid.se/profile/%s',
      _('Hi.'),
      $this->orgName,
      _('uses eduID for login into national and international web-services. To be able to use this service you need to connect yoour personal eduID-account to your organisation.'),
      _('To be able to make this connection, you need to have done the following:'),
      _('Create a personal identity on eduID.'),
      _('Verify your identity in eduID.'),
      "\n");
    if ($this->forceMFA) {
      $mail->AltBody .= sprintf('      3. %s, https://eduid.se/profile/%s',
        _('Add a security key to eduID for safer login.'), "\n");
    }
    $mail->AltBody .= sprintf('
      %s %s?action=showInviteFlow %s

      %s %s

      %s',
      _('When you have a personal identity in eduID, proceed to this web-page'),
      $hostURL,
      _('enter the follwing code and press the button.'),
      _('Invitecode:'),
      $code,
    _('Welcome'));
    $mail->Subject  = _('Your invite code for eduID Connect');

    try {
      $mail->send();
    } catch (\PHPMailer\PHPMailer\Exception $e) {
      echo 'Message could not be sent to invited person.<br>';
      echo 'Mailer Error: ' . $mail->ErrorInfo . '<br>';
    }
  }

  public function updateInviteAttributesById($id, $attributes, $inviteInfo, $lang, $sendMail = true) {
    $invitesHandler = $this->db->prepare('SELECT *  FROM invites WHERE `id` = :Id AND `instance_id` = :Instance');
    $invitesHandler->bindParam(self::SQL_ID, $id);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
    $invitesHandler->execute();
    if ($invitesHandler->fetch(PDO::FETCH_ASSOC)) {
      # session exists in DB
      $updateHandler = $this->db->prepare('UPDATE invites
        SET `attributes` = :Attributes, `modified` = NOW(), status = 1, `inviteInfo` = :InviteInfo, `lang` = :Lang
        WHERE `id` = :Id AND `instance_id` = :Instance');
      $updateHandler->bindParam(self::SQL_ID, $id);
      $updateHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
      $updateHandler->bindValue(self::SQL_ATTRIBUTES, json_encode($attributes));
      $updateHandler->bindValue(self::SQL_INVITEINFO, json_encode($inviteInfo));
      $updateHandler->bindValue(self::SQL_LANG, $lang);
      return $updateHandler->execute();
    } else {
      # No id exists, create a new
      $insertHandler = $this->db->prepare('INSERT INTO invites
        (`instance_id`, `modified`, `attributes`, `status`, `inviteInfo`, `lang`)
        VALUES (:Instance, NOW(), :Attributes, 1, :InviteInfo, :Lang)');
      $insertHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
      $insertHandler->bindValue(self::SQL_ATTRIBUTES, json_encode($attributes));
      $insertHandler->bindValue(self::SQL_INVITEINFO, json_encode($inviteInfo));
      $insertHandler->bindValue(self::SQL_LANG, $lang);
      if ($insertHandler->execute()) {
        if ($sendMail) {
          $this->sendNewInviteCode($this->db->lastInsertId());
        }
        return true;
      } else {
        return false;
      }
    }
  }

  public function updateInviteByCode($session,$code) {
    $invitesHandler = $this->db->prepare("SELECT *  FROM invites
      WHERE `hash` = :Hash AND `instance_id` = :Instance AND `status` = 1");
    $invitesHandler->bindValue(self::SQL_HASH, hash('sha256', $code));
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
    $invitesHandler->execute();
    if ($invitesHandler->fetch(PDO::FETCH_ASSOC)) {
      # inviteCode exists in DB and has not been used
      $updateHandler = $this->db->prepare('UPDATE invites
        SET `session` = :Session, `modified` = NOW()
        WHERE `hash` = :Hash AND `instance_id` = :Instance');
      $updateHandler->bindValue(self::SQL_HASH, hash('sha256', $code));
      $updateHandler->bindParam(self::SQL_SESSION, $session);
      $updateHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
      return $updateHandler->execute();
    } else {
      return false;
    }
  }

  public function getInviteBySession($session) {
    $invitesHandler = $this->db->prepare(self::SQL_SPECIFICINVITE);
    $invitesHandler->bindParam(self::SQL_SESSION, $session);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
    $invitesHandler->execute();
    return $invitesHandler->fetch(PDO::FETCH_ASSOC);
  }

  public function removeInvite($id) {
    $invitesHandler = $this->db->prepare('DELETE FROM invites
      WHERE `id` = :Id AND `instance_id` = :Instance');
    $invitesHandler->bindParam(self::SQL_ID, $id);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
    return $invitesHandler->execute();
  }

  public function move2Manual($id, $migrateInfo) {
    $invitesHandler = $this->db->prepare('UPDATE invites
      SET `status` = 2, `migrateInfo`= :MigrateInfo, `session` = NULL
      WHERE `id` = :Id AND `instance_id` = :Instance');
    $invitesHandler->bindParam(self::SQL_ID, $id);
    $invitesHandler->bindValue(self::SQL_MIGRATEINFO, $migrateInfo);
    $invitesHandler->bindValue(self::SQL_INSTANCE, $this->dbInstanceId);
    return $invitesHandler->execute();
  }

  public function getInstance() {
    return $this->scope;
  }

  public function getInviteePPNid() {
    return $this->ePPN_id;
  }

  public function redirectToNewIdP($page, $mfa = false) {
    $hostURL = "http".(!empty($_SERVER['HTTPS'])?"s":"")."://".$_SERVER['SERVER_NAME'];
    $redirectURL = sprintf('%s/Shibboleth.sso/Login?entityID=%s&target=%s&forceAuthn=true%s',
      $hostURL, $this->backendIdP, urlencode($hostURL . '/' . $this->scope . '/' . $page),
      $mfa ? '&authnContextClassRef=https%3A%2F%2Frefeds.org%2Fprofile%2Fmfa' : '');
    header('Location: ' . $redirectURL);
  }

  public function validateEmail($string) {
    $mailA = explode('@', $string);
    if (isset($mailA[1]) && strlen($mailA[0])) {
      # x exists set in x@yy.zz
      $domainA = explode('.', $mailA[1]);
      if (count($domainA) > 1 && strlen($domainA[count($domainA)-1]) > 1) {
        ## yy and zz exists and zz is at least 2 chars
        return true;
      }
    }
    return false;
  }

  public function validateSSN($ssn, $allowBirthDate = false) {
    if (strlen($ssn) == 12 || (strlen($ssn) == 8 && $allowBirthDate)) {
      $dateA = str_split($ssn,2);
      if ($dateA[0] > 18 && $dateA[0] < 21 && $dateA[2] < 13 && $dateA[3] < 32) {
        if ($allowBirthDate) {
          return true;
        } else {
          $ssnA = str_split($ssn);
          $checkValue = 0;
          for ($pos = 2; $pos <= 10; $pos += 2) {
            if ($ssnA[$pos] * 2 > 9) {
              $checkValue += ($ssnA[$pos] * 2) - 9;
            } else {
              $checkValue += $ssnA[$pos] * 2;
            }
            #printf ('%d<br>', $checkValue);
          }
          for ($pos = 3; $pos <= 9; $pos += 2) {
            $checkValue += $ssnA[$pos];
            #printf ('%d<br>', $checkValue);
          }
          return (10 - ($checkValue %10))%10 == $ssnA[11];
        }
      }

    }
    return false;
  }
}
