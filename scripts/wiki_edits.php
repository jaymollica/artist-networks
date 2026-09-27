<?php
/**
 * Fetch Wikipedia edit history + language-count for every ULAN we have a
 * Wikipedia title for. Stores monthly edit buckets plus pre-computed stats:
 * total edits, recent vs baseline averages, acceleration ratio, language count.
 *
 *   php scripts/wiki_edits.php [--months=24] [--concurrency=10] [--limit=N]
 *
 * Acceleration ratio = avg edits/month in last 6 months ÷ avg in months 7-24.
 * Languages = number of interwiki sitelinks for the en.wiki article (a proxy
 * for cross-cultural reach independent of English pageviews).
 */

declare(strict_types=1);
ini_set('memory_limit', '1G');

$opts        = getopt('', ['months::', 'concurrency::', 'limit::']);
$months      = isset($opts['months']) ? max(12, min(36, (int)$opts['months'])) : 24;
$concurrency = isset($opts['concurrency']) ? max(1, min(20, (int)$opts['concurrency'])) : 10;
$limit       = isset($opts['limit']) ? (int)$opts['limit'] : 0;

const DB_NAME      = 'artist_networks';
const DB_USER      = 'artist_networks';
const DB_PASS_FILE = '/root/.artist_networks_db_pass';
const UA           = 'artist-networks/1.0 (https://networks.vaguespac.es)';
const API_BASE     = 'https://en.wikipedia.org/w/api.php';

$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    trim((string)file_get_contents(DB_PASS_FILE)),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function logmsg(string $m): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $m . "\n"); }

