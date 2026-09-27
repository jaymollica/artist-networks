<?php
/**
 * Ingest annual museum attendance figures from data/museum_attendance.csv.
 * Idempotent: re-running replaces rows matched by (ein, year).
 *
 *   php scripts/ingest_attendance.php [--dry-run]
 *
 * The CSV is hand-curated (mostly from The Art Newspaper's annual survey).
 * Header lines and blank lines are skipped.
 */

declare(strict_types=1);

const DB_NAME      = 'artist_networks';
const DB_USER      = 'artist_networks';
const DB_PASS_FILE = '/etc/artist-networks/db_pass';
const CSV_PATH     = __DIR__ . '/../data/museum_attendance.csv';

$opts   = getopt('', ['dry-run']);
$dryRun = isset($opts['dry-run']);

function lm(string $m): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $m . "\n"); }

if (!is_readable(CSV_PATH)) { lm('CSV not readable: ' . CSV_PATH); exit(1); }

$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    trim((string)file_get_contents(DB_PASS_FILE)),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$validEins = array_flip($pdo->query('SELECT ein FROM museums')->fetchAll(PDO::FETCH_COLUMN));

$ins = $pdo->prepare("INSERT INTO museum_attendance
    (ein, year, attendance, source, source_url, notes)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      attendance  = VALUES(attendance),
      source      = VALUES(source),
      source_url  = VALUES(source_url),
      notes       = VALUES(notes),
      ingested_at = NOW()");

$fp = fopen(CSV_PATH, 'r');
$rows = 0; $skipped = 0;
while (($line = fgets($fp)) !== false) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    // Pull rows out as CSV; tolerate variable column count (≥3).
    $cols = str_getcsv($line);
    if (count($cols) < 3) { $skipped++; continue; }
    [$ein, $year, $att] = $cols;
    $ein = str_pad(trim($ein), 9, '0', STR_PAD_LEFT);
    $year = (int)$year;
    $att = (int)str_replace([',', ' '], '', $att);
    if (!$year || !$att) { $skipped++; lm("  bad row: $line"); continue; }
    if (!isset($validEins[$ein])) { $skipped++; lm("  unknown EIN: $ein"); continue; }
    $source    = $cols[3] ?? 'art_newspaper';
    $sourceUrl = $cols[4] ?? null;
    $notes     = $cols[5] ?? null;
    if ($dryRun) {
        lm("  would upsert: $ein $year $att");
    } else {
        $ins->execute([$ein, $year, $att, $source, $sourceUrl, $notes]);
    }
    $rows++;
}
fclose($fp);
lm("rows processed: $rows (skipped: $skipped)" . ($dryRun ? ' — dry run, nothing written' : ''));
