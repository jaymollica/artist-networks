<?php
/**
 * Met Open Access ingestion.
 * Downloads MetObjects.csv, filters rows whose Artist ULAN URL matches a ULAN
 * in our graph, and writes them to the met_works table.
 *
 *   php scripts/met_ingest.php [--no-download]
 */

declare(strict_types=1);
ini_set('memory_limit', '768M');

const CSV_URL  = 'https://media.githubusercontent.com/media/metmuseum/openaccess/master/MetObjects.csv';
const CSV_PATH = '/tmp/MetObjects.csv';
const PER_ARTIST_CAP = 50;

$opts        = getopt('', ['no-download']);
$noDownload  = isset($opts['no-download']);

const DB_NAME    = 'artist_networks';
const DB_USER    = 'artist_networks';
const DB_PASS_FILE = '/root/.artist_networks_db_pass';

if (!is_readable(DB_PASS_FILE)) { fwrite(STDERR, "cannot read " . DB_PASS_FILE . "\n"); exit(1); }
$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    trim((string)file_get_contents(DB_PASS_FILE)),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function logmsg(string $msg): void {
    fwrite(STDERR, '[' . date('H:i:s') . '] ' . $msg . "\n");
}

// 1. Build the set of ULANs we care about: anyone in our relationship graph,
//    plus anyone with biographical data (so first-degree discoveries are covered).
logmsg('loading tracked ULANs…');
$tracked = [];
$sql = "SELECT artist_ulan AS u FROM artist_relationships
        UNION
        SELECT related_ulan FROM artist_relationships";
foreach ($pdo->query($sql, PDO::FETCH_ASSOC) as $r) $tracked[(int)$r['u']] = true;
logmsg('tracking ' . count($tracked) . ' ULANs');

// 2. Download CSV if needed.
if (!$noDownload) {
    if (!file_exists(CSV_PATH) || (time() - filemtime(CSV_PATH)) > 86400) {
        logmsg('downloading Met CSV…');
        $cmd = 'curl -sL --fail ' . escapeshellarg(CSV_URL) . ' -o ' . escapeshellarg(CSV_PATH);
        passthru($cmd, $rc);
        if ($rc !== 0) { logmsg('download failed (rc=' . $rc . ')'); exit(1); }
    } else {
        logmsg('using cached CSV (< 24h old)');
    }
}
if (!is_file(CSV_PATH)) { logmsg('no CSV at ' . CSV_PATH); exit(1); }
logmsg('CSV size: ' . round(filesize(CSV_PATH) / 1048576, 1) . ' MB');

// 3. Stream-parse CSV.
$f = fopen(CSV_PATH, 'r');
if (!$f) { logmsg('cannot open csv'); exit(1); }
$header = fgetcsv($f);
$col = function(string $name) use ($header) {
    $i = array_search($name, $header, true);
    if ($i === false) { fwrite(STDERR, "column not found: $name\n"); exit(1); }
    return $i;
};
$cObj   = $col('Object ID');
$cTit   = $col('Title');
$cDate  = $col('Object Date');
$cLink  = $col('Link Resource');
$cUlan  = $col('Artist ULAN URL');
$cPD    = $col('Is Public Domain');
$cHi    = $col('Is Highlight');
$cName  = $col('Object Name');
$cClass = $col('Classification');

// Rank known classifications so when we cap per-artist we keep the best.
$RANK = [
  'Painting'       => 100,
  'Drawing'        => 80,
  'Sculpture'      => 70,
  'Photograph'     => 60,
  'Hanging scroll' => 55,
  'Woodblock print' => 50,
  'Print'          => 45,
];

// 4. Recreate the target table.
logmsg('rebuilding met_works table…');
$pdo->exec("DROP TABLE IF EXISTS met_works");
$pdo->exec("CREATE TABLE met_works (
    id                  INT NOT NULL AUTO_INCREMENT,
    object_id           INT NOT NULL,
    ulan                INT NOT NULL,
    title               VARCHAR(500),
    object_date         VARCHAR(100),
    object_name         VARCHAR(120),
    classification      VARCHAR(120),
    is_highlight        TINYINT(1) NOT NULL DEFAULT 0,
    rank_score          SMALLINT NOT NULL DEFAULT 0,
    link                VARCHAR(500),
    primary_image_small VARCHAR(800) NULL,
    primary_image       VARCHAR(800) NULL,
    gallery_number      VARCHAR(20)  NULL,
    image_fetched_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_obj_ulan (object_id, ulan),
    KEY idx_ulan_rank (ulan, rank_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 5. Stream rows; cap per-artist to keep the table bounded.
$insert = $pdo->prepare(
    'INSERT IGNORE INTO met_works (object_id, ulan, title, object_date, object_name, classification, is_highlight, rank_score, link) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$pdo->beginTransaction();

$rowCount = 0;
$matched  = 0;
$skippedNotPd = 0;
while (($row = fgetcsv($f, 0, ',', '"', '\\')) !== false) {
    $rowCount++;
    if ($rowCount % 50000 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        logmsg(sprintf('  %d rows scanned, %d inserted', $rowCount, $matched));
    }
    // Only ingest public-domain rows — these have unrestricted image rights.
    if (($row[$cPD] ?? '') !== 'True') { $skippedNotPd++; continue; }

    $ulanField = $row[$cUlan] ?? '';
    if ($ulanField === '') continue;

    $name  = $row[$cName]  ?? '';
    $class = $row[$cClass] ?? '';
    $hi    = (($row[$cHi] ?? '') === 'True') ? 1 : 0;
    $score = $RANK[$name] ?? ($RANK[$class] ?? 20);
    if ($hi) $score += 1000;

    $seen = [];
    foreach (explode('|', $ulanField) as $url) {
        if (!preg_match('~/ulan/(\d+)~', trim($url), $m)) continue;
        $u = (int)$m[1];
        if (!isset($tracked[$u]) || isset($seen[$u])) continue;
        $seen[$u] = true;

        $insert->execute([
            (int)($row[$cObj] ?? 0),
            $u,
            mb_substr($row[$cTit]  ?? '', 0, 500),
            mb_substr($row[$cDate] ?? '', 0, 100),
            mb_substr($name,             0, 120),
            mb_substr($class,            0, 120),
            $hi,
            $score,
            mb_substr($row[$cLink] ?? '', 0, 500),
        ]);
        if ($insert->rowCount() > 0) $matched++;
    }
}
$pdo->commit();

// Cap per-artist by score (after-the-fact pruning).
logmsg('pruning to top ' . PER_ARTIST_CAP . ' per artist…');
$pdo->exec("DELETE FROM met_works WHERE id IN (
    SELECT id FROM (
        SELECT id, ROW_NUMBER() OVER (PARTITION BY ulan ORDER BY rank_score DESC, id ASC) AS rn
        FROM met_works
    ) x WHERE x.rn > " . PER_ARTIST_CAP . "
)");

fclose($f);

$artistCount = (int)$pdo->query('SELECT COUNT(DISTINCT ulan) FROM met_works')->fetchColumn();
$finalCount  = (int)$pdo->query('SELECT COUNT(*) FROM met_works')->fetchColumn();
logmsg(sprintf('done: %d scanned, %d inserted (%d non-PD skipped), kept %d across %d artists',
    $rowCount, $matched, $skippedNotPd, $finalCount, $artistCount));
