<?php
// Per-artist Open Graph image generator.
// /og.php?ulan=NNN -> 1200x630 PNG, cached to disk.

$ulan = $_GET['ulan'] ?? '';
if (!preg_match('/^\d{6,12}$/', $ulan)) {
    http_response_code(400);
    exit('invalid ulan');
}

$cacheDir  = __DIR__ . '/og-cache';
$cacheFile = $cacheDir . '/' . $ulan . '.png';
$ttl       = 30 * 86400;

// serve cached
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($cacheFile);
    exit;
}

require_once __DIR__ . '/settings.php';

$displayName = '';
$bio = '';
try {
    $st = $pdo->prepare("
        SELECT alias FROM artist_aliases
        WHERE ulan=? AND id=(
            SELECT id FROM artist_aliases
            WHERE ulan=? ORDER BY display DESC, preferred DESC, id ASC LIMIT 1
        )
    ");
    $st->execute([$ulan, $ulan]);
    $displayName = (string)($st->fetchColumn() ?: ('ULAN ' . $ulan));

    $st = $pdo->prepare('SELECT biography FROM biographies WHERE ulan=? AND preferred=1 LIMIT 1');
    $st->execute([$ulan]);
    $bio = (string)($st->fetchColumn() ?: '');
} catch (Throwable $e) {
    $displayName = 'ULAN ' . $ulan;
}

// humanize "Last, First" -> "First Last"
function humanize($n) {
    $parts = array_map('trim', explode(',', $n));
    if (count($parts) < 2) return $n;
    $family = array_shift($parts);
    return implode(' ', $parts) . ' ' . $family;
}
$display = humanize($displayName);

$W = 1200; $H = 630;
$img = imagecreatetruecolor($W, $H);
$bg     = imagecolorallocate($img, 0, 0, 0);
$white  = imagecolorallocate($img, 255, 255, 255);
$gray   = imagecolorallocate($img, 170, 170, 170);
$accent = imagecolorallocate($img, 91, 155, 213);
imagefill($img, 0, 0, $bg);

$fontBold = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$fontReg  = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

// thin accent bar
imagefilledrectangle($img, 80, 80, 88, 200, $accent);

// header eyebrow
imagettftext($img, 22, 0, 110, 110, $gray, $fontReg, 'ARTIST · NETWORKS');

// fit name to width: try sizes 96 -> 56 until it fits
$maxWidth = $W - 160;
$nameSize = 96;
while ($nameSize >= 40) {
    $box = imagettfbbox($nameSize, 0, $fontBold, $display);
    $w = $box[2] - $box[0];
    if ($w <= $maxWidth) break;
    $nameSize -= 8;
}
imagettftext($img, $nameSize, 0, 110, 220 + $nameSize, $white, $fontBold, $display);

// short bio snippet wrapped to two lines if available
if ($bio !== '') {
    $bioStr = strip_tags($bio);
    if (mb_strlen($bioStr) > 200) $bioStr = mb_substr($bioStr, 0, 197) . '…';
    $bioWords = preg_split('/\s+/', $bioStr);
    $line = ''; $lines = [];
    foreach ($bioWords as $w) {
        $cand = trim($line . ' ' . $w);
        $box = imagettfbbox(28, 0, $fontReg, $cand);
        if (($box[2] - $box[0]) > $maxWidth) {
            if ($line !== '') { $lines[] = $line; $line = $w; }
            else { $lines[] = $w; $line = ''; }
        } else {
            $line = $cand;
        }
        if (count($lines) >= 2) break;
    }
    if ($line && count($lines) < 2) $lines[] = $line;
    $yBase = 250 + $nameSize + 60;
    foreach (array_slice($lines, 0, 2) as $i => $l) {
        imagettftext($img, 28, 0, 110, $yBase + $i * 42, $gray, $fontReg, $l);
    }
}

// footer URL
imagettftext($img, 20, 0, 110, $H - 60, $gray, $fontReg, 'networks.vaguespac.es');

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($img, $cacheFile);
imagepng($img);
imagedestroy($img);
