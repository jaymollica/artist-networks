<?php
/**
 * Moneyball v0 — surfacing Met works by network-central artists that aren't
 * currently on view. Combines: Getty network degree + Met collection + onDisplay
 * status. Layers on Wikipedia pageview trends when available.
 *
 *   php scripts/insight_moneyball.php
 */

declare(strict_types=1);
ini_set('memory_limit', '1G');

require_once __DIR__ . '/../lib/report_sources.php';

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

logmsg('computing artist degrees…');
$pdo->exec("CREATE TEMPORARY TABLE artist_degree AS
    SELECT u AS ulan, SUM(d) AS degree FROM (
        SELECT artist_ulan AS u, COUNT(*) AS d FROM artist_relationships GROUP BY artist_ulan
        UNION ALL
        SELECT related_ulan AS u, COUNT(*) AS d FROM artist_relationships GROUP BY related_ulan
    ) s GROUP BY u");
$pdo->exec('CREATE INDEX idx_d ON artist_degree (ulan)');

// Detect presence of optional tables.
$hasPV    = (bool)$pdo->query("SHOW TABLES LIKE 'wiki_pageview_stats'")->fetchColumn();
$hasEdits = (bool)$pdo->query("SHOW TABLES LIKE 'wiki_edit_stats'")->fetchColumn();
$hasLink  = (bool)$pdo->query("SHOW TABLES LIKE 'wiki_links'")->fetchColumn();
logmsg('wiki_pageview_stats: ' . ($hasPV ? 'yes' : 'no') . ', wiki_edit_stats: ' . ($hasEdits ? 'yes' : 'no'));

$pvJoin    = $hasPV    ? "LEFT JOIN wiki_pageview_stats pv ON pv.ulan = m.ulan" : "";
$pvSel     = $hasPV    ? ", pv.avg_recent_views AS pv, pv.trend_ratio AS trend" : ", 0 AS pv, 1.0 AS trend";
$edJoin    = $hasEdits ? "LEFT JOIN wiki_edit_stats es ON es.ulan = m.ulan" : "";
$edSel     = $hasEdits ? ", es.accel_ratio AS accel, es.languages AS langs" : ", 1.0 AS accel, 0 AS langs";

// helper to get display-form name
function name($pdo, int $ulan): string {
    static $cache = [];
    if (isset($cache[$ulan])) return $cache[$ulan];
    $st = $pdo->prepare("SELECT alias FROM artist_aliases WHERE ulan=? AND id=(SELECT id FROM artist_aliases a2 WHERE a2.ulan=? ORDER BY a2.display DESC, a2.preferred DESC, a2.id ASC LIMIT 1)");
    $st->execute([$ulan, $ulan]);
    $n = (string)($st->fetchColumn() ?: ('ULAN ' . $ulan));
    $parts = array_map('trim', explode(',', $n));
    if (count($parts) >= 2) { $f = array_shift($parts); $n = implode(' ', $parts) . ' ' . $f; }
    return $cache[$ulan] = $n;
}

$out = '/var/log/artist-networks/insight-moneyball-' . date('Y-m-d') . '.md';
$fh  = fopen($out, 'w');
fwrite($fh, "# Moneyball — undervalued Met works\n\n_" . date('c') . "_\n\n");
fwrite($fh, "**Legend:** ⭐ = the Met has flagged this work as a collection highlight (`isHighlight=true` in its catalog).\n\n");

// Section A: Top works in storage by central artists.
fwrite($fh, "## Section A — Top works currently *not* on view, by network-central artists\n\n");
fwrite($fh, "| Artist | Work | Date | Type | Met link |\n");
fwrite($fh, "|---|---|---|---|---|\n");

$sql = "SELECT m.ulan, m.title, m.object_date, m.object_name, m.is_highlight, m.link, m.image_fetched_at,
               ad.degree
               $pvSel
               $edSel
        FROM met_works m
        JOIN artist_degree ad ON ad.ulan = m.ulan
        $pvJoin
        $edJoin
        WHERE m.gallery_number IS NULL
          AND m.image_fetched_at IS NOT NULL
          AND m.primary_image_small <> ''
        ORDER BY ad.degree DESC, m.is_highlight DESC, m.rank_score DESC
        LIMIT 400";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$scored = [];
foreach ($rows as $r) {
    $pv     = (float)($r['pv']    ?? 0);
    $accel  = (float)($r['accel'] ?? 1.0);
    $langs  = (int)  ($r['langs'] ?? 0);
    // Clamp accel so a single outlier doesn't dominate; reward growth gently.
    $accelClamped = max(0.6, min(1.6, $accel));
    $langBoost    = 1 + 0.4 * log($langs + 1);
    $score = (float)$r['degree']
           * (1 + log($pv + 1))
           * ($hasEdits ? $langBoost * $accelClamped : 1.0)
           * ((int)$r['is_highlight'] ? 2 : 1);
    $r['score'] = $score;
    $scored[] = $r;
}
usort($scored, fn($a,$b) => $b['score'] <=> $a['score']);
// Cap to 3 works per artist so a single artist's drawing corpus doesn't
// crowd the list. We want breadth across moneyball candidates.
$perArtistCap = 3;
$perArtistCount = [];
$picked = [];
foreach ($scored as $r) {
    $u = (int)$r['ulan'];
    if (($perArtistCount[$u] ?? 0) >= $perArtistCap) continue;
    $perArtistCount[$u] = ($perArtistCount[$u] ?? 0) + 1;
    $picked[] = $r;
    if (count($picked) >= 50) break;
}
foreach ($picked as $r) {
    $artist = name($pdo, (int)$r['ulan']);
    $hi = $r['is_highlight'] ? ' ⭐' : '';
    fwrite($fh, sprintf(
        "| [%s](/?ulan=%d) | %s%s | %s | %s | [Met](%s) |\n",
        $artist, $r['ulan'],
        htmlspecialchars($r['title'] ?? ''), $hi,
        htmlspecialchars($r['object_date'] ?? ''),
        htmlspecialchars($r['object_name'] ?? ''),
        $r['link']
    ));
}
fwrite($fh, "\n");

// Section B: Coverage per central artist.
fwrite($fh, "## Section B — Coverage: top artists, how their Met works are split\n\n");
fwrite($fh, "| Artist | Met works (cataloged) | On view | In storage | % on view |\n");
fwrite($fh, "|---|---:|---:|---:|---:|\n");

$sql = "SELECT m.ulan,
               ad.degree,
               COUNT(*) AS total,
               SUM(CASE WHEN m.gallery_number IS NOT NULL THEN 1 ELSE 0 END) AS on_view
        FROM met_works m
        JOIN artist_degree ad ON ad.ulan = m.ulan
        WHERE m.image_fetched_at IS NOT NULL
        GROUP BY m.ulan, ad.degree
        ORDER BY ad.degree DESC
        LIMIT 30";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $artist = name($pdo, (int)$r['ulan']);
    $total  = (int)$r['total'];
    $on     = (int)$r['on_view'];
    $off    = $total - $on;
    $pct    = $total ? round(100 * $on / $total) : 0;
    fwrite($fh, sprintf(
        "| [%s](/?ulan=%d) | %d | %d | %d | %d%% |\n",
        $artist, $r['ulan'], $total, $on, $off, $pct
    ));
}
fwrite($fh, "\n");

