<?php
/**
 * Nepo babies — artists who seem to have benefited from family connections in
 * the Getty graph.
 *
 *   A) Children of titans: artists whose parent is a high-degree, well-known
 *      figure in the Getty network.
 *   B) Multi-generational dynasties: family clusters spanning 3+ generations.
 *   C) Marrying into the canon: spouse pairs with a large fame gap.
 *
 *   php scripts/insight_nepo.php
 */

declare(strict_types=1);
ini_set('memory_limit', '1G');

require_once __DIR__ . '/lib/non_artist_filter.php';
require_once __DIR__ . '/../lib/report_sources.php';

const DB_NAME      = 'artist_networks';
const DB_USER      = 'artist_networks';
const DB_PASS_FILE = '/root/.artist_networks_db_pass';

// Family edge types (bidirectional pairs collapse naturally — we use either side).
const FAMILY_TYPES = [1501, 1511, 1512, 1513, 1514, 1515, 1516, 1521, 1531, 1532,
                      1541, 1550, 1551, 1552, 1553, 1554, 1555, 1556, 1557,
                      1561, 1562, 1574, 1575];
const CHILD_OF      = 1511;
const PARENT_OF     = 1512;
const SPOUSE_OF     = 1541;

$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    trim((string)file_get_contents(DB_PASS_FILE)),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function logmsg(string $m): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $m . "\n"); }

