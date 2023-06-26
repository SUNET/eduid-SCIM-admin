<?php
Class HTML {
	# Setup
	function __construct($Mode='Prod') {
		$this->displayName = '';
		$this->startTimer = time();
		$this->mode = $Mode;
    $this->scope = str_replace('/','',$_SERVER['CONTEXT_PREFIX']);
	}

	###
	# Print start of webpage
	###
	public function showHeaders($title = "", $collapse = true) { ?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?=$title?></title>
    <link rel="stylesheet" href="/<?=$this->scope?>/css/reset.css" type="text/css" media="all" />
    <link rel="stylesheet" href="/<?=$this->scope?>/css/index.css" type="text/css" media="all" />
    <link rel="icon" href="/assets/favicon.ico" type="image/x-icon" />
    <!--style>
    /* Space out content a bit */
    .banner {
      <?= $this->mode == 'QA' ? 'background-color: #F05523;' : ''?><?= $this->mode == 'Lab' ? 'background-color: #8B0000;' : ''?>
    }
    </style-->
  </head>

  <body>
    <section class="banner">
      <header>
        <a href="<?=$_SERVER['CONTEXT_PREFIX']?>" area-label="eduID connect" title="eduID connect">
          <div class="eduid-connect-logo"></div>
        </a>
        <!-- <?=$this->mode == 'Prod' ? '' : $this->mode?> -->
        <div><?=$this->displayName?></div>
      </header>
      <div class="horizontal-content-margin">
        <h1 class="tagline">eduID Connect ger en organisationstillhörighet till eduID konton.</h1>
      </div>
    </section>

    <section class="panel">
      <div class="horizontal-content-margin content">
<?php	}

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
    </footer>
<?php if ($collapse) {
    print '    <script>
      function showUsers(id) {
        const collapsible = document.querySelector(`tr.collapsible[data-id="${id}"]`);
        const content = collapsible.nextElementSibling;

        if (content.classList.contains("content")) {
          content.style.display = content.style.display === "none" ? "table-row" : "none";
        }
      }

      const selectElement = document.querySelector("#selectList");
      const usertable = document.getElementById("list-users-table");
      const invitetable = document.getElementById("list-invites-table");

      selectElement.addEventListener("change", (event) => {
        if (event.target.value == "List Users") {
          usertable.hidden = false;
          invitetable.hidden = true;
        } else {
          usertable.hidden = true;
          invitetable.hidden = false;
        }
      });
    </script>' . "\n";
}?>

  </body>
</html>
<?php
	}

	public function setDisplayName($name) {
		$this->displayName = $name;
	}
}