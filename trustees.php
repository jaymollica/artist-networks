<?php
$pageTitle = 'Trustee networks — Artist Networks';
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="description" content="The social graph of US museum boards: who serves on which boards, who connects museums to one another.">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="manifest" href="site.webmanifest">
  <link rel="apple-touch-icon" href="https://networks.vaguespac.es/tile.png">
  <link rel="stylesheet" href="css/normalize.css">
  <link rel="stylesheet" href="css/main.css">
  <link rel="stylesheet" type="text/css" href="css/vendor/jquery-ui.css" />
  <script defer src="https://analytics.vaguespac.es/script.js" data-website-id="4261d199-04cb-456f-a3ce-e2d22b4bfd17"></script>
</head>
<body>
  <a class="skip-link" href="#main">Skip to main content</a>
  <div class="site-title-container">
    <nav class="site-title" aria-label="Primary">
      <a href="/"><h1>ARTIST &middot; NETWORKS</h1></a>
      <ul class="sub-menu">
        <li><a href="/about.html">About</a></li>
        <li><a href="/reports.php">Reports</a></li>
        <!-- <li><a href="/viz-lab.php">Lab</a></li> hidden for now -->
        <li><a href="/updates.php">Updates</a></li>
        <li><a href="/bacon.html">Bacon</a></li>
      </ul>
    </nav>
  </div>

  <main id="main" class="container trustees-container">
    <div class="trustees-preamble">
      <h1>Trustee networks</h1>
      <p>The social graph of US museum boards. Search a trustee to see who they serve alongside; toggle 2nd-degree to reach neighbors-of-neighbors.</p>
      <p class="trustees-source-note">
        Data: IRS Form 990 Part VII officer rosters surfaced via
        <a href="https://projects.propublica.org/nonprofits/" target="_blank" rel="noopener">ProPublica Nonprofit Explorer</a>.
        Each museum link below leads to its ProPublica organization page.
      </p>
    </div>

    <div id="trustee-controls" class="trustees-controls" hidden>
      <a href="#" id="trustee-browse-back" class="trustees-back-link">← Browse all</a>
      <label class="trustees-toggle">
        <input type="checkbox" id="trustee-depth-toggle"> Include 2nd-degree
      </label>
      <div class="legend-spacer"></div>
      <span id="trustee-meta" class="trustees-meta"></span>
    </div>

    <div id="trustee-stage"></div>

    <div id="trustee-browse" class="trustees-browse">
      <div class="trustees-browse-col">
        <h2>Trustees on multiple boards</h2>
        <p class="trustees-browse-help">People who sit on two or more museum boards. Click to open their network.</p>
        <ul id="trustee-browse-bridges" class="browse-list"></ul>
      </div>
      <div class="trustees-browse-col">
        <h2>Boards by museum</h2>
        <p class="trustees-browse-help">Pick a museum to see its trustees, then click a name to open the network.</p>
        <ul id="trustee-browse-museums" class="browse-list"></ul>
      </div>
    </div>

    <div id="trustee-detail" class="trustees-detail" hidden></div>
  </main>

  <div class="footer">
    <div class="info">
      <ul>
        <li><a href="/">networks.vaguespac.es</a></li>
        <li>Explore the social networks of artists.</li>
        <li class="lede">by <a href="https://www.jaymollica.com">Jay Mollica</a></li>
        <li><a href="/about.html">About</a></li>
        <li>&copy; <span class="current-year">2026</span> Vague Media, LLC</li>
      </ul>
    </div>
  </div>

  <script src="js/vendor/jquery-3.3.1.min.js"></script>
  <script src="js/vendor/jquery-ui.js"></script>
  <script src="js/vendor/d3.v5.min.js"></script>
  <script src="js/trustees.js"></script>
  <script>$(".current-year").text(new Date().getFullYear());</script>
</body>
</html>
