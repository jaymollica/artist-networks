<?php
/**
 * Triangulation candidates: pairs of artists with N>=THRESHOLD mutual neighbors
 * but NO direct Getty edge. Strong "Getty might have missed this" candidates.
 *
 *   php scripts/insight_triangulation.php [--top=50] [--threshold=4]
 *
 * Writes a markdown report to /var/log/artist-networks/insight-triangulation-YYYY-MM-DD.md
 */

declare(strict_types=1);
ini_set('memory_limit', '4G');

require_once __DIR__ . '/../lib/report_sources.php';

$opts      = getopt('', ['top::', 'threshold::']);
$topN      = isset($opts['top']) ? (int)$opts['top'] : 50;
$threshold = isset($opts['threshold']) ? (int)$opts['threshold'] : 4;

const DB_NAME    = 'artist_networks';
const DB_USER    = 'artist_networks';
const DB_PASS_FILE = '/root/.artist_networks_db_pass';

$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    trim((string)file_get_contents(DB_PASS_FILE)),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function logmsg(string $m): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $m . "\n"); }

logmsg('loading edges…');
$adj   = [];
$edges = [];
$st = $pdo->query('SELECT artist_ulan, related_ulan FROM artist_relationships');
while ($r = $st->fetch(PDO::FETCH_NUM)) {
    $a = (int)$r[0]; $b = (int)$r[1];
    if ($a === $b) continue;
    $adj[$a][$b] = true;
    $adj[$b][$a] = true;
    $edges[$a < $b ? "$a-$b" : "$b-$a"] = true;
}
logmsg('nodes: ' . count($adj) . ', undirected edges: ' . count($edges));

logmsg('counting mutual neighbors…');
$mutual = [];
$artistCount = 0;
foreach ($adj as $x => $neighbors) {
    $artistCount++;
    if ($artistCount % 5000 === 0) logmsg("  processed $artistCount artists, " . count($mutual) . ' candidate pairs');
    $n = array_keys($neighbors);
    sort($n);
    $c = count($n);
    if ($c < 2) continue;
    for ($i = 0; $i < $c - 1; $i++) {
        $ai = $n[$i];
        for ($j = $i + 1; $j < $c; $j++) {
            $bj = $n[$j];
            // (ai < bj is guaranteed because sorted)
            $key = "$ai-$bj";
            if (isset($edges[$key])) continue; // already connected
            $mutual[$key] = ($mutual[$key] ?? 0) + 1;
        }
    }
}
logmsg('total candidate pairs: ' . count($mutual));

logmsg('sorting and picking top ' . $topN . ' with threshold ' . $threshold . '…');
arsort($mutual);
$top = [];
foreach ($mutual as $key => $count) {
    if ($count < $threshold) break;
    $top[$key] = $count;
    if (count($top) >= $topN) break;
}
unset($mutual); // free memory

// Lookup names
$ulans = [];
foreach ($top as $key => $_) {
    [$a, $b] = explode('-', $key);
    $ulans[(int)$a] = true;
    $ulans[(int)$b] = true;
}
$names = [];
if ($ulans) {
    $ids   = array_keys($ulans);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT a.ulan, a.alias FROM artist_aliases a
            WHERE a.ulan IN ($place) AND a.id = (
                SELECT a2.id FROM artist_aliases a2
                WHERE a2.ulan = a.ulan
                ORDER BY a2.display DESC, a2.preferred DESC, a2.id ASC LIMIT 1
            )";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $n = $r['alias'];
        $parts = array_map('trim', explode(',', $n));
        if (count($parts) >= 2) { $f = array_shift($parts); $n = implode(' ', $parts) . ' ' . $f; }
        $names[(int)$r['ulan']] = $n;
    }
}

$path = '/var/log/artist-networks/insight-triangulation-' . date('Y-m-d') . '.md';
$fh = fopen($path, 'w');
fwrite($fh, "# Triangulation candidates\n\n");
fwrite($fh, "_" . date('c') . "_\n\n");
fwrite($fh, "Pairs of artists who share **" . $threshold . "+ mutual connections** in Getty ULAN but have no direct edge recorded. Strong candidates for relationships Getty may have missed (or for further investigation).\n\n");
fwrite($fh, "| Mutual | Artist A | Artist B | Inspect |\n");
fwrite($fh, "|---:|---|---|---|\n");
foreach ($top as $key => $count) {
    [$a, $b] = explode('-', $key);
    $na = $names[(int)$a] ?? "ULAN $a";
    $nb = $names[(int)$b] ?? "ULAN $b";
    $url = '/bacon.html?ulan1=' . $a . '&ulan2=' . $b;
    fwrite($fh, "| $count | [$na](/?ulan=$a) | [$nb](/?ulan=$b) | [path]($url) |\n");
}
fwrite($fh, build_sources_footer(['getty']));
fclose($fh);
logmsg('wrote ' . $path);
echo $path . "\n";
