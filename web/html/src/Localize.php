<?php
namespace scimAdmin;

class Localize {

  public function __construct() {
    if(session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
    }

    $selectedLang = false;

    if (isset($_GET['lang'])) {
      if ($_GET['lang'] == 'sv') {
        $selectedLang = 'sv_SE';
      } else {
        $selectedLang = 'en';
      }
    }
    elseif (isset($_SESSION['lang'])) {
      $this->setLocale($_SESSION['lang']);
    } else {
      $langs = array();

      if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        // break up string into pieces (languages and q factors)
        preg_match_all('/([a-z]{1,8}(-[a-z]{1,8})?)\s*(;\s*q\s*=\s*(1|0\.[0-9]+))?/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $lang_parse);
      
        if (count($lang_parse[1])) {
          // create a list like "en" => 0.8
          $langs = array_combine($lang_parse[1], $lang_parse[4]);
        
          // set default to 1 for any without q factor
          foreach ($langs as $lang => $val) {
            $langs[$lang] = $val === '' ? 1 : $val;
          }

          // sort list based on value
          arsort($langs, SORT_NUMERIC);
        }
      }

      // look through sorted list and use first one that matches our languages
      foreach ($langs as $lang => $val) {
        if (! $selectedLang ) {
          switch ($lang) {
            case 'sv' :
            case 'sv-SE' :
              $selectedLang = 'sv_SE';
              break;
            case 'en-GB' :
            case 'en-US' :
            case 'en' :
              $selectedLang = 'en';
              break;
            default:
          }
        }
      }
    }
    if ($selectedLang) {
      if ($selectedLang != 'en') {
        $this->setLocale($selectedLang);
      }
      $_SESSION['lang'] = $selectedLang;
    }
  }

  private function setLocale($locale) {
    setlocale(LC_MESSAGES, $locale); // Linux
    bindtextdomain("SCIM", __DIR__ . '/../locale');
    textdomain("SCIM");
  }
}
