<?php
$pageTitle = 'Artist Networks: Explore the social networks of artists';
$ogTitle   = 'Artist Networks: Explore the social networks of artists.';
$ogDesc    = 'An interactive visualization of connections between artists and the movements and organizations they are related to.';
$ogImage   = 'https://networks.vaguespac.es/tile.png';
$ogUrl     = 'https://networks.vaguespac.es/';

if (isset($_GET['ulan']) && preg_match('/^\d{6,12}$/', $_GET['ulan'])) {
    $_artistUlan = $_GET['ulan'];
    require_once __DIR__ . '/settings.php';
    try {
        $st = $pdo->prepare("
            SELECT alias FROM artist_aliases
            WHERE ulan=? AND id=(
                SELECT id FROM artist_aliases
                WHERE ulan=? ORDER BY display DESC, preferred DESC, id ASC LIMIT 1
            )
        ");
        $st->execute([$_artistUlan, $_artistUlan]);
        $rawName = (string)($st->fetchColumn() ?: '');
        if ($rawName !== '') {
            $parts = array_map('trim', explode(',', $rawName));
            if (count($parts) >= 2) { $f = array_shift($parts); $name = implode(' ', $parts) . ' ' . $f; }
            else                    { $name = $rawName; }
            $pageTitle = $name . ' — Artist Networks';
            $ogTitle   = $name . ' — Artist Networks';
            $ogImage   = 'https://networks.vaguespac.es/og.php?ulan=' . $_artistUlan;
            $ogUrl     = 'https://networks.vaguespac.es/?ulan=' . $_artistUlan;
            $st2 = $pdo->prepare('SELECT biography FROM biographies WHERE ulan=? AND preferred=1 LIMIT 1');
            $st2->execute([$_artistUlan]);
            $bio = (string)($st2->fetchColumn() ?: '');
            if ($bio !== '') $ogDesc = mb_substr(strip_tags($bio), 0, 200);
        }
    } catch (Throwable $e) { /* fall through to defaults */ }
}
?><!doctype html>
<html class="no-js" lang="en">

<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="title" content="<?= htmlspecialchars($ogTitle) ?>">
  <meta name="description" content="<?= htmlspecialchars($ogDesc) ?>">
  <meta name="author" content="Jay Mollica">

  <!-- Open Graph / Facebook -->
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= htmlspecialchars($ogUrl) ?>">
  <meta property="og:title" content="<?= htmlspecialchars($ogTitle) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($ogDesc) ?>">
  <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">

  <!-- Twitter -->
  <meta property="twitter:card" content="summary_large_image">
  <meta property="twitter:url" content="<?= htmlspecialchars($ogUrl) ?>">
  <meta property="twitter:title" content="<?= htmlspecialchars($ogTitle) ?>">
  <meta property="twitter:description" content="<?= htmlspecialchars($ogDesc) ?>">
  <meta property="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

  <link rel="manifest" href="site.webmanifest">
  <link rel="apple-touch-icon" href="https://networks.vaguespac.es/tile.png">
  <link rel="stylesheet" href="css/normalize.css">
  <link rel="stylesheet" href="css/main.css">
  <script defer src="https://analytics.vaguespac.es/script.js" data-website-id="4261d199-04cb-456f-a3ce-e2d22b4bfd17"></script>
</head>

<body>
  <a class="skip-link" href="#main">Skip to main content</a>
  <!--[if lte IE 9]>
    <p class="browserupgrade">You are using an <strong>outdated</strong> browser. Please <a href="https://browsehappy.com/">upgrade your browser</a> to improve your experience and security.</p>
  <![endif]-->

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

  <main id="main" class="container">
    <div id="mobile-header">
      <h1><a href="/">Artist Networks</a></h1>
      <p>Explore the social networks of artists</p>
    </div>
    <div class="search-wrapper">
      <div class="search-wrapper-inner">
        <form id="searchNetworks" role="search">
          <label for="hint" class="visuallyhidden">Search for an artist</label>
          <input type="text" class="network-search-box" id="hint" placeholder="Type to search..." aria-describedby="search-hint" autocomplete="off" />
          <input type="hidden" id="searchUlan" value="" />
          <span id="search-hint" class="visuallyhidden">Start typing an artist's name to see suggestions. Press Enter to open the network.</span>
        </form>
        <ul id="suggestion-results" class="suggestion-list"></ul>
        <!-- Outside #bio so it survives the panel being emptied when results load -->
        <p class="random-pick"><a href="/random.php" class="random-link">Surprise me — show a random artist</a></p>
        <div id="bio">
          <p class="artist-bio">Search for an artist to explore their social network.</p>
          <p class="featured-intro">Or jump in:</p>
          <ul class="featured-artists">
            <li><a href="?ulan=500009666" id="500009666" class="artist-link">Pablo Picasso</a></li>
            <li><a href="?ulan=500017300" id="500017300" class="artist-link">Henri Matisse</a></li>
            <li><a href="?ulan=500115588" id="500115588" class="artist-link">Vincent van Gogh</a></li>
            <li><a href="?ulan=500115393" id="500115393" class="artist-link">Marcel Duchamp</a></li>
            <li><a href="?ulan=500018666" id="500018666" class="artist-link">Georgia O&rsquo;Keeffe</a></li>
            <li><a href="?ulan=500030701" id="500030701" class="artist-link">Frida Kahlo</a></li>
            <li><a href="?ulan=500006031" id="500006031" class="artist-link">Andy Warhol</a></li>
            <li><a href="?ulan=500009365" id="500009365" class="artist-link">Salvador Dal&iacute;</a></li>
            <li><a href="?ulan=500015134" id="500015134" class="artist-link">Jackson Pollock</a></li>
            <li><a href="?ulan=500012368" id="500012368" class="artist-link">Mary Cassatt</a></li>
            <li><a href="?ulan=500273319" id="500273319" class="artist-link">Gertrude Stein</a></li>
            <li><a href="?ulan=500093239" id="500093239" class="artist-link">Jean-Michel Basquiat</a></li>
            <li><a href="?ulan=500057350" id="500057350" class="artist-link">Louise Bourgeois</a></li>
            <li><a href="?ulan=500122518" id="500122518" class="artist-link">Yayoi Kusama</a></li>
          </ul>
        </div>
      </div>
    </div>
    <div class="modal">
    </div>
    <div id="layout-toggle" class="layout-toggle" style="display:none">
      <button type="button" data-layout="grid" class="active">Grid</button>
      <button type="button" data-layout="clock">Lifetime clock</button>
      <span class="layout-toggle-sep"></span>
      <button type="button" id="toggle-depth">+ 2nd degree</button>
    </div>
    <div id="rel-legend" class="rel-legend" style="display:none">
      <span class="rel-swatch rel-mentorship"></span>Mentorship
      <span class="rel-swatch rel-family"></span>Family
      <span class="rel-swatch rel-affiliation"></span>Affiliation
      <span class="rel-swatch rel-collaboration"></span>Collaboration
      <span class="rel-swatch rel-other"></span>Other
    </div>
    <div id="stage">
    </div>
    <div id="mobile-stage">
    </div>
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

  <script src="js/vendor/modernizr-3.6.0.min.js"></script>
  <script src="js/vendor/jquery-3.3.1.min.js"></script>
  <script>window.jQuery || document.write('<script src="js/vendor/jquery-3.3.1.min.js"><\/script>')</script>
  <script src="js/vendor/jquery-ui.js"></script>
  <script src="js/vendor/d3.v5.min.js"></script>
  <link rel="stylesheet" type="text/css" href="css/vendor/jquery-ui.css" />
  <script src="js/plugins.js"></script>
  <script src="js/main.js"></script>

</body>

</html>
