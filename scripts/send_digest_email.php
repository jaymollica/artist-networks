<?php
/**
 * Send the most recent digest to every confirmed subscriber via Resend.
 * Skips subscribers who've already received the same digest file.
 *
 *   php scripts/send_digest_email.php [--digest=/path/to/digest.md] [--dry-run]
 */

declare(strict_types=1);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../lib/digest_mail.php';
require_once __DIR__ . '/../lib/Parsedown.php';

$opts   = getopt('', ['digest::', 'dry-run', 'to::']);
$dryRun = isset($opts['dry-run']);
$sampleTo = !empty($opts['to']) ? (string)$opts['to'] : null;

function lm(string $m): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $m . "\n"); }

// 1. find the digest to send
if (!empty($opts['digest'])) {
    $digestPath = (string)$opts['digest'];
} else {
    // Only the full digest (digest-YYYY-MM-DD.md) is a valid send target.
    // The dataset-refresh process writes a near-empty stub under the same prefix
    // but with a time suffix (digest-YYYY-MM-DD-0430.md); those must never be
    // emailed to subscribers, so we exclude anything with a trailing -HHMM.
    $candidates = array_filter(
        glob('/var/log/artist-networks/digest-*.md') ?: [],
        fn($p) => (bool)preg_match('~/digest-\d{4}-\d{2}-\d{2}\.md$~', $p)
    );
    if (!$candidates) { lm('no full digest files found'); exit(1); }
    usort($candidates, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $digestPath = $candidates[0];
}
if (!is_readable($digestPath)) { lm("digest not readable: $digestPath"); exit(1); }
$digestName = basename($digestPath);
$digestMd   = (string)file_get_contents($digestPath);
lm("digest: $digestName (" . strlen($digestMd) . " bytes)");

// 2. render md → html (strip the top-level h1 since we render our own)
$md = preg_replace('/^# .+\n+/', '', $digestMd, 1);
$parsedown = new Parsedown();
$parsedown->setSafeMode(true);
$bodyHtml  = $parsedown->text($md);

// Rewrite root-relative links to absolute so they resolve in email clients.
// `(?!/)` skips protocol-relative `href="//…"`.
$bodyHtml = preg_replace('~\bhref="/(?!/)~', 'href="' . DM_SITE_URL . '/', $bodyHtml);

// extract a date from the digest filename for the subject line
$dateLabel = preg_match('/digest-(\d{4}-\d{2}-\d{2})/', $digestName, $m) ? $m[1] : date('Y-m-d');

// 3. load recipients
$pdo = dm_pdo();
if ($sampleTo !== null) {
    // One-off sample send (e.g. preview to author) — does NOT mark any
    // subscriber as already-received and never sends to subscribers in bulk.
    $subs = [['id' => null, 'email' => $sampleTo, 'token' => 'sample']];
    lm('sample send to: ' . $sampleTo);
} else {
    $st  = $pdo->prepare('SELECT id, email, token FROM digest_subscribers
                          WHERE confirmed_at IS NOT NULL
                            AND (last_digest_name IS NULL OR last_digest_name <> ?)');
    $st->execute([$digestName]);
    $subs = $st->fetchAll(PDO::FETCH_ASSOC);
    lm('subscribers to email: ' . count($subs));
}

if (!$subs) { lm('no one to send to'); exit(0); }
if ($dryRun) {
    foreach ($subs as $s) lm('  would send to ' . $s['email']);
    exit(0);
}

// 4. send one at a time so each gets its own unsubscribe link
$updSent = $pdo->prepare('UPDATE digest_subscribers SET last_sent_at = NOW(), last_digest_name = ? WHERE id = ?');
$sent = 0; $failed = 0;
foreach ($subs as $s) {
    $unsubUrl = dm_unsub_url($s['token']);
    $footer = '<hr style="border:none;border-top:1px solid #ddd;margin:32px 0 16px">'
            . '<p style="color:#777;font-size:12px;line-height:1.5">'
            . 'You\'re receiving this because you subscribed at <a href="' . DM_SITE_URL . '">networks.vaguespac.es</a>. '
            . '<a href="' . htmlspecialchars($unsubUrl) . '">Unsubscribe</a>.'
            . '</p>';
    $html = '<div style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;max-width:680px;margin:0 auto;padding:24px;color:#222;line-height:1.5">'
          . '<h1 style="font-size:22px;margin:0 0 16px">Artist Networks digest — ' . htmlspecialchars($dateLabel) . '</h1>'
          . $bodyHtml
          . $footer
          . '</div>';

    $subjectPrefix = $sampleTo !== null ? '[Sample] ' : '';
    [$ok, $code, $body] = dm_resend_send([$s['email']], $subjectPrefix . "Artist Networks digest — $dateLabel", $html, $unsubUrl);
    if ($ok) {
        if ($s['id'] !== null) $updSent->execute([$digestName, $s['id']]);
        $sent++;
    } else {
        $failed++;
        lm("  fail {$s['email']} code=$code body=" . substr($body, 0, 160));
    }
    usleep(150000); // 150ms — well under Resend's 10 req/s default
}
lm("done: $sent sent, $failed failed");
