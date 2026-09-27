<?php
/**
 * Sleeper insight — artists whose Wikipedia *edit activity* is accelerating
 * (recent 6 months running well above the prior 18-month baseline), often a
 * leading indicator of scholarly attention before popular pageviews catch up.
 *
 * Sections:
 *   A) Rising stars — high accel_ratio with real edit volume
 *   B) Cross-cultural reach — high language count relative to en-pageviews
 *   C) Decelerating canon — established names with falling edit attention
 *
 *   php scripts/insight_sleeper.php
 */

declare(strict_types=1);
ini_set('memory_limit', '1G');

require_once __DIR__ . '/lib/non_artist_filter.php';
require_once __DIR__ . '/../lib/report_sources.php';

const DB_NAME      = 'artist_networks';
const DB_USER      = 'artist_networks';
const DB_PASS_FILE = '/root/.artist_networks_db_pass';

$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    trim((string)file_get_contents(DB_PASS_FILE)),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function logmsg(string $m): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $m . "\n"); }

logmsg('computing artist degrees…');
$pdo->exec("CREATE TEMPORARY TABLE _artist_degree AS
    SELECT u AS ulan, SUM(d) AS degree FROM (
        SELECT artist_ulan AS u, COUNT(*) AS d FROM artist_relationships GROUP BY artist_ulan
        UNION ALL
        SELECT related_ulan AS u, COUNT(*) AS d FROM artist_relationships GROUP BY related_ulan
    ) s GROUP BY u");
$pdo->exec('CREATE INDEX ix_d ON _artist_degree (ulan)');

logmsg('loading non-artist filter…');
setup_non_artist_filter($pdo);

function nameOf(PDO $pdo, int $u): string {
    static $cache = [];
    if (isset($cache[$u])) return $cache[$u];
    $st = $pdo->prepare("SELECT alias FROM artist_aliases WHERE ulan=? AND id=(SELECT id FROM artist_aliases a2 WHERE a2.ulan=? ORDER BY a2.display DESC, a2.preferred DESC, a2.id ASC LIMIT 1)");
    $st->execute([$u, $u]);
    $n = (string)($st->fetchColumn() ?: ('ULAN ' . $u));
    $parts = array_map('trim', explode(',', $n));
    if (count($parts) >= 2) { $f = array_shift($parts); $n = implode(' ', $parts) . ' ' . $f; }
    return $cache[$u] = $n;
}

$out = '/var/log/artist-networks/insight-sleeper-' . date('Y-m-d') . '.md';
$fh  = fopen($out, 'w');
fwrite($fh, "# Sleeper signals — Wikipedia edit acceleration\n\n_" . date('c') . "_\n\n");
fwrite($fh, "Wikipedia *edit* activity (not pageviews) is a leading indicator of scholarly attention. Editors update articles when new research surfaces, exhibitions land, or reappraisals start. Pageviews follow later. This report tracks where edit activity is accelerating, where cross-cultural reach is wide, and where the canon is losing edit attention.\n\n");
fwrite($fh, "Window: last 24 months. **Acceleration ratio** = avg edits/month in last 6mo ÷ avg in months 7–24 (baseline floored at 0.25 to dampen low-traffic noise). **Languages** = number of interwiki Wikipedia versions for the en.wiki article.\n\n");

// -------------------------------------------------------------------------
// Section A — Rising stars
// -------------------------------------------------------------------------
fwrite($fh, "## Section A — Rising stars (edit acceleration)\n\n");
fwrite($fh, "Artists whose recent edit pace is well above their baseline, with enough total volume that the signal isn't noise.\n\n");
fwrite($fh, "| Rank | Accel | Recent edits/mo | Baseline edits/mo | Total | Langs | Degree | Artist |\n");
fwrite($fh, "|---:|---:|---:|---:|---:|---:|---:|---|\n");

$sql = "SELECT s.ulan, s.accel_ratio, s.avg_recent_edits, s.avg_baseline_edits, s.total_edits, s.languages,
               COALESCE(d.degree, 0) AS degree
        FROM wiki_edit_stats s
        JOIN artist_aliases a ON a.ulan = s.ulan AND a.id = (
            SELECT a2.id FROM artist_aliases a2 WHERE a2.ulan = s.ulan
            ORDER BY a2.display DESC, a2.preferred DESC, a2.id ASC LIMIT 1)
        LEFT JOIN _artist_degree d ON d.ulan = s.ulan
        WHERE s.total_edits >= 12
          AND s.avg_baseline_edits >= 0.3
          AND s.accel_ratio >= 1.5
          AND s.ulan NOT IN (SELECT ulan FROM _non_artist_ulans)
        ORDER BY s.accel_ratio DESC
        LIMIT 40";
$rank = 1;
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $name = nameOf($pdo, (int)$r['ulan']);
    fwrite($fh, sprintf("| %d | %.2f× | %.2f | %.2f | %d | %d | %d | [%s](/?ulan=%d) |\n",
        $rank++,
        (float)$r['accel_ratio'],
        (float)$r['avg_recent_edits'],
        (float)$r['avg_baseline_edits'],
        (int)$r['total_edits'],
        (int)$r['languages'],
        (int)$r['degree'],
        $name, (int)$r['ulan']
    ));
}
fwrite($fh, "\n");

