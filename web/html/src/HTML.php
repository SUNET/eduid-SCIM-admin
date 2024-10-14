<?php
namespace scimAdmin;

class HTML {
  # Setup
  private $displayName = '';
  private $extraURL  = '';
  private $scope = '';
  private $tagLine = '';
  private $tableToSort = array();

  public function __construct($tagLine = '') {
    $this->displayName = '';
    $this->extraURL = '';
    $this->scope = str_replace('/','',$_SERVER['CONTEXT_PREFIX']);
    $this->tagLine = $tagLine == '' ? _('Activate your organisation identity in eduID') : $tagLine;
  }

  ###
  # Print start of webpage
  ###
  public function showHeaders($title = '', $tagLine = '') { ?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?=$title?></title>
    <link rel="stylesheet" href="/<?=$this->scope?>/css/reset.css" type="text/css" media="all" />
    <link rel="stylesheet" href="/<?=$this->scope?>/css/index.css" type="text/css" media="all" />
    <link rel="stylesheet" href="/<?=$this->scope?>/css/fontawesome.min.css" type="text/css" media="all" />
    <link rel="stylesheet" href="/<?=$this->scope?>/css/solid.min.css" type="text/css" media="all" />
    <link rel="stylesheet" href="/<?=$this->scope?>/css/regular.min.css" type="text/css" media="all" />
    <link rel="stylesheet" href="/<?=$this->scope?>/css/jquery.dataTables.css" type="text/css" media="all" />
    <link rel="icon" href="/assets/favicon.ico" type="image/x-icon" />
  </head>

  <body>
    <section class="banner">
      <header>
        <a href="<?=$_SERVER['CONTEXT_PREFIX']?>" area-label="eduID connect" title="eduID connect">
          <div class="eduid-connect-logo"></div>
        </a>
        <div><?=$this->displayName?></div>
      </header>
      <div class="horizontal-content-margin">
        <h1 class="tagline"><?= $tagLine == '' ? $this->tagLine : $tagLine ?></h1>
      </div>
    </section>

    <section class="panel">
      <div class="horizontal-content-margin content">
<?php }

  ###
  # Print footer on webpage
  ###
  public function showFooter($collapse = false) { ?>
      </div>
    </section>

    <footer id="footer">
      <div class="logo-wrapper">
        <a href="https://www.sunet.se/" area-label="Sunet.se" title="Sunet.se">
          <div class="sunet-logo"></div>
        </a>
      </div>
      <div>
        <?php
        printf ('<a href="?lang=sv%s">Svenska</a> | <a href="?lang=en%s">English</a>', $this->extraURL, $this->extraURL);
        ?>

      </div>
    </footer>

<?php
    if ($collapse || isset($this->tableToSort[0])) {
      if (isset($this->tableToSort[0])) {
        # Add JS script to be able to use later
        printf('        <script src="/%s/js/jquery-3.7.1.slim.min.js"
      integrity="sha256-kmHvs0B+OpCW5GVHUNjv9rOmY0IvSIRcf7zGUDTDQM8="
      crossorigin="anonymous">
    </script>
    <script type="text/javascript" charset="utf8"
      src="/%s/js/jquery.dataTables.js">
    </script>%s', $this->scope, $this->scope, "\n");
      }
      print "    <script>\n";
      if ($collapse) {
        print '      function showId(id) {
        const collapsible = document.querySelector(`tr.collapsible[data-id="${id}"]`);
        const content = collapsible.nextElementSibling;

        if (content.classList.contains("content")) {
          content.style.display = content.style.display === "none" ? "table-row" : "none";
        }
      }

      const selectElement = document.querySelector("#selectList");
      const usertable = document.getElementById("list-users-table");
      const invitetable = document.getElementById("list-invites-table");
      const deletedUsertable = document.getElementById("list-deletedUsers-table");

      selectElement.addEventListener("change", (event) => {
        switch(event.target.value) {
          case "List Users":
            usertable.hidden = false;
            invitetable.hidden = true;
            deletedUsertable.hidden = true;
            break;
          case "List invites":
            usertable.hidden = true;
            invitetable.hidden = false;
            deletedUsertable.hidden = true;
            break;
          default:
            usertable.hidden = true;
            invitetable.hidden = true;
            deletedUsertable.hidden = false;
        }
      });' . "\n";
      }
      if (isset($this->tableToSort[0])) {
        print "    $(document).ready(function () {\n";
        foreach ($this->tableToSort as $table) {
          printf ("      $('#%s').DataTable( {paging: false});\n", $table);
        }
        print "    });\n";
      }
      print '    </script>' . "\n";
    }
    printf ('  </body>%s</html>%s', "\n", "\n");
  }

  public function setDisplayName($name) {
    $this->displayName = $name;
  }

  public function setExtraURLPart($extra) {
    $this->extraURL = $extra;
  }

  public function addTableSort($tableId) {
    $this->tableToSort[] = $tableId;
  }
}