// Section C: Met-flagged highlights in storage.
fwrite($fh, "## Section C — Highlights currently *not* on view\n\n");
fwrite($fh, "Met-flagged highlights that aren't on display right now.\n\n");
fwrite($fh, "| Artist | Work | Date | Type | Met link |\n");
fwrite($fh, "|---|---|---|---|---|\n");

$sql = "SELECT m.ulan, m.title, m.object_date, m.object_name, m.link, ad.degree
        FROM met_works m
        JOIN artist_degree ad ON ad.ulan = m.ulan
        WHERE m.gallery_number IS NULL
          AND m.is_highlight = 1
          AND m.image_fetched_at IS NOT NULL
        ORDER BY ad.degree DESC
        LIMIT 30";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $artist = name($pdo, (int)$r['ulan']);
    fwrite($fh, sprintf(
        "| [%s](/?ulan=%d) | %s | %s | %s | [Met](%s) |\n",
        $artist, $r['ulan'],
        htmlspecialchars($r['title'] ?? ''),
        htmlspecialchars($r['object_date'] ?? ''),
        htmlspecialchars($r['object_name'] ?? ''),
        $r['link']
    ));
}
fwrite($fh, "\n");

fwrite($fh, build_sources_footer(['getty', 'wikipedia_pv', 'wikipedia_edits', 'met_oa', 'met_api']));
fclose($fh);
logmsg('wrote ' . $out);
echo $out . "\n";
