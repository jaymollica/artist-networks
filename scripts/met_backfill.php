<?php
/**
 * Backfill primary_image_small, primary_image, and gallery_number for every
 * row in met_works by hitting the Met Museum public collection API. Uses
 * curl_multi for concurrency.
 *
 *   php scripts/met_backfill.php [--concurrency=20] [--refresh]
 *
 * Without --refresh, only rows with image_fetched_at IS NULL are touched.
 */

declare(strict_types=1);
ini_set('memory_limit', '1G');

$opts        = getopt('', ['concurrency::', 'refresh']);
$concurrency = isset($opts['concurrency']) ? max(1, min(40, (int)$opts['concurrency'])) : 20;
$refresh     = isset($opts['refresh']);

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

$where = $refresh ? '' : 'WHERE image_fetched_at IS NULL';
$total = (int)$pdo->query("SELECT COUNT(*) FROM met_works $where")->fetchColumn();
logmsg("rows to fetch: $total (concurrency=$concurrency)");

if ($total === 0) { logmsg('nothing to do'); exit(0); }

$update = $pdo->prepare(
    'UPDATE met_works SET primary_image_small=?, primary_image=?, gallery_number=?, image_fetched_at=NOW() WHERE id=?'
);

$select = $pdo->prepare("SELECT id, object_id FROM met_works $where ORDER BY id LIMIT ?");
$select->bindValue(1, 1000, PDO::PARAM_INT);
$select->execute();
$queue = $select->fetchAll(PDO::FETCH_ASSOC);

$done = 0;
$lastTick = microtime(true);

while ($queue) {
    $mh = curl_multi_init();
    $handles = [];
    $batch   = array_splice($queue, 0, $concurrency);
    foreach ($batch as $row) {
        $ch = curl_init('https://collectionapi.metmuseum.org/public/collection/v1/objects/' . (int)$row['object_id']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['User-Agent: artist-networks/1.0 (https://networks.vaguespac.es)'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[(int)$ch] = ['ch' => $ch, 'row' => $row];
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.2);
    } while ($running > 0);

    $failedRows = [];
    $rateLimitHit = false;
    foreach ($handles as $h) {
        $body = curl_multi_getcontent($h['ch']);
        $code = curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
        $ok = false;
        $img = $imgSmall = $gallery = null;
        if ($body && $code >= 200 && $code < 300) {
            $j = json_decode($body, true);
            if ($j && isset($j['objectID'])) {
                $ok = true;
                $imgSmall = $j['primaryImageSmall'] ?: null;
                $img      = $j['primaryImage']      ?: null;
                $g        = $j['GalleryNumber']     ?? '';
                $gallery  = ($g === '' || $g === null) ? null : (string)$g;
            }
        }
        if ($code === 403 || $code === 429) $rateLimitHit = true;
        if ($ok) {
            $update->execute([$imgSmall ?? '', $img ?? '', $gallery, (int)$h['row']['id']]);
            $done++;
        } else {
            $row = $h['row'];
            $row['retries'] = ($row['retries'] ?? 0) + 1;
            if ($row['retries'] <= 3) {
                $failedRows[] = $row;
            } else {
                // Give up; leave image_fetched_at NULL so a later run picks it up.
                logmsg("  giving up on object {$row['object_id']} after 3 retries (last code $code)");
                $done++;
            }
        }
        curl_multi_remove_handle($mh, $h['ch']);
        curl_close($h['ch']);
    }
    if ($rateLimitHit) {
        logmsg('  rate-limit response detected — sleeping 30s');
        sleep(30);
    } else {
        usleep(150000); // gentle 150ms pace between batches
    }
    if ($failedRows) {
        foreach ($failedRows as $r) array_unshift($queue, $r);
    }
    curl_multi_close($mh);

    if (microtime(true) - $lastTick > 4) {
        logmsg(sprintf('  %d / %d (%.1f%%)', $done, $total, 100 * $done / $total));
        $lastTick = microtime(true);
    }

    // refill queue when low
    if (count($queue) < $concurrency) {
        $select->bindValue(1, 1000, PDO::PARAM_INT);
        $select->execute();
        $more = $select->fetchAll(PDO::FETCH_ASSOC);
        // avoid re-pulling rows we just processed by tracking last id
        if ($more) {
            $existingIds = array_column($queue, 'id');
            foreach ($more as $r) {
                if (!in_array($r['id'], $existingIds, true)) $queue[] = $r;
            }
        }
    }
}

$onView = (int)$pdo->query('SELECT COUNT(*) FROM met_works WHERE gallery_number IS NOT NULL')->fetchColumn();
$total2 = (int)$pdo->query('SELECT COUNT(*) FROM met_works WHERE image_fetched_at IS NOT NULL')->fetchColumn();
logmsg("done: $done processed, $onView currently on view out of $total2 fetched");
