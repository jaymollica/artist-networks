<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/digest_mail.php';

// Accept both GET (link click) and POST (RFC 8058 one-click).
$ok = false;
$email = '';
$token = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['t'] ?? $_POST['t'] ?? ''));
if ($token !== '' && strlen($token) >= 32) {
    $pdo = dm_pdo();
    $st = $pdo->prepare('SELECT id, email, source FROM digest_subscribers WHERE token = ?');
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $email = $row['email'];
        [$audOk, $audCode, $audBody] = dm_audience_remove($email, $row['source']);
        if (!$audOk) error_log("[unsubscribe] audience remove failed for $email ({$row['source']}): $audCode $audBody");
        $del = $pdo->prepare('DELETE FROM digest_subscribers WHERE id = ?');
        $del->execute([$row['id']]);
        $ok = true;
    }
}

// One-click POST: return 200 with no UI per RFC 8058.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: text/plain');
    echo $ok ? 'unsubscribed' : 'unknown token';
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Unsubscribed — Artist Networks</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
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
      <?php if ($ok): ?>
        <h1>Unsubscribed</h1>
        <p>Removed <strong><?= htmlspecialchars($email) ?></strong> from the digest list. Sorry to see you go — <a href="/subscribe.php">resubscribe</a> any time.</p>
      <?php else: ?>
        <h1>Unsubscribe failed</h1>
        <p>That unsubscribe link is invalid or already used.</p>
      <?php endif ?>
    </div>
  </main>
</body>
</html>
