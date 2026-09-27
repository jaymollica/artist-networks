<?php
/**
 * Concentration index — measures attention polarization over time.
 *
 *   A) Wikipedia pageview Gini month-by-month (last 24 months)
 *   B) Within-cohort polarization: per birth-decade, ratio of #1 to median
 *   C) Met display concentration: who holds the most on-view real estate
 *      and who has the most "shelved" works
 *
 *   php scripts/insight_concentration.php
 *
 * The empirical foundation for asking "is cumulative-advantage getting worse?"
 * and a baseline for any counter-canon recommendation work.
 */

declare(strict_types=1);
ini_set('memory_limit', '1G');

require_once __DIR__ . '/lib/non_artist_filter.php';
require_once __DIR__ . '/../lib/report_sources.php';

const DB_NAME      = 'artist_networks';
const DB_USER      = 'artist_networks';
const DB_PASS_FILE = '/etc/artist-networks/db_pass';

$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    trim((string)file_get_contents(DB_PASS_FILE)),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function logmsg(string $m): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $m . "\n"); }

logmsg('loading non-artist filter…');
setup_non_artist_filter($pdo);

logmsg('computing artist degrees…');
$pdo->exec("CREATE TEMPORARY TABLE _artist_degree AS
    SELECT u AS ulan, SUM(d) AS degree FROM (
        SELECT artist_ulan AS u, COUNT(*) AS d FROM artist_relationships GROUP BY artist_ulan
        UNION ALL
        SELECT related_ulan AS u, COUNT(*) AS d FROM artist_relationships GROUP BY related_ulan
    ) s GROUP BY u");
$pdo->exec('CREATE INDEX ix_d ON _artist_degree (ulan)');

/**
 * Gini coefficient on a list of non-negative numbers.
 * Returns 0 (perfectly equal) to 1 (one person has everything).
 */
function gini(array $values): float {
    $values = array_values(array_filter($values, fn($v) => $v >= 0));
    sort($values, SORT_NUMERIC);
    $n = count($values);
    if ($n === 0) return 0.0;
    $sum = array_sum($values);
    if ($sum <= 0) return 0.0;
    $weighted = 0.0;
    foreach ($values as $i => $v) $weighted += ($i + 1) * $v;
    return (2 * $weighted) / ($n * $sum) - ($n + 1) / $n;
}

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

$out = '/var/log/artist-networks/insight-concentration-' . date('Y-m-d') . '.md';
$fh  = fopen($out, 'w');
fwrite($fh, "# Concentration index — attention polarization tracker\n\n_" . date('c') . "_\n\n");
fwrite($fh, "Empirical measures of how concentrated cultural attention is across the dataset, and whether it's getting more so. Gini = 0 means perfectly equal attention; Gini = 1 means a single artist absorbs all attention.\n\n");

// ------------------------------------------------------------------
// Section A — Pageview Gini over time
// ------------------------------------------------------------------
logmsg('computing monthly Gini on pageviews…');
fwrite($fh, "## Section A — Wikipedia pageview Gini, monthly\n\n");
fwrite($fh, "Computed across all artists with a Wikipedia page in our dataset, excluding royals/institutions. A rising Gini means English Wikipedia attention is concentrating further into fewer hands.\n\n");
fwrite($fh, "| Month | Artists | Total views | Gini | Top-1% share | Top-10% share |\n");
fwrite($fh, "|---|---:|---:|---:|---:|---:|\n");

$sql = "SELECT pv.month, GROUP_CONCAT(pv.views) AS views_csv, COUNT(*) AS n, SUM(pv.views) AS total
        FROM wiki_pageviews pv
        WHERE pv.ulan NOT IN (SELECT ulan FROM _non_artist_ulans)
        GROUP BY pv.month
        ORDER BY pv.month";
// GROUP_CONCAT has a default 1024-char limit; bump it for this run.
$pdo->exec("SET SESSION group_concat_max_len = 134217728");
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$giniSeries = [];
foreach ($rows as $r) {
    $views = array_map('intval', explode(',', $r['views_csv']));
    sort($views);
    $n = count($views);
    $total = array_sum($views);
    $g = gini($views);
    $top1cut  = (int)floor($n * 0.99);
    $top10cut = (int)floor($n * 0.90);
    $top1Share  = $total > 0 ? array_sum(array_slice($views, $top1cut))  / $total : 0;
    $top10Share = $total > 0 ? array_sum(array_slice($views, $top10cut)) / $total : 0;
    $giniSeries[] = ['month' => $r['month'], 'gini' => $g, 'top1' => $top1Share, 'top10' => $top10Share];
    fwrite($fh, sprintf("| %s | %s | %s | %.4f | %.1f%% | %.1f%% |\n",
        $r['month'],
        number_format($n),
        number_format($total),
        $g,
        $top1Share * 100,
        $top10Share * 100
    ));
}

if (count($giniSeries) >= 6) {
    $first = $giniSeries[0];
    $last  = end($giniSeries);
    $delta = $last['gini'] - $first['gini'];
    $sign  = $delta > 0 ? 'concentrating' : ($delta < 0 ? 'spreading' : 'flat');
    fwrite($fh, sprintf("\n_Δ Gini over the window: **%+.4f** (%s). Top-1%% share went from %.1f%% → %.1f%%._\n\n",
        $delta, $sign, $first['top1'] * 100, $last['top1'] * 100));
}

// ------------------------------------------------------------------
// Section B — Within-cohort polarization (by birth decade)
// ------------------------------------------------------------------
logmsg('computing within-cohort polarization…');
fwrite($fh, "## Section B — Within-cohort polarization, by birth decade\n\n");
fwrite($fh, "For each birth-decade cohort with ≥25 artists who have Wikipedia data, the ratio of the #1 artist's recent pageviews to the cohort median. High ratios = one or two names dominate the era's attention; low ratios = attention is spread.\n\n");
fwrite($fh, "| Decade | Cohort size | Top-1 artist | Top views/mo | Median views/mo | Top ÷ median |\n");
fwrite($fh, "|---:|---:|---|---:|---:|---:|\n");

$sql = "SELECT FLOOR(b.birth_year / 10) * 10 AS decade,
               s.ulan, s.avg_recent_views
        FROM wiki_pageview_stats s
        JOIN biographies b ON b.ulan = s.ulan AND b.preferred = 1
        WHERE b.birth_year BETWEEN 1500 AND 2010
          AND s.avg_recent_views > 0
          AND s.ulan NOT IN (SELECT ulan FROM _non_artist_ulans)
        ORDER BY decade, s.avg_recent_views DESC";
$byDecade = [];
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $byDecade[(int)$r['decade']][] = ['ulan' => (int)$r['ulan'], 'views' => (int)$r['avg_recent_views']];
}
ksort($byDecade);
foreach ($byDecade as $decade => $cohort) {
    if (count($cohort) < 25) continue;
    $views = array_column($cohort, 'views');
    sort($views);
    $median = $views[(int)floor(count($views) / 2)];
    $top = $cohort[0]; // already sorted DESC by views in SQL within decade
    $ratio = $median > 0 ? $top['views'] / $median : 0;
    fwrite($fh, sprintf("| %ds | %d | [%s](/?ulan=%d) | %s | %s | %.1f× |\n",
        $decade, count($cohort),
        nameOf($pdo, $top['ulan']), $top['ulan'],
        number_format($top['views']),
        number_format($median),
        $ratio
    ));
}
fwrite($fh, "\n");

