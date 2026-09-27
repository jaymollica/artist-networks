<?php
require_once __DIR__ . '/lib/Parsedown.php';
require_once __DIR__ . '/lib/toc.php';

const DIGEST_DIR = '/var/log/artist-networks';

function list_digests() {
    $out = [];
    foreach (glob(DIGEST_DIR . '/digest-*.md') ?: [] as $path) {
        if (!preg_match('/digest-(\d{4}-\d{2}-\d{2}-\d{4})\.md$/', $path, $m)) continue;
        $stamp = $m[1];
        // pretty: "2026-05-10-1758" -> "2026-05-10 17:58"
        $pretty = substr($stamp, 0, 10) . ' ' . substr($stamp, 11, 2) . ':' . substr($stamp, 13, 2);
        $out[] = ['stamp' => $stamp, 'pretty' => $pretty, 'path' => $path, 'mtime' => filemtime($path)];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

$digests = list_digests();

$selected = null;
if (isset($_GET['d']) && preg_match('/^\d{4}-\d{2}-\d{2}-\d{4}$/', $_GET['d'])) {
    foreach ($digests as $d) if ($d['stamp'] === $_GET['d']) { $selected = $d; break; }
}
if (!$selected && $digests) $selected = $digests[0];

$bodyHtml = '';
$tocHtml  = '';
if ($selected && is_readable($selected['path'])) {
    $md = file_get_contents($selected['path']);
    $md = preg_replace('/^# .+\n+/', '', $md, 1);
    $parsedown = new Parsedown();
    $parsedown->setSafeMode(true);
    $bodyHtml = $parsedown->text($md);
    [$bodyHtml, $tocHtml] = inject_toc($bodyHtml);
}
?>
<!doctype html>
<html class="no-js" lang="en">

<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <title>Artist Networks: Updates</title>
  <meta name="description" content="Recent updates to the Artist Networks dataset.">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="manifest" href="site.webmanifest">
  <link rel="apple-touch-icon" href="https://networks.vaguespac.es/tile.png">
  <link rel="alternate" type="application/rss+xml" href="/updates.rss.php" title="Artist Networks updates">
  <link rel="stylesheet" href="css/normalize.css">
  <link rel="stylesheet" href="css/main.css">
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

  <main id="main" class="container">
    <div id="mobile-header">
      <h1><a href="/">Artist Networks</a></h1>
      <p>Explore the social networks of artists</p>
    </div>
    <div class="about updates">
      <h1>Updates</h1>
      <p>Weekly digests summarizing what changed in the Artist Networks dataset. <a href="/subscribe.php">Subscribe to the monthly email</a> or follow <a href="/updates.rss.php">via RSS</a>.</p>

      <?php if (!$digests): ?>
        <p><em>No digests available yet.</em></p>
      <?php else: ?>
        <div class="digest-picker">
          <label for="digest-select">Refresh:</label>
          <select id="digest-select" onchange="if(this.value)window.location='?d='+this.value">
            <?php foreach ($digests as $d): ?>
              <option value="<?= htmlspecialchars($d['stamp']) ?>" <?= $selected && $d['stamp'] === $selected['stamp'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['pretty']) ?>
              </option>
            <?php endforeach ?>
          </select>
        </div>
        <?= $tocHtml ?>
        <div class="digest-body">
          <?= $bodyHtml ?>
        </div>
      <?php endif ?>
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

</body>
</html>
