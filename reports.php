<?php
require_once __DIR__ . '/lib/Parsedown.php';
require_once __DIR__ . '/lib/toc.php';

const LOG_DIR = '/var/log/artist-networks';

$REPORTS = require __DIR__ . '/lib/reports_catalog.php';

function list_runs(string $prefix): array {
    $out = [];
    foreach (glob(LOG_DIR . '/' . $prefix . '*.md') ?: [] as $path) {
        if (!preg_match('/-(\d{4}-\d{2}-\d{2})\.md$/', $path, $m)) continue;
        $out[] = ['date' => $m[1], 'path' => $path, 'mtime' => filemtime($path)];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

$selectedReport = $_GET['r'] ?? null;
$selectedDate   = (isset($_GET['d']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['d'])) ? $_GET['d'] : null;

if ($selectedReport && !isset($REPORTS[$selectedReport])) $selectedReport = null;

$activeReport = null;
$activeRun    = null;
$runs         = [];

if ($selectedReport) {
    $activeReport = $REPORTS[$selectedReport];
    $runs = list_runs($activeReport['prefix']);
    if ($selectedDate) {
        foreach ($runs as $r) if ($r['date'] === $selectedDate) { $activeRun = $r; break; }
    }
    if (!$activeRun && $runs) $activeRun = $runs[0];
}

$bodyHtml = '';
$tocHtml  = '';
if ($activeRun && is_readable($activeRun['path'])) {
    $md = file_get_contents($activeRun['path']);
    // strip the top-level h1 since we render our own page header
    $md = preg_replace('/^# .+\n+/', '', $md, 1);
    $parsedown = new Parsedown();
    $parsedown->setSafeMode(true);
    $bodyHtml = $parsedown->text($md);
    [$bodyHtml, $tocHtml] = inject_toc($bodyHtml);
}

$pageTitle = $activeReport
    ? ($activeReport['title'] . ' — Artist Networks')
    : 'Reports — Artist Networks';
?>
<!doctype html>
<html class="no-js" lang="en">

<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="description" content="Network-analysis insights pulled from the Getty ULAN graph: missing-link candidates, long mentorship chains, and era hub artists.">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="manifest" href="site.webmanifest">
  <link rel="apple-touch-icon" href="https://networks.vaguespac.es/tile.png">
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
    <div class="about updates">
      <?php if (!$activeReport): ?>
        <h1>Reports</h1>
        <p>Network-analysis findings derived from the full Getty ULAN graph. Each report is regenerated periodically.</p>
        <div class="reports-grid">
          <?php foreach ($REPORTS as $key => $r):
              if (!empty($r['hidden'])) continue;
              $latest = list_runs($r['prefix']);
              $when   = $latest ? $latest[0]['date'] : null;
          ?>
            <a class="report-card" href="/reports.php?r=<?= htmlspecialchars($key) ?>">
              <h2>
                <?= htmlspecialchars($r['title']) ?>
                <?php if (!empty($r['badge'])): ?>
                  <span class="report-card-badge"><?= htmlspecialchars($r['badge']) ?></span>
                <?php endif ?>
              </h2>
              <p><?= htmlspecialchars($r['blurb']) ?></p>
              <?php if ($when): ?>
                <span class="report-card-date">Latest run: <?= htmlspecialchars($when) ?></span>
              <?php else: ?>
                <span class="report-card-date report-card-empty">No runs yet</span>
              <?php endif ?>
            </a>
          <?php endforeach ?>
        </div>
      <?php else: ?>
        <p class="reports-breadcrumb"><a href="/reports.php">← All reports</a></p>
        <h1>
          <?= htmlspecialchars($activeReport['title']) ?>
          <?php if (!empty($activeReport['badge'])): ?>
            <span class="report-card-badge"><?= htmlspecialchars($activeReport['badge']) ?></span>
          <?php endif ?>
        </h1>
        <p class="reports-blurb"><?= htmlspecialchars($activeReport['blurb']) ?></p>
        <?php if (!empty($activeReport['interactive_url'])): ?>
          <p class="reports-interactive">
            <a href="<?= htmlspecialchars($activeReport['interactive_url']) ?>">→ Interactive view</a>
          </p>
        <?php endif ?>

        <?php if (!$runs): ?>
          <p><em>No runs available yet.</em></p>
        <?php else: ?>
          <?php if (count($runs) > 1): ?>
            <div class="digest-picker">
              <label for="report-date">Run date:</label>
              <select id="report-date" onchange="if(this.value)window.location='?r=<?= htmlspecialchars($selectedReport) ?>&d='+this.value">
                <?php foreach ($runs as $r): ?>
                  <option value="<?= htmlspecialchars($r['date']) ?>" <?= ($activeRun && $r['date'] === $activeRun['date']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['date']) ?>
                  </option>
                <?php endforeach ?>
              </select>
            </div>
          <?php endif ?>
          <?= $tocHtml ?>
          <div class="digest-body">
            <?= $bodyHtml ?>
          </div>
        <?php endif ?>
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

  <script src="js/vendor/jquery-3.3.1.min.js"></script>
  <script>$(".current-year").text(new Date().getFullYear());</script>

</body>
</html>
