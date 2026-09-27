<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/digest_mail.php';

$ok = false;
$alreadyConfirmed = false;
$email = '';
$token = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['t'] ?? ''));
if ($token !== '' && strlen($token) >= 32) {
    $pdo = dm_pdo();
    $st  = $pdo->prepare('SELECT id, email, source, confirmed_at FROM digest_subscribers WHERE token = ?');
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $email = $row['email'];
        if ($row['confirmed_at']) {
            $ok = true;
            $alreadyConfirmed = true;
        } else {
            $upd = $pdo->prepare('UPDATE digest_subscribers SET confirmed_at = NOW() WHERE id = ?');
            $upd->execute([$row['id']]);
            [$audOk, $audCode, $audBody] = dm_audience_add($email, $row['source']);
            if (!$audOk) error_log("[confirm] audience add failed for $email ({$row['source']}): $audCode $audBody");
            $ok = true;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= $ok ? 'Confirmed' : 'Confirmation failed' ?> — Artist Networks</title>
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
      <?php if ($ok && $alreadyConfirmed): ?>
        <h1>Already confirmed</h1>
        <p><strong><?= htmlspecialchars($email) ?></strong> is already on the digest list. You're all set.</p>
      <?php elseif ($ok): ?>
        <h1>You're in</h1>
        <p>Confirmed <strong><?= htmlspecialchars($email) ?></strong> for the digest. The next one ships on the first Sunday of the month, after the data refresh.</p>
      <?php else: ?>
        <h1>Confirmation failed</h1>
        <p>That confirmation link is invalid or expired. You can <a href="/subscribe.php">subscribe again</a>.</p>
      <?php endif ?>
    </div>
  </main>
</body>
</html>