// -------------------------------------------------------------------------
// Section B — Cross-cultural reach
// -------------------------------------------------------------------------
fwrite($fh, "## Section B — Cross-cultural reach\n\n");
fwrite($fh, "Artists with many interwiki Wikipedia versions but relatively low English-Wikipedia readership. They're reaching audiences en.wiki doesn't see. Filter: ≥25 languages, ≤5000 English views/mo, in our Getty graph (degree ≥ 3).\n\n");
fwrite($fh, "| Rank | Languages | En. views/mo | Langs ÷ ln(views) | Degree | Accel | Artist |\n");
fwrite($fh, "|---:|---:|---:|---:|---:|---:|---|\n");

$sql = "SELECT s.ulan, s.languages, s.accel_ratio,
               pv.avg_recent_views AS views,
               COALESCE(d.degree, 0) AS degree
        FROM wiki_edit_stats s
        JOIN wiki_pageview_stats pv ON pv.ulan = s.ulan
        JOIN _artist_degree d ON d.ulan = s.ulan
        JOIN artist_aliases a ON a.ulan = s.ulan AND a.id = (
            SELECT a2.id FROM artist_aliases a2 WHERE a2.ulan = s.ulan
            ORDER BY a2.display DESC, a2.preferred DESC, a2.id ASC LIMIT 1)
        WHERE s.languages >= 25
          AND pv.avg_recent_views BETWEEN 100 AND 5000
          AND d.degree >= 3
          AND s.ulan NOT IN (SELECT ulan FROM _non_artist_ulans)
        ORDER BY (s.languages / LN(pv.avg_recent_views + 1)) DESC
        LIMIT 30";
$rank = 1;
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $name = nameOf($pdo, (int)$r['ulan']);
    $views = (int)$r['views'];
    $reach = $views > 0 ? $r['languages'] / log($views + 1) : 0;
    fwrite($fh, sprintf("| %d | %d | %s | %.2f | %d | %.2f× | [%s](/?ulan=%d) |\n",
        $rank++,
        (int)$r['languages'],
        number_format($views),
        $reach,
        (int)$r['degree'],
        (float)$r['accel_ratio'],
        $name, (int)$r['ulan']
    ));
}
fwrite($fh, "\n");

// -------------------------------------------------------------------------
// Section C — Decelerating canon
// -------------------------------------------------------------------------
fwrite($fh, "## Section C — Decelerating canon\n\n");
fwrite($fh, "Heavily-edited canonical artists whose edit pace has fallen off. Could signal settled consensus, or losing critical attention.\n\n");
fwrite($fh, "| Rank | Accel | Recent edits/mo | Baseline edits/mo | Total | Langs | Artist |\n");
fwrite($fh, "|---:|---:|---:|---:|---:|---:|---|\n");

$sql = "SELECT s.ulan, s.accel_ratio, s.avg_recent_edits, s.avg_baseline_edits, s.total_edits, s.languages
        FROM wiki_edit_stats s
        JOIN artist_aliases a ON a.ulan = s.ulan AND a.id = (
            SELECT a2.id FROM artist_aliases a2 WHERE a2.ulan = s.ulan
            ORDER BY a2.display DESC, a2.preferred DESC, a2.id ASC LIMIT 1)
        WHERE s.total_edits >= 50
          AND s.accel_ratio <= 0.6
          AND s.ulan NOT IN (SELECT ulan FROM _non_artist_ulans)
        ORDER BY s.accel_ratio ASC
        LIMIT 25";
$rank = 1;
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $name = nameOf($pdo, (int)$r['ulan']);
    fwrite($fh, sprintf("| %d | %.2f× | %.2f | %.2f | %d | %d | [%s](/?ulan=%d) |\n",
        $rank++,
        (float)$r['accel_ratio'],
        (float)$r['avg_recent_edits'],
        (float)$r['avg_baseline_edits'],
        (int)$r['total_edits'],
        (int)$r['languages'],
        $name, (int)$r['ulan']
    ));
}
fwrite($fh, "\n");

fwrite($fh, build_sources_footer(['getty', 'wikipedia_edits', 'wikipedia_pv']));
fclose($fh);
logmsg('wrote ' . $out);
echo $out . "\n";
