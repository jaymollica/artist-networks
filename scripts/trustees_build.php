<?php
/**
 * Rebuild the denormalized `trustees` table from `museum_officers`. Run after
 * museums_refresh.php (the wrapper does this automatically).
 *
 *   php scripts/trustees_build.php
 */

declare(strict_types=1);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/../lib/trustee_canonicalize.php';

const DB_NAME      = 'artist_networks';
const DB_USER      = 'artist_networks';
const DB_PASS_FILE = '/etc/artist-networks/db_pass';

$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    trim((string)file_get_contents(DB_PASS_FILE)),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function lm(string $m): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $m . "\n"); }

// Pull every filing-level row, not just distinct (person_name, ein), so we can
// compute first/last tax_period per (canonical_key, ein) for tenure tracking.
$sql = "SELECT person_name, ein, tax_period FROM museum_officers WHERE role IN ('trustee','other')";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
lm('officer rows: ' . count($rows));

$bucket = []; // key => ['display' => longest_observed_name, 'eins' => [ein => true]]
$tenures = []; // key => ein => ['first' => period, 'last' => period, 'count' => N]
foreach ($rows as $r) {
    $c = trustee_canonical($r['person_name']);
    $key = $c['key'];
    if (!isset($bucket[$key])) {
        $bucket[$key] = ['display' => $r['person_name'], 'eins' => []];
    } elseif (strlen($r['person_name']) > strlen($bucket[$key]['display'])) {
        $bucket[$key]['display'] = $r['person_name'];
    }
    $bucket[$key]['eins'][$r['ein']] = true;

    $p = $r['tax_period'];
    if (!isset($tenures[$key][$r['ein']])) {
        $tenures[$key][$r['ein']] = ['first' => $p, 'last' => $p, 'count' => 1];
    } else {
        $t =& $tenures[$key][$r['ein']];
        if ($p < $t['first']) $t['first'] = $p;
        if ($p > $t['last'])  $t['last']  = $p;
        $t['count']++;
        unset($t);
    }
}

$pdo->beginTransaction();
$pdo->exec('DELETE FROM trustees');
$pdo->exec('DELETE FROM trustee_tenures');
$ins  = $pdo->prepare('INSERT INTO trustees (canonical_key, display_name, museum_eins, board_count) VALUES (?, ?, ?, ?)');
$insT = $pdo->prepare('INSERT INTO trustee_tenures (canonical_key, ein, first_period, last_period, filings_count) VALUES (?, ?, ?, ?, ?)');
$total = 0; $multi = 0; $tenRows = 0;
foreach ($bucket as $key => $t) {
    $eins = array_keys($t['eins']);
    sort($eins);
    $count = count($eins);
    $ins->execute([$key, $t['display'], implode(',', $eins), $count]);
    $total++;
    if ($count >= 2) $multi++;
    foreach ($tenures[$key] ?? [] as $ein => $tn) {
        $insT->execute([$key, $ein, $tn['first'], $tn['last'], $tn['count']]);
        $tenRows++;
    }
}
$pdo->commit();
lm("trustees: $total total, $multi on multiple boards");
lm("tenure rows: $tenRows");