$pdo->exec("CREATE TABLE IF NOT EXISTS wiki_edits (
    ulan  INT NOT NULL,
    month CHAR(7) NOT NULL,
    edits INT NOT NULL,
    PRIMARY KEY (ulan, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS wiki_edit_stats (
    ulan                 INT NOT NULL PRIMARY KEY,
    total_edits          INT NOT NULL,
    avg_recent_edits     FLOAT NOT NULL,
    avg_baseline_edits   FLOAT NOT NULL,
    accel_ratio          FLOAT NOT NULL,
    languages            SMALLINT NOT NULL,
    months_window        SMALLINT NOT NULL,
    computed_at          DATETIME NOT NULL,
    INDEX ix_accel (accel_ratio),
    INDEX ix_langs (languages)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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

// Window: first day of (today - $months) at 00:00 UTC → now.
$now      = new DateTime('now', new DateTimeZone('UTC'));
$cutoff   = (clone $now)->modify("first day of -{$months} months")->setTime(0, 0, 0);
$cutoffIso = $cutoff->format('Y-m-d\TH:i:s\Z');
$recentCutoff = (clone $now)->modify('first day of -6 months')->format('Y-m');
logmsg("window: {$cutoffIso} → now (recent split at {$recentCutoff})");

$insertEdit = $pdo->prepare('REPLACE INTO wiki_edits (ulan, month, edits) VALUES (?, ?, ?)');
$upsertStat = $pdo->prepare('REPLACE INTO wiki_edit_stats
    (ulan, total_edits, avg_recent_edits, avg_baseline_edits, accel_ratio, languages, months_window, computed_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');

function api_url(string $title, string $cutoffIso, ?string $rvcontinue = null): string {
    $params = [
        'action'        => 'query',
        'prop'          => 'revisions|langlinks',
        'titles'        => $title,
        'rvprop'        => 'timestamp',
        'rvlimit'       => '500',
        'rvend'         => $cutoffIso,
        'lllimit'       => '500',
        'format'        => 'json',
        'formatversion' => '2',
    ];
    if ($rvcontinue) $params['rvcontinue'] = $rvcontinue;
    return API_BASE . '?' . http_build_query($params);
}

// Process artist response into months map + langlink count.
function digest_response(string $body, array &$monthMap, ?int &$langs): ?string {
    $j = json_decode($body, true);
    if (!is_array($j)) return null;
    $page = $j['query']['pages'][0] ?? null;
    if (!$page || !empty($page['missing'])) return null;
    foreach (($page['revisions'] ?? []) as $rev) {
        $ts = $rev['timestamp'] ?? '';
        if (strlen($ts) >= 7) {
            $m = substr($ts, 0, 7); // YYYY-MM
            $monthMap[$m] = ($monthMap[$m] ?? 0) + 1;
        }
    }
    if ($langs === null) {
        $langs = count($page['langlinks'] ?? []);
    }
    return $j['continue']['rvcontinue'] ?? null;
}

$pdo->beginTransaction();
$done = 0;
$total = count($artists);
$lastTick = microtime(true);

// build month skeleton (every month in window) so the table has zeros too
$skeleton = [];
$walker = clone $cutoff;
while ($walker <= $now) {
    $skeleton[$walker->format('Y-m')] = 0;
    $walker->modify('first day of +1 month');
}
// Recent = last 6 months, baseline = the rest of the window
$allMonths = array_keys($skeleton);
$recentMonths = array_slice($allMonths, -6);
$baselineMonths = array_slice($allMonths, 0, max(0, count($allMonths) - 6));

while ($artists) {
    $batch = array_splice($artists, 0, $concurrency);

    // Each batch entry can need multiple sequential pages. Track them.
    $pending = [];
    foreach ($batch as $a) {
        $pending[] = [
            'artist'   => $a,
            'months'   => $skeleton,
            'langs'    => null,
            'page'     => 0,
            'continue' => null,
            'done'     => false,
        ];
    }

    // up to 3 sequential pages per artist (covers 1500 revisions)
    for ($page = 0; $page < 3; $page++) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($pending as $idx => $p) {
            if ($p['done']) continue;
            if ($page > 0 && !$p['continue']) { $pending[$idx]['done'] = true; continue; }
            $url = api_url($p['artist']['en_title'], $GLOBALS['cutoffIso'], $p['continue']);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_HTTPHEADER     => ['User-Agent: ' . UA, 'Accept: application/json'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[(int)$ch] = ['ch' => $ch, 'idx' => $idx];
        }
        if (!$handles) { curl_multi_close($mh); break; }
        $running = null;
        do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.2); } while ($running > 0);

        foreach ($handles as $h) {
            $body = curl_multi_getcontent($h['ch']);
            $code = curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $h['ch']);
            curl_close($h['ch']);
            $p =& $pending[$h['idx']];
            if (!$body || $code < 200 || $code >= 300) {
                $p['done'] = true;
                continue;
            }
            $cont = digest_response($body, $p['months'], $p['langs']);
            if ($cont) {
                $p['continue'] = $cont;
            } else {
                $p['done'] = true;
            }
            unset($p);
        }
        curl_multi_close($mh);
        usleep(150000); // 150ms breather between pages
    }

    // commit each artist
    foreach ($pending as $p) {
        $ulan = (int)$p['artist']['ulan'];
        $perMonth = $p['months'];
        foreach ($perMonth as $m => $c) {
            if ($c > 0) $insertEdit->execute([$ulan, $m, $c]);
        }
        $recentVals   = array_map(fn($m) => $perMonth[$m] ?? 0, $recentMonths);
        $baselineVals = array_map(fn($m) => $perMonth[$m] ?? 0, $baselineMonths);
        $total_edits = array_sum($perMonth);
        $avgRecent   = $recentVals ? array_sum($recentVals) / count($recentVals) : 0.0;
        $avgBaseline = $baselineVals ? array_sum($baselineVals) / count($baselineVals) : 0.0;
        // Smooth low-edit baselines so a single recent edit doesn't blow ratio to infinity.
        $accelRatio  = $avgRecent / max(0.25, $avgBaseline);
        $languages   = (int)($p['langs'] ?? 0);
        $upsertStat->execute([
            $ulan, $total_edits,
            round($avgRecent, 3), round($avgBaseline, 3),
            round($accelRatio, 3), $languages,
            $months
        ]);
        $done++;
    }

    if (microtime(true) - $lastTick > 4) {
        $pdo->commit();
        $pdo->beginTransaction();
        logmsg(sprintf('  %d / %d (%.1f%%)', $done, $total, 100 * $done / $total));
        $lastTick = microtime(true);
    }
}
$pdo->commit();
logmsg("done: $done artists processed");
