<?php
/**
 * Longest mentorship chains.
 *
 * Build a directed graph of teacher→student edges (collapsing all
 * teacher/master/apprentice/student/etc. relationships into one canonical
 * direction). Find longest paths starting from "root" teachers (nodes with no
 * incoming mentorship edge within the graph). Report the top N chains.
 *
 *   php scripts/insight_chains.php [--top=20]
 */

declare(strict_types=1);
ini_set('memory_limit', '2G');

require_once __DIR__ . '/../lib/report_sources.php';

$opts = getopt('', ['top::']);
$topN = isset($opts['top']) ? (int)$opts['top'] : 20;

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

// Relationship-type IDs we treat as mentorship.
// "Teacher of" (1101) and "master of" (1111): source = teacher, target = student.
// The "student of" (1102), "apprentice of" (1105), "apprentice was" (1106),
// "master was" (1112) versions are reciprocals — we flip them so all edges
// uniformly point teacher → student.
const TEACHER_TO_STUDENT = [1101, 1111];
const FLIP               = [1102, 1105, 1106, 1112];

logmsg('loading mentorship edges…');
$adj = []; // teacher => [student, student, ...]
$st = $pdo->prepare(
    "SELECT artist_ulan, related_ulan, relationship_type FROM artist_relationships
     WHERE relationship_type IN (1101, 1102, 1105, 1106, 1111, 1112)"
);
$st->execute();
while ($r = $st->fetch(PDO::FETCH_NUM)) {
    $a = (int)$r[0]; $b = (int)$r[1]; $t = (int)$r[2];
    if (in_array($t, TEACHER_TO_STUDENT, true)) {
        $adj[$a][$b] = true;
    } elseif (in_array($t, FLIP, true)) {
        $adj[$b][$a] = true;
    }
}
foreach ($adj as $k => $v) $adj[$k] = array_keys($v);
$edgeCount = 0;
foreach ($adj as $v) $edgeCount += count($v);
logmsg('mentorship edges (deduped): ' . $edgeCount . ' across ' . count($adj) . ' teachers');

// Compute longest path FROM each node using memoized DFS (DAG-friendly,
// cycle-safe with a visiting set).
$memo     = [];
$visiting = [];
function longestFrom(int $node): array {
    global $adj, $memo, $visiting;
    if (isset($memo[$node])) return $memo[$node];
    if (isset($visiting[$node])) return ['len' => 0, 'next' => null]; // cycle
    $visiting[$node] = true;
    $best = ['len' => 0, 'next' => null];
    foreach ($adj[$node] ?? [] as $next) {
        $r = longestFrom($next);
        $candLen = $r['len'] + 1;
        if ($candLen > $best['len']) $best = ['len' => $candLen, 'next' => $next];
    }
    unset($visiting[$node]);
    return $memo[$node] = $best;
}

logmsg('computing longest paths…');
// Use iterative driver to avoid PHP stack overflow.
$allNodes = array_unique(array_merge(
    array_keys($adj),
    array_reduce($adj, fn($carry, $v) => array_merge($carry, $v), [])
));
foreach ($allNodes as $n) longestFrom($n);
logmsg('done');

// Identify "root teachers" = nodes that appear as a teacher (have outgoing
// edges) but never as a student of another node in this subgraph.
$incoming = [];
foreach ($adj as $teacher => $students) {
    foreach ($students as $s) $incoming[$s] = true;
}
$roots = [];
foreach ($adj as $t => $_) {
    if (!isset($incoming[$t])) $roots[] = $t;
}
logmsg('root teachers: ' . count($roots));

// Rank roots by their longest chain length.
$chains = [];
foreach ($roots as $root) {
    $len = $memo[$root]['len'];
    if ($len < 2) continue; // skip 1-hop pairs; we want chains
    $chains[$root] = $len;
}
arsort($chains);
$top = array_slice($chains, 0, $topN, true);

// Reconstruct full chain for each top root.
$paths = [];
$nodeSet = [];
foreach ($top as $root => $len) {
    $path = [$root];
    $cur = $root;
    while (true) {
        $nxt = $memo[$cur]['next'] ?? null;
        if ($nxt === null) break;
        $path[] = $nxt;
        $cur = $nxt;
    }
    $paths[] = $path;
    foreach ($path as $u) $nodeSet[$u] = true;
}

// Bulk name + birth-year lookup.
$ulans = array_keys($nodeSet);
$names = [];
$years = [];
if ($ulans) {
    $place = implode(',', array_fill(0, count($ulans), '?'));
    $stmt = $pdo->prepare(
        "SELECT a.ulan, a.alias FROM artist_aliases a
         WHERE a.ulan IN ($place) AND a.id = (
            SELECT a2.id FROM artist_aliases a2
            WHERE a2.ulan = a.ulan
            ORDER BY a2.display DESC, a2.preferred DESC, a2.id ASC LIMIT 1
         )"
    );
    $stmt->execute($ulans);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $n = $r['alias'];
        $parts = array_map('trim', explode(',', $n));
        if (count($parts) >= 2) { $f = array_shift($parts); $n = implode(' ', $parts) . ' ' . $f; }
        $names[(int)$r['ulan']] = $n;
    }
    $stmt = $pdo->prepare(
        "SELECT ulan, MAX(birth_year) yr FROM biographies
         WHERE ulan IN ($place) AND preferred=1 AND birth_year>0 GROUP BY ulan"
    );
    $stmt->execute($ulans);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $years[(int)$r['ulan']] = (int)$r['yr'];
    }
}

$out = '/var/log/artist-networks/insight-chains-' . date('Y-m-d') . '.md';
$fh  = fopen($out, 'w');
fwrite($fh, "# Longest mentorship chains\n\n");
fwrite($fh, "_" . date('c') . "_\n\n");
fwrite($fh, "Longest unbroken **teacher → student** chains in Getty ULAN. Direction is normalized so `student of`, `apprentice of`, etc. point in the same direction as `teacher of`.\n\n");

$rank = 1;
foreach ($paths as $path) {
    $len  = count($path) - 1;
    $first = $names[$path[0]] ?? ('ULAN ' . $path[0]);
    $last  = $names[end($path)] ?? ('ULAN ' . end($path));
    $yrFirst = isset($years[$path[0]]) ? ' (b. ' . $years[$path[0]] . ')' : '';
    $yrLast  = isset($years[end($path)]) ? ' (b. ' . $years[end($path)] . ')' : '';
    fwrite($fh, "## #" . $rank++ . " — " . $len . " hops · " . $first . $yrFirst . " → " . $last . $yrLast . "\n\n");
    foreach ($path as $i => $u) {
        $name = $names[$u] ?? ('ULAN ' . $u);
        $yr   = isset($years[$u]) ? ' (b. ' . $years[$u] . ')' : '';
        $arrow = $i === 0 ? '  ' : '↓ ';
        fwrite($fh, '- ' . ($i === 0 ? '' : '↳ ') . '[' . $name . '](/?ulan=' . $u . ')' . $yr . "\n");
    }
    fwrite($fh, "\n");
}
fwrite($fh, build_sources_footer(['getty']));
fclose($fh);
logmsg('wrote ' . $out);
echo $out . "\n";
