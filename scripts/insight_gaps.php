<?php
/**
 * Two-part gap analysis:
 *   (A) Popularity vs degree — artists with high Wikipedia readership but low
 *       Getty connectivity. Strongest candidates for "Getty is missing data."
 *   (B) Asymmetric mentorship audit — pairs Getty has recorded in one
 *       direction (teacher_of) but not the other (student_of), or vice versa.
 *       Suggests data-quality errors.
 *
 *   php scripts/insight_gaps.php [--top=50]
 */

declare(strict_types=1);
ini_set('memory_limit', '2G');

require_once __DIR__ . '/lib/non_artist_filter.php';
require_once __DIR__ . '/../lib/report_sources.php';

$opts = getopt('', ['top::']);
$topN = isset($opts['top']) ? (int)$opts['top'] : 50;

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

$nameCache = [];
function nameOf(PDO $pdo, int $u, array &$cache): string {
    if (isset($cache[$u])) return $cache[$u];
    $stmt = $pdo->prepare("SELECT a.alias FROM artist_aliases a
        WHERE a.ulan=? AND a.id=(SELECT a2.id FROM artist_aliases a2
            WHERE a2.ulan=a.ulan ORDER BY a2.display DESC, a2.preferred DESC, a2.id ASC LIMIT 1)");
    $stmt->execute([$u]);
    $n = (string)($stmt->fetchColumn() ?: ('ULAN ' . $u));
    $parts = array_map('trim', explode(',', $n));
    if (count($parts) >= 2) { $f = array_shift($parts); $n = implode(' ', $parts) . ' ' . $f; }
    return $cache[$u] = $n;
}

// ------------------------------------------------------------------
// (A) Popularity-vs-degree gap
// ------------------------------------------------------------------
logmsg('computing popularity-vs-degree gap…');

$pdo->exec("CREATE TEMPORARY TABLE _artist_degree AS
    SELECT u AS ulan, SUM(d) AS degree FROM (
        SELECT artist_ulan AS u, COUNT(*) AS d FROM artist_relationships GROUP BY artist_ulan
        UNION ALL
        SELECT related_ulan AS u, COUNT(*) AS d FROM artist_relationships GROUP BY related_ulan
    ) s GROUP BY u");
$pdo->exec('CREATE INDEX ix1 ON _artist_degree (ulan)');

logmsg('loading non-artist filter…');
setup_non_artist_filter($pdo);

$sql = "
    WITH disp AS (
        SELECT a.ulan, a.alias AS name
        FROM artist_aliases a
        WHERE a.id = (
            SELECT a2.id FROM artist_aliases a2
            WHERE a2.ulan = a.ulan
            ORDER BY a2.display DESC, a2.preferred DESC, a2.id ASC LIMIT 1
        )
    )
    SELECT pv.ulan, pv.avg_recent_views, pv.trend_ratio, COALESCE(ad.degree, 0) AS degree,
           LOG10(pv.avg_recent_views + 1) - 1.5 * LOG10(COALESCE(ad.degree, 0) + 1) AS gap
    FROM wiki_pageview_stats pv
    LEFT JOIN _artist_degree ad ON ad.ulan = pv.ulan
    JOIN disp d ON d.ulan = pv.ulan
    WHERE pv.avg_recent_views >= 500
      AND COALESCE(ad.degree, 0) BETWEEN 3 AND 15
      AND pv.ulan NOT IN (SELECT ulan FROM _non_artist_ulans)
    ORDER BY gap DESC
    LIMIT " . ($topN * 2);
$gapRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------------------------------------------
// (B) Asymmetric mentorship audit
// ------------------------------------------------------------------
logmsg('computing asymmetric mentorship pairs…');

// Reciprocal pairs (Getty relationship_type → its inverse).
$RECIPROCAL = [
    1101 => 1102,  // teacher of  ↔ student of
    1111 => 1112,  // master of   ↔ master was (student)
    1105 => 1106,  // apprentice of ↔ apprentice was
];

$pdo->exec("CREATE TEMPORARY TABLE _asym_edges AS
    SELECT artist_ulan, related_ulan, relationship_type FROM artist_relationships
    WHERE relationship_type IN (1101,1102,1105,1106,1111,1112)");
$pdo->exec('CREATE INDEX ix2 ON _asym_edges (artist_ulan, related_ulan, relationship_type)');

$asymCounts = []; // 'forward'|'reverse'|'matched' => count, per type pair
$missingReverse = []; // [forwardType => [[from, to], ...]]

foreach ($RECIPROCAL as $fwd => $rev) {
    $pdo->exec("CREATE TEMPORARY TABLE _fwd AS
        SELECT artist_ulan AS a, related_ulan AS b FROM _asym_edges WHERE relationship_type=$fwd");
    $pdo->exec("CREATE TEMPORARY TABLE _rev AS
        SELECT related_ulan AS a, artist_ulan AS b FROM _asym_edges WHERE relationship_type=$rev");
    $pdo->exec('CREATE INDEX ix_f ON _fwd (a, b)');
    $pdo->exec('CREATE INDEX ix_r ON _rev (a, b)');

    $fwdCount = (int)$pdo->query('SELECT COUNT(*) FROM _fwd')->fetchColumn();
    $revCount = (int)$pdo->query('SELECT COUNT(*) FROM _rev')->fetchColumn();
    $matched  = (int)$pdo->query('SELECT COUNT(*) FROM _fwd f JOIN _rev r USING (a, b)')->fetchColumn();
    $missingRev = $fwdCount - $matched;  // forward exists, reverse doesn't
    $missingFwd = $revCount - $matched;  // reverse exists, forward doesn't

    $asymCounts[$fwd] = [
        'fwd' => $fwdCount, 'rev' => $revCount,
        'matched' => $matched,
        'missing_reverse' => $missingRev,
        'missing_forward' => $missingFwd,
    ];

    // Pick examples of missing-reverse pairs (forward exists, no reverse)
    $exFwd = $pdo->query("SELECT f.a, f.b FROM _fwd f
        LEFT JOIN _rev r ON r.a=f.a AND r.b=f.b
        WHERE r.a IS NULL LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    $missingReverse[$fwd] = $exFwd;

    $pdo->exec('DROP TEMPORARY TABLE _fwd');
    $pdo->exec('DROP TEMPORARY TABLE _rev');
}

// ------------------------------------------------------------------
// Write report
// ------------------------------------------------------------------
$relNames = $pdo->query('SELECT getty_id, relationship FROM relationship_types')->fetchAll(PDO::FETCH_KEY_PAIR);

$out = '/var/log/artist-networks/insight-gaps-' . date('Y-m-d') . '.md';
$fh  = fopen($out, 'w');
fwrite($fh, "# Gaps & anomalies\n\n_" . date('c') . "_\n\n");
fwrite($fh, "Two analyses surfacing where Getty's data is likely incomplete.\n\n");

// Section A
fwrite($fh, "## Section A — Famous on Wikipedia, sparse in Getty\n\n");
fwrite($fh, "Limited to artists with Getty degree **between 3 and 15** — i.e. Getty has them, but only thinly. Score is `log₁₀(avg_recent_monthly_views) − 1.5·log₁₀(getty_degree+1)`. High score = high Wikipedia readership relative to Getty connectivity. These are the strongest candidates for *missing Getty relationships*.\n\n");
fwrite($fh, "| Rank | Score | Views/mo | Getty degree | Artist |\n");
fwrite($fh, "|---:|---:|---:|---:|---|\n");
$rank = 1;
foreach ($gapRows as $r) {
    if ($rank > $topN) break;
    $artist = nameOf($pdo, (int)$r['ulan'], $nameCache);
    fwrite($fh, sprintf("| %d | %.2f | %s | %d | [%s](/?ulan=%d) |\n",
        $rank,
        (float)$r['gap'],
        number_format((float)$r['avg_recent_views']),
        (int)$r['degree'],
        $artist,
        (int)$r['ulan']
    ));
    $rank++;
}
fwrite($fh, "\n");

// Section B
fwrite($fh, "## Section B — Asymmetric mentorship pairs\n\n");
fwrite($fh, "Getty stores mentorship in both directions (e.g. `teacher of` ↔ `student of`). One-directional records are likely data gaps.\n\n");
fwrite($fh, "| Relationship pair | Forward edges | Reverse edges | Both present | Missing reverse | Missing forward |\n");
fwrite($fh, "|---|---:|---:|---:|---:|---:|\n");
foreach ($asymCounts as $fwd => $c) {
    $rev = $RECIPROCAL[$fwd];
    fwrite($fh, sprintf("| `%s` ↔ `%s` | %d | %d | %d | %d | %d |\n",
        $relNames[$fwd] ?? "type $fwd",
        $relNames[$rev] ?? "type $rev",
        $c['fwd'], $c['rev'], $c['matched'], $c['missing_reverse'], $c['missing_forward']
    ));
}
fwrite($fh, "\n");

$hasUnmatched = false;
foreach ($asymCounts as $c) if ($c['missing_reverse'] || $c['missing_forward']) { $hasUnmatched = true; break; }
if (!$hasUnmatched) {
    fwrite($fh, "_All mentorship relationships are perfectly reciprocated — Getty's data quality is clean here._\n\n");
}

foreach ($missingReverse as $fwd => $examples) {
    if (!$examples) continue;
    $fwdName = $relNames[$fwd] ?? "type $fwd";
    $revName = $relNames[$RECIPROCAL[$fwd]] ?? "reciprocal";
    fwrite($fh, "### Examples — `$fwdName` recorded but `$revName` missing\n\n");
    fwrite($fh, "| Artist A | (Getty says A is $fwdName) | Artist B |\n");
    fwrite($fh, "|---|---|---|\n");
    foreach (array_slice($examples, 0, 20) as $ex) {
        $a = nameOf($pdo, (int)$ex['a'], $nameCache);
        $b = nameOf($pdo, (int)$ex['b'], $nameCache);
        fwrite($fh, sprintf("| [%s](/?ulan=%d) | $fwdName | [%s](/?ulan=%d) |\n",
            $a, (int)$ex['a'], $b, (int)$ex['b']));
    }
    fwrite($fh, "\n");
}

// ------------------------------------------------------------------
// (C) Met-collected but Getty-isolated
// ------------------------------------------------------------------
logmsg('computing Met-collected but Getty-isolated…');

fwrite($fh, "## Section C — Met-collected but Getty-isolated\n\n");
fwrite($fh, "Artists with Met holdings but very low Getty connectivity (degree ≤ 3). Significant enough for the Met to collect, but Getty hasn't linked them into the social graph.\n\n");
fwrite($fh, "| Met works | Getty degree | Wiki views/mo | Artist |\n");
fwrite($fh, "|---:|---:|---:|---|\n");

$sql = "SELECT m.ulan, COUNT(*) AS work_count, COALESCE(ad.degree, 0) AS degree, COALESCE(pv.avg_recent_views, 0) AS views
        FROM met_works m
        LEFT JOIN _artist_degree ad ON ad.ulan = m.ulan
        LEFT JOIN wiki_pageview_stats pv ON pv.ulan = m.ulan
        GROUP BY m.ulan
        HAVING degree <= 3
        ORDER BY work_count DESC, views DESC
        LIMIT 30";
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $artist = nameOf($pdo, (int)$r['ulan'], $nameCache);
    fwrite($fh, sprintf("| %d | %d | %s | [%s](/?ulan=%d) |\n",
        (int)$r['work_count'], (int)$r['degree'],
        number_format((float)$r['views']),
        $artist, (int)$r['ulan']));
}
fwrite($fh, "\n");

fwrite($fh, build_sources_footer(['getty', 'wikipedia_pv', 'met_api']));
fclose($fh);
logmsg('wrote ' . $out);
echo $out . "\n";
