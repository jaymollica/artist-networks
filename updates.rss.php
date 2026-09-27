<?php
require_once __DIR__ . '/lib/Parsedown.php';
header('Content-Type: application/rss+xml; charset=UTF-8');

const DIGEST_DIR = '/var/log/artist-networks';
const SITE       = 'https://networks.vaguespac.es';

$digests = [];
foreach (glob(DIGEST_DIR . '/digest-*.md') ?: [] as $path) {
    if (!preg_match('/digest-(\d{4}-\d{2}-\d{2}-\d{4})\.md$/', $path, $m)) continue;
    $digests[] = ['stamp' => $m[1], 'path' => $path, 'mtime' => filemtime($path)];
}
usort($digests, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
$digests = array_slice($digests, 0, 20);

$pd = new Parsedown();
$pd->setSafeMode(true);

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
  <title>Artist Networks — Updates</title>
  <link><?= SITE ?>/updates.php</link>
  <description>Weekly digests of changes to the Artist Networks dataset.</description>
  <language>en-us</language>
  <atom:link href="<?= SITE ?>/updates.rss.php" rel="self" type="application/rss+xml"/>
<?php foreach ($digests as $d):
    $stamp   = $d['stamp'];
    $pretty  = substr($stamp, 0, 10) . ' ' . substr($stamp, 11, 2) . ':' . substr($stamp, 13, 2);
    $url     = SITE . '/updates.php?d=' . $stamp;
    $html    = $pd->text(file_get_contents($d['path']));
    $pubDate = date('r', $d['mtime']);
?>
  <item>
    <title>Refresh: <?= htmlspecialchars($pretty) ?></title>
    <link><?= htmlspecialchars($url) ?></link>
    <guid isPermaLink="true"><?= htmlspecialchars($url) ?></guid>
    <pubDate><?= htmlspecialchars($pubDate) ?></pubDate>
    <description><![CDATA[<?= $html ?>]]></description>
  </item>
<?php endforeach ?>
</channel>
</rss>