// ------------------------------------------------------------------
// Degree + name helpers
// ------------------------------------------------------------------
logmsg('computing artist degrees…');
$pdo->exec("CREATE TEMPORARY TABLE _artist_degree AS
    SELECT u AS ulan, SUM(d) AS degree FROM (
        SELECT artist_ulan AS u, COUNT(*) AS d FROM artist_relationships GROUP BY artist_ulan
        UNION ALL
        SELECT related_ulan AS u, COUNT(*) AS d FROM artist_relationships GROUP BY related_ulan
    ) s GROUP BY u");
$pdo->exec('CREATE INDEX ix_d ON _artist_degree (ulan)');

$nameCache = [];
$bioCache  = [];
function nameOf(PDO $pdo, int $u, array &$cache): string {
    if (isset($cache[$u])) return $cache[$u];
    $st = $pdo->prepare("SELECT alias FROM artist_aliases WHERE ulan=? AND id=(SELECT id FROM artist_aliases a2 WHERE a2.ulan=? ORDER BY a2.display DESC, a2.preferred DESC, a2.id ASC LIMIT 1)");
    $st->execute([$u, $u]);
    $n = (string)($st->fetchColumn() ?: ('ULAN ' . $u));
    $parts = array_map('trim', explode(',', $n));
    if (count($parts) >= 2) { $f = array_shift($parts); $n = implode(' ', $parts) . ' ' . $f; }
    return $cache[$u] = $n;
}
function bioYears(PDO $pdo, int $u, array &$cache): array {
    if (isset($cache[$u])) return $cache[$u];
    $st = $pdo->prepare("SELECT MAX(birth_year) AS yr_birth, MAX(death_year) AS yr_death FROM biographies WHERE ulan=? AND preferred=1");
    $st->execute([$u]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['yr_birth' => null, 'yr_death' => null];
    return $cache[$u] = ['birth' => $r['yr_birth'] ?: null, 'death' => $r['yr_death'] ?: null];
}
$thisYear = (int)date('Y');
function dateRange(?int $b, ?int $d, int $thisYear): string {
    if (!$b && !$d) return '';
    // Hide nonsense death years (open-ended bio entries get $thisYear + life-expectancy).
    if ($d !== null && $d > $thisYear) $d = null;
    return ' (' . ($b ?? '?') . '–' . ($d ?? '?') . ')';
}

logmsg('loading non-artist filter…');
$excludedUlans = setup_non_artist_filter($pdo);
logmsg('excluded ULANs: ' . count($excludedUlans));

$out = '/var/log/artist-networks/insight-nepo-' . date('Y-m-d') . '.md';
$fh  = fopen($out, 'w');
fwrite($fh, "# Nepo babies — artists who benefited from family\n\n_" . date('c') . "_\n\n");
fwrite($fh, "Artists whose Getty position is materially shaped by a family connection. Three lenses: children with prominent artist-parents, multi-generational dynasties, and spouses who married into prominence.\n\n");
fwrite($fh, "**Caveat.** Some of these are real generational talents (Bellini, Brueghel, Wyeth). Family connection ≠ undeserved success; this report just surfaces *where* a family signal exists.\n\n");

// ------------------------------------------------------------------
// Section A — Children of titans
// ------------------------------------------------------------------
fwrite($fh, "## Section A — Children of titans\n\n");
fwrite($fh, "Artists with at least one parent who is themselves an artist with a high Getty degree. `Parent fame` = parent's Getty degree × log(parent's Wikipedia views + 1). Ranked by sum of parent fame.\n\n");
fwrite($fh, "| Rank | Parent fame | Child | Parent(s) | Child degree | Years |\n");
fwrite($fh, "|---:|---:|---|---|---:|---|\n");

// child→parent rows
$sql = "SELECT ar.artist_ulan AS child, ar.related_ulan AS parent,
               cd.degree AS child_deg, pd.degree AS parent_deg,
               COALESCE(pv.avg_recent_views, 0) AS parent_views
        FROM artist_relationships ar
        JOIN artist_aliases ac ON ac.ulan = ar.artist_ulan  AND ac.preferred = 1
        JOIN artist_aliases ap ON ap.ulan = ar.related_ulan AND ap.preferred = 1
        LEFT JOIN _artist_degree cd ON cd.ulan = ar.artist_ulan
        LEFT JOIN _artist_degree pd ON pd.ulan = ar.related_ulan
        LEFT JOIN wiki_pageview_stats pv ON pv.ulan = ar.related_ulan
        WHERE ar.relationship_type = " . CHILD_OF;
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Aggregate per child: sum parent fame, collect parent ULANs
$kids = [];
foreach ($rows as $r) {
    $cu = (int)$r['child']; $pu = (int)$r['parent'];
    $pd = (int)$r['parent_deg'];
    $pv = (float)$r['parent_views'];
    $pFame = $pd * (1 + log($pv + 1));
    $kids[$cu] = $kids[$cu] ?? ['child_deg' => (int)$r['child_deg'], 'parent_fame' => 0.0, 'parents' => []];
    $kids[$cu]['parent_fame'] += $pFame;
    $kids[$cu]['parents'][$pu] = ['deg' => $pd, 'views' => $pv];
}
uasort($kids, fn($a, $b) => $b['parent_fame'] <=> $a['parent_fame']);
$rank = 1;
foreach ($kids as $cu => $info) {
    if ($rank > 40) break;
    if ($info['parent_fame'] < 1) continue;
    if (isset($excludedUlans[$cu])) continue;
    // also reject if every parent is excluded — nothing to show
    $cleanParents = array_filter($info['parents'], fn($_, $pu) => !isset($excludedUlans[$pu]), ARRAY_FILTER_USE_BOTH);
    if (!$cleanParents) continue;
    $childName = nameOf($pdo, $cu, $nameCache);
    $childYrs  = bioYears($pdo, $cu, $bioCache);
    $parts = [];
    foreach ($cleanParents as $pu => $p) {
        $pn = nameOf($pdo, $pu, $nameCache);
        $py = bioYears($pdo, $pu, $bioCache);
        $parts[] = sprintf('[%s](/?ulan=%d)%s — deg %d', $pn, $pu, dateRange($py['birth'], $py['death'], $thisYear), $p['deg']);
    }
    fwrite($fh, sprintf(
        "| %d | %.0f | [%s](/?ulan=%d) | %s | %d | %s |\n",
        $rank++, $info['parent_fame'],
        $childName, $cu,
        implode('<br>', $parts),
        $info['child_deg'] ?? 0,
        trim(dateRange($childYrs['birth'], $childYrs['death'], $thisYear), ' ()')
    ));
}
fwrite($fh, "\n");

// ------------------------------------------------------------------
// Section B — Multi-generational dynasties (union-find on family edges)
// ------------------------------------------------------------------
fwrite($fh, "## Section B — Multi-generational dynasties\n\n");
fwrite($fh, "Connected components of the family-only subgraph with 4+ members spanning a wide year range. Same family, multiple recorded artists.\n\n");

$fam = $pdo->query("SELECT artist_ulan AS a, related_ulan AS b FROM artist_relationships
                    WHERE relationship_type IN (" . implode(',', FAMILY_TYPES) . ")")->fetchAll(PDO::FETCH_NUM);

$parent = [];
function root(int $x, array &$p): int { while (isset($p[$x]) && $p[$x] !== $x) { $p[$x] = $p[$p[$x]] ?? $p[$x]; $x = $p[$x]; } return $x; }
function uniteIt(int $x, int $y, array &$p): void { $rx = root($x, $p); $ry = root($y, $p); if ($rx !== $ry) $p[$rx] = $ry; }

foreach ($fam as [$a, $b]) {
    $a = (int)$a; $b = (int)$b;
    // Skip edges that touch any non-artist ULAN — keeps the dynasty graph clean.
    if (isset($excludedUlans[$a]) || isset($excludedUlans[$b])) continue;
    if (!isset($parent[$a])) $parent[$a] = $a;
    if (!isset($parent[$b])) $parent[$b] = $b;
    uniteIt($a, $b, $parent);
}
$comps = [];
foreach (array_keys($parent) as $u) {
    $r = root($u, $parent);
    $comps[$r][] = $u;
}
$dynasties = [];
foreach ($comps as $members) {
    if (count($members) < 4) continue;
    $minB = null; $maxB = null;
    foreach ($members as $u) {
        $yy = bioYears($pdo, $u, $bioCache);
        if ($yy['birth']) {
            if ($minB === null || $yy['birth'] < $minB) $minB = $yy['birth'];
            if ($maxB === null || $yy['birth'] > $maxB) $maxB = $yy['birth'];
        }
    }
    $span = ($minB && $maxB) ? ($maxB - $minB) : 0;
    $dynasties[] = ['members' => $members, 'size' => count($members), 'span' => $span, 'min_b' => $minB, 'max_b' => $maxB];
}
usort($dynasties, fn($a, $b) => ($b['size'] * 50 + $b['span']) <=> ($a['size'] * 50 + $a['span']));
fwrite($fh, "| Rank | Members | Year span | Family |\n");
fwrite($fh, "|---:|---:|---|---|\n");
$rank = 1;
foreach (array_slice($dynasties, 0, 20) as $d) {
    $names = array_map(function ($u) use (&$nameCache, &$bioCache, $pdo, $thisYear) {
        $n = nameOf($pdo, $u, $nameCache);
        $yy = bioYears($pdo, $u, $bioCache);
        return sprintf('[%s](/?ulan=%d)%s', $n, $u, dateRange($yy['birth'], $yy['death'], $thisYear));
    }, $d['members']);
    $yrs = ($d['min_b'] && $d['max_b']) ? ($d['min_b'] . '–' . $d['max_b']) : '?';
    fwrite($fh, sprintf("| %d | %d | %s | %s |\n",
        $rank++, $d['size'], $yrs, implode('; ', $names)));
}
fwrite($fh, "\n");

// ------------------------------------------------------------------
// Section C — Marrying into the canon
// ------------------------------------------------------------------
fwrite($fh, "## Section C — Marrying into the canon\n\n");
fwrite($fh, "Spouse pairs with a large gap in Getty degree. The lower-degree partner's network position may have been substantially shaped by the marriage.\n\n");
fwrite($fh, "| Δ degree | Higher-degree partner | Lower-degree partner | Higher deg | Lower deg |\n");
fwrite($fh, "|---:|---|---|---:|---:|\n");

$sql = "SELECT ar.artist_ulan AS a, ar.related_ulan AS b,
               COALESCE(ad.degree, 0) AS deg_a, COALESCE(bd.degree, 0) AS deg_b
        FROM artist_relationships ar
        LEFT JOIN _artist_degree ad ON ad.ulan = ar.artist_ulan
        LEFT JOIN _artist_degree bd ON bd.ulan = ar.related_ulan
        WHERE ar.relationship_type = " . SPOUSE_OF . "
          AND ar.artist_ulan < ar.related_ulan"; // dedupe bidir
$spouses = [];
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $ah = max((int)$r['deg_a'], (int)$r['deg_b']);
    $al = min((int)$r['deg_a'], (int)$r['deg_b']);
    if ($ah - $al < 20) continue;
    $hi = ($r['deg_a'] >= $r['deg_b']) ? (int)$r['a'] : (int)$r['b'];
    $lo = ($r['deg_a'] >= $r['deg_b']) ? (int)$r['b'] : (int)$r['a'];
    $spouses[] = ['hi' => $hi, 'lo' => $lo, 'deg_hi' => $ah, 'deg_lo' => $al, 'diff' => $ah - $al];
}
usort($spouses, fn($a, $b) => $b['diff'] <=> $a['diff']);
$shown = 0;
foreach ($spouses as $s) {
    if ($shown >= 25) break;
    if (isset($excludedUlans[$s['hi']]) || isset($excludedUlans[$s['lo']])) continue;
    $hiName = nameOf($pdo, $s['hi'], $nameCache);
    $loName = nameOf($pdo, $s['lo'], $nameCache);
    $hiYrs = bioYears($pdo, $s['hi'], $bioCache);
    $loYrs = bioYears($pdo, $s['lo'], $bioCache);
    fwrite($fh, sprintf("| %d | [%s](/?ulan=%d)%s | [%s](/?ulan=%d)%s | %d | %d |\n",
        $s['diff'],
        $hiName, $s['hi'], dateRange($hiYrs['birth'], $hiYrs['death'], $thisYear),
        $loName, $s['lo'], dateRange($loYrs['birth'], $loYrs['death'], $thisYear),
        $s['deg_hi'], $s['deg_lo']
    ));
    $shown++;
}
fwrite($fh, "\n");

fwrite($fh, build_sources_footer(['getty']));
fclose($fh);
logmsg('wrote ' . $out);
echo $out . "\n";
