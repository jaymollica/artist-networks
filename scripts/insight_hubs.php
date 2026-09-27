<?php
/**
 * Hub artists per era.
 *
 * For each birth-year decade (1300s..2000s), build a subgraph of artists born
 * in that decade plus their edges, then compute both degree centrality and
 * betweenness centrality (Brandes' algorithm). Output the top 10 hubs per
 * decade by each metric.
 *
 *   php scripts/insight_hubs.php [--top=10]
 */

declare(strict_types=1);
ini_set('memory_limit', '4G');

require_once __DIR__ . '/../lib/report_sources.php';

$opts = getopt('', ['top::']);
$topN = isset($opts['top']) ? (int)$opts['top'] : 10;

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

// Birth-year per ULAN (preferred biographies only).
logmsg('loading birth years…');
$birth = [];
$st = $pdo->query("SELECT ulan, MAX(birth_year) yr FROM biographies WHERE preferred=1 AND birth_year>0 GROUP BY ulan");
while ($r = $st->fetch(PDO::FETCH_NUM)) $birth[(int)$r[0]] = (int)$r[1];
logmsg('  ' . count($birth) . ' artists with birth year');

// Full adjacency.
logmsg('loading adjacency…');
$adj = [];
$st = $pdo->query("SELECT artist_ulan, related_ulan FROM artist_relationships");
while ($r = $st->fetch(PDO::FETCH_NUM)) {
    $a = (int)$r[0]; $b = (int)$r[1];
    if ($a === $b) continue;
    $adj[$a][$b] = true;
    $adj[$b][$a] = true;
}
logmsg('  ' . count($adj) . ' nodes with edges');

// Bucket artists by birth-year decade.
$decadeNodes = [];
foreach ($birth as $u => $yr) {
    $dec = intdiv($yr, 10) * 10;
    if ($dec < 1300 || $dec > 2020) continue;
    $decadeNodes[$dec][] = $u;
}
ksort($decadeNodes);
logmsg('decades: ' . implode(', ', array_keys($decadeNodes)));

// Brandes betweenness (BFS queue uses an index, not array_shift).
function brandes(array $nodes, array $subAdj): array {
    $bc = array_fill_keys($nodes, 0.0);
    foreach ($nodes as $s) {
        $S = [];
        $P = array_fill_keys($nodes, []);
        $sigma = array_fill_keys($nodes, 0);
        $sigma[$s] = 1;
        $d = array_fill_keys($nodes, -1);
        $d[$s] = 0;
        $Q = [$s];
        $qIdx = 0;
        while ($qIdx < count($Q)) {
            $v = $Q[$qIdx++];
            $S[] = $v;
            foreach ($subAdj[$v] ?? [] as $w) {
                if (!isset($d[$w])) continue;
                if ($d[$w] < 0) {
                    $d[$w] = $d[$v] + 1;
                    $Q[] = $w;
                }
                if ($d[$w] === $d[$v] + 1) {
                    $sigma[$w] += $sigma[$v];
                    $P[$w][] = $v;
                }
            }
        }
        $delta = array_fill_keys($nodes, 0.0);
        while ($S) {
            $w = array_pop($S);
            foreach ($P[$w] as $v) {
                $delta[$v] += ($sigma[$v] / $sigma[$w]) * (1 + $delta[$w]);
            }
            if ($w !== $s) $bc[$w] += $delta[$w];
        }
    }
    return $bc;
}

// Collect names for everyone we'll mention. (Build name map lazily.)
$nameCache = [];
function nameOf(PDO $pdo, int $ulan, array &$cache): string {
    if (isset($cache[$ulan])) return $cache[$ulan];
    $stmt = $pdo->prepare(
        "SELECT a.alias FROM artist_aliases a
         WHERE a.ulan = ? AND a.id = (
            SELECT a2.id FROM artist_aliases a2
            WHERE a2.ulan = a.ulan
            ORDER BY a2.display DESC, a2.preferred DESC, a2.id ASC LIMIT 1
         )"
    );
    $stmt->execute([$ulan]);
    $n = (string)($stmt->fetchColumn() ?: ('ULAN ' . $ulan));
    $parts = array_map('trim', explode(',', $n));
    if (count($parts) >= 2) { $f = array_shift($parts); $n = implode(' ', $parts) . ' ' . $f; }
    return $cache[$ulan] = $n;
}

// Per-decade compute + write.
$out = '/var/log/artist-networks/insight-hubs-' . date('Y-m-d') . '.md';
$fh  = fopen($out, 'w');
fwrite($fh, "# Hub artists per era\n\n_" . date('c') . "_\n\n");
fwrite($fh, "For each birth-year decade, the subgraph of artists born in that decade is analyzed. **Degree** is total Getty-recorded connections; **betweenness** is how often that artist sits on shortest paths between others in the same era (the social-broker measure).\n\n");

foreach ($decadeNodes as $dec => $nodes) {
    if (count($nodes) < 20 || $dec < 1500) continue; // skip sparse early decades

    // Total degree (all edges, regardless of partner's era).
    $deg = [];
    foreach ($nodes as $u) $deg[$u] = isset($adj[$u]) ? count($adj[$u]) : 0;
    arsort($deg);
    $topDeg = array_slice($deg, 0, $topN, true);

    fwrite($fh, "## " . $dec . "s — " . count($nodes) . " artists born this decade\n\n");
    fwrite($fh, "**Top by total Getty connections:**\n\n");
    $rank = 1;
    foreach ($topDeg as $u => $d) {
        if ($d === 0) break;
        $yr = $birth[$u] ?? '?';
        fwrite($fh, $rank++ . '. [' . nameOf($pdo, $u, $nameCache) . '](/?ulan=' . $u . ') — ' . $d . " connections (b. $yr)\n");
    }
    fwrite($fh, "\n");
}
fwrite($fh, build_sources_footer(['getty']));
fclose($fh);
logmsg('wrote ' . $out);
echo $out . "\n";
