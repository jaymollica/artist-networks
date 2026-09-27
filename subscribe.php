<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/digest_mail.php';

$status  = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email  = trim((string)($_POST['email']  ?? ''));
    $source = trim((string)($_POST['source'] ?? 'artist-networks'));
    if (!preg_match('/^[a-z0-9-]{1,64}$/', $source)) $source = 'artist-networks';
    if (!dm_valid_email($email)) {
        $status = 'error';
        $message = 'That doesn\'t look like a valid email address.';
    } else {
        $pdo = dm_pdo();
        $st  = $pdo->prepare('SELECT id, token, confirmed_at FROM digest_subscribers WHERE email = ?');
        $st->execute([$email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['confirmed_at']) {
            $status = 'ok';
            $message = 'You\'re already subscribed — thanks!';
        } else {
            if ($row) {
                $token = $row['token'];
            } else {
                $token = dm_token();
                $ins = $pdo->prepare('INSERT INTO digest_subscribers (email, source, token) VALUES (?, ?, ?)');
                $ins->execute([$email, $source, $token]);
            }

            $confirmUrl = dm_confirm_url($token);
            $unsubUrl   = dm_unsub_url($token);
            $html = '<p>Hi —</p>'
                  . '<p>Someone (hopefully you) asked to subscribe <strong>' . htmlspecialchars($email) . '</strong> '
                  . 'to the <a href="' . DM_SITE_URL . '">Artist Networks</a> digest.</p>'
                  . '<p>Confirm with one click:</p>'
                  . '<p><a href="' . $confirmUrl . '" style="display:inline-block;padding:12px 20px;'
                  . 'background:#222;color:#fff;text-decoration:none;border-radius:4px">Confirm subscription</a></p>'
                  . '<p style="color:#666;font-size:13px">If you didn\'t do this, ignore this email — '
                  . 'we won\'t send anything else unless you confirm.</p>';
            [$ok, $code, $body] = dm_resend_send([$email], 'Confirm your Artist Networks subscription', $html, $unsubUrl);
            if ($ok) {
                $status = 'ok';
                $message = 'Check your inbox for a confirmation link.';
            } else {
                $status = 'error';
                $message = 'Couldn\'t send the confirmation email right now. Try again in a bit.';
                error_log('[subscribe] resend send failed: ' . $code . ' ' . $body);
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Subscribe — Artist Networks</title>
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
      <h1>Subscribe to the digest</h1>
      <p>Once a month, on the first Sunday after we refresh the data, we mail a digest summarizing what changed in the artist-networks dataset and what the new insight reports surfaced.</p>
      <?php if ($status === 'ok'): ?>
        <p class="subscribe-ok" role="status"><?= htmlspecialchars($message) ?></p>
      <?php elseif ($status === 'error'): ?>
        <p class="subscribe-error" role="alert"><?= htmlspecialchars($message) ?></p>
      <?php endif ?>
      <form method="post" action="/subscribe.php" class="subscribe-form">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autocomplete="email"
               aria-required="true"
               aria-invalid="<?= $status === 'error' ? 'true' : 'false' ?>"
               aria-describedby="email-help"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        <p id="email-help" class="subscribe-help">We'll send a confirmation link; you have to click it before we add you.</p>
        <button type="submit">Subscribe</button>
      </form>
    </div>
  </main>
</body>
</html>
