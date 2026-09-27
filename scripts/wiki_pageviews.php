<?php
/**
 * Fetch Wikipedia monthly pageviews for every ULAN we have a Wikipedia title for.
 * Stores last 24 months per artist plus pre-computed stats.
 *
 *   php scripts/wiki_pageviews.php [--months=24] [--concurrency=15] [--limit=N]
 */

declare(strict_types=1);
ini_set('memory_limit', '1G');

$opts        = getopt('', ['months::', 'concurrency::', 'limit::']);
$months      = isset($opts['months']) ? max(3, min(36, (int)$opts['months'])) : 24;
$concurrency = isset($opts['concurrency']) ? max(1, min(30, (int)$opts['concurrency'])) : 15;
$limit       = isset($opts['limit']) ? (int)$opts['limit'] : 0;

const DB_NAME    = 'artist_networks';
const DB_USER    = 'artist_networks';
const DB_PASS_FILE = '/root/.artist_networks_db_pass';
const UA         = 'artist-networks/1.0 (https://networks.vaguespac.es)';

$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    trim((string)file_get_contents(DB_PASS_FILE)),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function logmsg(string $m): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $m . "\n"); }

$pdo->exec("CREATE TABLE IF NOT EXISTS wiki_pageviews (
    ulan  INT NOT NULL,
    month CHAR(7) NOT NULL,
    views INT NOT NULL,
    PRIMARY KEY (ulan, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS wiki_pageview_stats (
    ulan              INT NOT NULL PRIMARY KEY,
    avg_views         INT NOT NULL,
    avg_recent_views  INT NOT NULL,
    trend_ratio       FLOAT NOT NULL,
    months_window     SMALLINT NOT NULL,
    computed_at       DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Pull artists that have a Wikipedia title, ordered by Getty network degree.
logmsg('loading artists with Wikipedia titles…');
$sql = "SELECT wl.ulan, wl.en_title, COALESCE(d.deg, 0) AS degree
        FROM wiki_links wl
        LEFT JOIN (
            SELECT u, SUM(deg) AS deg FROM (
                SELECT artist_ulan AS u, COUNT(*) AS deg FROM artist_relationships GROUP BY artist_ulan
                UNION ALL
                SELECT related_ulan AS u, COUNT(*) AS deg FROM artist_relationships GROUP BY related_ulan
            ) s GROUP BY u
        ) d ON d.u = wl.ulan
        WHERE wl.en_title IS NOT NULL
        ORDER BY degree DESC";
$artists = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
logmsg('artists to fetch: ' . count($artists));

if ($limit > 0) $artists = array_slice($artists, 0, $limit);

// Pageviews API expects YYYYMMDD; cap to first of month windows.
$end   = (new DateTime('first day of last month'))->modify('+1 month -1 day');
$start = (clone $end)->modify('-' . ($months - 1) . ' months')->modify('first day of this month');
$startStr = $start->format('Ymd');
$endStr   = $end->format('Ymd');
logmsg("window: $startStr → $endStr");

$insertView = $pdo->prepare('REPLACE INTO wiki_pageviews (ulan, month, views) VALUES (?, ?, ?)');
$upsertStat = $pdo->prepare('REPLACE INTO wiki_pageview_stats
    (ulan, avg_views, avg_recent_views, trend_ratio, months_window, computed_at)
    VALUES (?, ?, ?, ?, ?, NOW())');

function pv_url(string $title): string {
    $enc = rawurlencode(str_replace(' ', '_', $title));
    return "https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article/en.wikipedia.org/all-access/all-agents/{$enc}/monthly/" . $GLOBALS['startStr'] . '/' . $GLOBALS['endStr'];
}

$pdo->beginTransaction();
$done = 0;
$totalArtists = count($artists);
$lastTick = microtime(true);

while ($artists) {
    $batch = array_splice($artists, 0, $concurrency);
    $mh = curl_multi_init();
    $handles = [];
    foreach ($batch as $a) {
        $ch = curl_init(pv_url($a['en_title']));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['User-Agent: ' . UA, 'Accept: application/json'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[(int)$ch] = ['ch' => $ch, 'artist' => $a];
    }
    $running = null;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.2); } while ($running > 0);

    foreach ($handles as $h) {
        $body = curl_multi_getcontent($h['ch']);
        $code = curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $h['ch']);
        curl_close($h['ch']);

        $ulan = (int)$h['artist']['ulan'];
        if ($code === 404 || !$body) {
            // record an empty stat so we don't keep retrying
            $upsertStat->execute([$ulan, 0, 0, 1.0, $months]);
            $done++;
            continue;
        }
        $j = json_decode($body, true);
        $items = $j['items'] ?? [];
        if (!$items) {
            $upsertStat->execute([$ulan, 0, 0, 1.0, $months]);
            $done++;
            continue;
        }
        $views = [];
        foreach ($items as $item) {
            $ts = $item['timestamp'] ?? '';
            $month = substr($ts, 0, 4) . '-' . substr($ts, 4, 2);
            $v = (int)($item['views'] ?? 0);
            $views[$month] = $v;
            $insertView->execute([$ulan, $month, $v]);
        }
        $values = array_values($views);
        $avg = $values ? (int)round(array_sum($values) / count($values)) : 0;
        $halfLen = max(1, (int)floor(count($values) / 2));
        $recent  = array_slice($values, -$halfLen);
        $earlier = array_slice($values, 0, $halfLen);
        $avgRecent  = (int)round(array_sum($recent) / max(1, count($recent)));
        $avgEarlier = max(1.0, array_sum($earlier) / max(1, count($earlier))); // avoid div-by-0
        $trend = $avgEarlier > 0 ? $avgRecent / $avgEarlier : 1.0;
        $upsertStat->execute([$ulan, $avg, $avgRecent, $trend, $months]);
        $done++;
    }
    curl_multi_close($mh);

    if ($done % 200 < $concurrency && microtime(true) - $lastTick > 4) {
        $pdo->commit();
        $pdo->beginTransaction();
        logmsg(sprintf('  %d / %d (%.1f%%)', $done, $totalArtists, 100 * $done / $totalArtists));
        $lastTick = microtime(true);
    }
}
$pdo->commit();
logmsg("done: $done artists processed");
