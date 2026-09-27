<?php
/**
 * Map each tracked ULAN to its English Wikipedia article via Wikidata.
 *
 *   php scripts/wiki_titles.php [--batch=100] [--limit=N] [--refresh]
 */

declare(strict_types=1);
ini_set('memory_limit', '1G');

$opts    = getopt('', ['batch::', 'limit::', 'refresh']);
$batch   = isset($opts['batch']) ? max(20, min(200, (int)$opts['batch'])) : 100;
$limit   = isset($opts['limit']) ? (int)$opts['limit'] : 0;
$refresh = isset($opts['refresh']);

const DB_NAME    = 'artist_networks';
const DB_USER    = 'artist_networks';
const DB_PASS_FILE = '/root/.artist_networks_db_pass';
const SPARQL_URL = 'https://query.wikidata.org/sparql';
const UA         = 'artist-networks/1.0 (https://networks.vaguespac.es)';

$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    trim((string)file_get_contents(DB_PASS_FILE)),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function logmsg(string $m): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $m . "\n"); }

$pdo->exec("CREATE TABLE IF NOT EXISTS wiki_links (
    ulan      INT NOT NULL PRIMARY KEY,
    qid       VARCHAR(20) NULL,
    en_title  VARCHAR(255) NULL,
    fetched_at DATETIME NOT NULL,
    KEY idx_title (en_title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Tracked ULANs ordered by degree (so we hit the important ones first).
logmsg('loading tracked ULANs by degree…');
$having = $refresh ? '' : 'HAVING u NOT IN (SELECT ulan FROM wiki_links)';
$sql = "SELECT u, SUM(deg) AS total
        FROM (
            SELECT artist_ulan AS u, COUNT(*) AS deg FROM artist_relationships GROUP BY artist_ulan
            UNION ALL
            SELECT related_ulan AS u, COUNT(*) AS deg FROM artist_relationships GROUP BY related_ulan
        ) sub
        GROUP BY u
        $having
        ORDER BY total DESC";
$ulans = array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
logmsg('tracked ULANs to look up: ' . count($ulans));

if ($limit > 0) $ulans = array_slice($ulans, 0, $limit);

function sparql_query(string $query): array {
    $url = SPARQL_URL . '?query=' . urlencode($query) . '&format=json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Accept: application/sparql-results+json', 'User-Agent: ' . UA],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
    ]);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body && $code >= 200 && $code < 300) {
            curl_close($ch);
            $j = json_decode($body, true);
            return $j['results']['bindings'] ?? [];
        }
        $err = curl_error($ch) ?: ('http ' . $code);
        logmsg("  retry $attempt: $err");
        sleep((int)min(20, pow(2, $attempt)));
    }
    curl_close($ch);
    return [];
}

$upsert = $pdo->prepare(
    'REPLACE INTO wiki_links (ulan, qid, en_title, fetched_at) VALUES (?, ?, ?, NOW())'
);

$totalMatched = 0;
$totalQueried = 0;
$batches = array_chunk($ulans, $batch);
foreach ($batches as $i => $chunk) {
    $values = implode(' ', array_map(fn($u) => '"' . $u . '"', $chunk));
    $q = <<<SPARQL
PREFIX schema: <http://schema.org/>
PREFIX wdt: <http://www.wikidata.org/prop/direct/>
SELECT ?ulan ?q ?title WHERE {
  VALUES ?ulan { $values }
  ?q wdt:P245 ?ulan .
  OPTIONAL {
    ?wiki schema:about ?q ;
          schema:isPartOf <https://en.wikipedia.org/> ;
          schema:name ?title .
  }
}
SPARQL;

    $rows = sparql_query($q);
    $seen = [];
    foreach ($rows as $r) {
        $u = $r['ulan']['value'] ?? '';
        if ($u === '' || isset($seen[$u])) continue;
        $seen[$u] = true;
        $qUri = $r['q']['value'] ?? '';
        $qid  = $qUri ? basename($qUri) : null;
        $title = $r['title']['value'] ?? null;
        $upsert->execute([(int)$u, $qid, $title]);
        if ($title) $totalMatched++;
    }
    // also record null for ULANs that returned no row (so we don't keep retrying)
    foreach ($chunk as $u) {
        if (!isset($seen[(string)$u])) $upsert->execute([(int)$u, null, null]);
    }

    $totalQueried += count($chunk);
    if (($i + 1) % 5 === 0 || $i === count($batches) - 1) {
        logmsg(sprintf('  batch %d / %d — %d queried, %d matched',
            $i + 1, count($batches), $totalQueried, $totalMatched));
    }
}

logmsg("done: $totalQueried ULANs queried, $totalMatched have an English Wikipedia article");