// ------------------------------------------------------------------
// Section C — Met display concentration
// ------------------------------------------------------------------
logmsg('computing Met display concentration…');
fwrite($fh, "## Section C — Met display concentration\n\n");
fwrite($fh, "Of all Met works currently on view (`gallery_number` set), which artists hold the most display slots? Below: top 20 artists by on-view share, then the bottom: high-degree artists who have ≥10 works in the Met collection but zero on view right now.\n\n");

// Top artists by on-view count
fwrite($fh, "### Most-displayed artists\n\n");
fwrite($fh, "| Artist | Works on view | Total works | % on view |\n");
fwrite($fh, "|---|---:|---:|---:|\n");

$sql = "SELECT m.ulan,
               SUM(CASE WHEN m.gallery_number IS NOT NULL THEN 1 ELSE 0 END) AS on_view,
               COUNT(*) AS total
        FROM met_works m
        WHERE m.image_fetched_at IS NOT NULL
          AND m.ulan NOT IN (SELECT ulan FROM _non_artist_ulans)
        GROUP BY m.ulan
        HAVING on_view > 0
        ORDER BY on_view DESC
        LIMIT 20";
$totalOnView = 0;
foreach ($pdo->query("SELECT COUNT(*) FROM met_works WHERE gallery_number IS NOT NULL AND image_fetched_at IS NOT NULL")->fetch(PDO::FETCH_NUM) as $v) $totalOnView = (int)$v;

foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $pct = $r['total'] > 0 ? round(100 * $r['on_view'] / $r['total']) : 0;
    fwrite($fh, sprintf("| [%s](/?ulan=%d) | %d | %d | %d%% |\n",
        nameOf($pdo, (int)$r['ulan']), $r['ulan'],
        (int)$r['on_view'], (int)$r['total'], $pct
    ));
}
fwrite($fh, "\n_Total works on view across our cataloged set: " . number_format($totalOnView) . "._\n\n");

// Shelved heavyweights: high-degree artists with works in collection but none on view
fwrite($fh, "### Shelved heavyweights — high-degree artists, ≥10 works in collection, zero on view\n\n");
fwrite($fh, "| Artist | Degree | Works in collection | Works on view |\n");
fwrite($fh, "|---|---:|---:|---:|\n");
$sql = "SELECT m.ulan, ad.degree, COUNT(*) AS total,
               SUM(CASE WHEN m.gallery_number IS NOT NULL THEN 1 ELSE 0 END) AS on_view
        FROM met_works m
        JOIN _artist_degree ad ON ad.ulan = m.ulan
        WHERE m.image_fetched_at IS NOT NULL
          AND m.ulan NOT IN (SELECT ulan FROM _non_artist_ulans)
        GROUP BY m.ulan, ad.degree
        HAVING total >= 10 AND on_view = 0
        ORDER BY ad.degree DESC
        LIMIT 20";
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    fwrite($fh, sprintf("| [%s](/?ulan=%d) | %d | %d | %d |\n",
        nameOf($pdo, (int)$r['ulan']), $r['ulan'],
        (int)$r['degree'], (int)$r['total'], (int)$r['on_view']
    ));
}
fwrite($fh, "\n");

fwrite($fh, build_sources_footer(['getty', 'wikipedia_pv', 'met_api']));
fclose($fh);
logmsg('wrote ' . $out);
echo $out . "\n";
