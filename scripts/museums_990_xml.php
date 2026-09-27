<?php
/**
 * Phase-2 trustee ingestion: parse raw IRS Form 990 e-file XML to capture
 * the complete Part VII Section A roster (every trustee, officer, key
 * employee), not just ProPublica's top-26 truncation.
 *
 *   php scripts/museums_990_xml.php [--years=2024,2025] [--ein=NNN] [--dry-run]
 *
 * Cache dir: /var/cache/artist-networks/990xml/
 *   index_YYYY.csv    annual index (~750k rows, ~70MB)
 *   {batch}.zip       per-batch ZIP (~250MB each)
 *
 * On first run for a given year, this script may download ~2-3 GB of zips.
 * Subsequent runs skip already-cached zips.
 */

declare(strict_types=1);
ini_set('memory_limit', '1G');

const DB_NAME      = 'artist_networks';
const DB_USER      = 'artist_networks';
const DB_PASS_FILE = '/etc/artist-networks/db_pass';
const CACHE_DIR    = '/var/cache/artist-networks/990xml';
const UA           = 'artist-networks/1.0 (https://networks.vaguespac.es) 990-xml-ingest';

$opts    = getopt('', ['years::', 'ein::', 'dry-run']);
$years   = isset($opts['years']) ? explode(',', $opts['years']) : ['2024', '2025'];
$onlyEin = $opts['ein'] ?? null;
$dryRun  = isset($opts['dry-run']);

$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    trim((string)file_get_contents(DB_PASS_FILE)),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function lm(string $m): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $m . "\n"); }

if (!is_dir(CACHE_DIR) && !mkdir(CACHE_DIR, 0755, true)) {
    fwrite(STDERR, "cannot create " . CACHE_DIR . "\n"); exit(1);
}

function http_download(string $url, string $dest): bool {
    $fp = fopen($dest, 'w');
    if (!$fp) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: ' . UA],
        CURLOPT_TIMEOUT        => 0, // big files
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $code !== 200) { @unlink($dest); return false; }
    return true;
}

// --------------------------------------------------------------------------
// 1. Make sure index CSVs are cached
// --------------------------------------------------------------------------
foreach ($years as $y) {
    $path = CACHE_DIR . "/index_$y.csv";
    if (file_exists($path) && filesize($path) > 1024) continue;
    $url = "https://apps.irs.gov/pub/epostcard/990/xml/$y/index_$y.csv";
    lm("downloading index for $y …");
    if (!http_download($url, $path)) { lm("  failed to fetch index for $y"); continue; }
    lm('  ok ' . number_format(filesize($path)) . ' bytes');
}

// --------------------------------------------------------------------------
// 2. Collect target EINs
// --------------------------------------------------------------------------
$einSql = $onlyEin ? "SELECT ein FROM museums WHERE ein = ?" : "SELECT ein FROM museums";
$st = $pdo->prepare($einSql);
$st->execute($onlyEin ? [$onlyEin] : []);
$targetEins = $st->fetchAll(PDO::FETCH_COLUMN);
lm('target EINs: ' . count($targetEins));
$targetSet = array_fill_keys($targetEins, true);

// --------------------------------------------------------------------------
// 3. Walk index CSVs, find filings for our EINs, group by batch zip
// --------------------------------------------------------------------------
$filingsByBatch = []; // batch => [['ein','tax_prd','object_id'], ...]
foreach ($years as $y) {
    $path = CACHE_DIR . "/index_$y.csv";
    if (!file_exists($path)) continue;
    $fp = fopen($path, 'r');
    fgetcsv($fp); // header
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < 10) continue;
        // RETURN_ID, FILING_TYPE, EIN, TAX_PERIOD, SUB_DATE, TAXPAYER_NAME, RETURN_TYPE, DLN, OBJECT_ID, XML_BATCH_ID
        [$rid, $ftype, $ein, $taxPrd, $subDate, $tn, $retType, $dln, $objId, $batch] = $row;
        $einPad = str_pad((string)$ein, 9, '0', STR_PAD_LEFT);
        if (!isset($targetSet[$einPad])) continue;
        if ($retType !== '990') continue;
        // Normalize the batch identifier to uppercase. IRS's HTML index lists
        // some batches with a lowercase final char ("04a"), but the actual
        // zip and the directory inside it use uppercase ("04A").
        $batchUp = strtoupper($batch);
        $filingsByBatch[$batchUp][] = ['ein' => $einPad, 'tax_prd' => $taxPrd, 'object_id' => $objId, 'year' => $y];
    }
    fclose($fp);
}
$totalFilings = 0;
foreach ($filingsByBatch as $rows) $totalFilings += count($rows);
lm('filings to process: ' . $totalFilings . ' across ' . count($filingsByBatch) . ' batch zips');

if ($dryRun) {
    foreach ($filingsByBatch as $batch => $rows) {
        lm("  $batch: " . count($rows) . " filings");
    }
    exit(0);
}

// --------------------------------------------------------------------------
// 4. Make sure each needed batch zip is cached
// --------------------------------------------------------------------------
foreach (array_keys($filingsByBatch) as $batch) {
    $zipPath = CACHE_DIR . "/$batch.zip";
    if (file_exists($zipPath) && filesize($zipPath) > 1024 * 1024) continue;
    $y = substr($batch, 0, 4);
    $url = "https://apps.irs.gov/pub/epostcard/990/xml/$y/$batch.zip";
    lm("downloading $batch.zip …");
    if (!http_download($url, $zipPath)) {
        lm("  FAILED: $batch");
        continue;
    }
    lm('  ok ' . number_format(filesize($zipPath)) . ' bytes');
}

// --------------------------------------------------------------------------
// 5. Parse each filing's Part VII Section A
// --------------------------------------------------------------------------
function parse_part_vii(string $xmlPath): array {
    $xml = @file_get_contents($xmlPath);
    if (!$xml) return [];
    // strip default namespace to keep SimpleXML simple
    $xml = preg_replace('/\sxmlns="[^"]+"/', '', $xml, 1);
    $sx = @simplexml_load_string($xml);
    if (!$sx) return [];
    $out = [];
    // Find Form990PartVIISectionAGrp nodes anywhere in the tree
    $nodes = $sx->xpath('//Form990PartVIISectionAGrp');
    foreach (($nodes ?: []) as $g) {
        $name = trim((string)($g->PersonNm ?? ''));
        if (!$name) {
            // sometimes structured as BusinessNm — skip those (orgs serving on boards)
            $bn = trim((string)($g->BusinessName->BusinessNameLine1Txt ?? ''));
            if ($bn) $name = $bn;
        }
        if (!$name) continue;
        $title = trim((string)($g->TitleTxt ?? ''));
        $isTrustee = isset($g->IndividualTrusteeOrDirectorInd) || isset($g->InstitutionalTrusteeInd);
        $isOfficer = isset($g->OfficerInd);
        $isKeyEmp  = isset($g->KeyEmployeeInd);
        $isHighC   = isset($g->HighestCompensatedEmployeeInd);
        $hours     = isset($g->AverageHoursPerWeekRt) ? (float)$g->AverageHoursPerWeekRt : null;
        $comp      = isset($g->ReportableCompFromOrgAmt) ? (int)$g->ReportableCompFromOrgAmt : null;
        $out[] = [
            'name' => $name, 'title' => $title,
            'trustee' => $isTrustee, 'officer' => $isOfficer,
            'key_employee' => $isKeyEmp, 'high_comp' => $isHighC,
            'hours' => $hours, 'comp' => $comp,
        ];
    }
    return $out;
}

function classify_role(array $o): string {
    if ($o['trustee']) return 'trustee'; // governance role
    if ($o['officer'] || $o['high_comp'] || $o['key_employee']) return 'paid_exec';
    return 'other';
}

// Delete-then-insert per (ein, tax_period) so XML data fully replaces any
// prior ProPublica HTML scrape for that filing.
$del = $pdo->prepare('DELETE FROM museum_officers WHERE ein = ? AND tax_period = ?');
$ins = $pdo->prepare("INSERT INTO museum_officers
    (ein, tax_period, person_name, title_raw, role, is_trustee, is_officer,
     is_key_employee, is_high_comp, compensation, hours_per_week, source, ingested_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'irs_xml_990', NOW())");

$filingsDone = 0; $rowsDone = 0;

// Returns rows that couldn't be located in this zip (so caller can retry in a sibling batch).
$processBatch = function (string $batch, array $rows) use ($pdo, $del, $ins, &$filingsDone, &$rowsDone): array {
    $zipPath = CACHE_DIR . "/$batch.zip";
    if (!file_exists($zipPath)) { lm("  missing zip $batch — skipping"); return $rows; }
    $tmp = sys_get_temp_dir() . '/990xml_' . posix_getpid() . '_' . bin2hex(random_bytes(3));
    if (!is_dir($tmp)) mkdir($tmp, 0700, true);
    // Inner zip layout varies by year: 2024 nests under "$batch/<obj>_public.xml",
    // 2025 stores at the root. Try both patterns.
    $patterns = [];
    foreach ($rows as $r) {
        $patterns[] = "$batch/{$r['object_id']}_public.xml";
        $patterns[] = "{$r['object_id']}_public.xml";
    }
    $args = implode(' ', array_map('escapeshellarg', $patterns));
    @shell_exec("cd " . escapeshellarg($tmp) . " && unzip -qq -o " . escapeshellarg($zipPath) . " $args 2>/dev/null");

    $unresolved = [];
    foreach ($rows as $r) {
        $xmlPath = "$tmp/$batch/{$r['object_id']}_public.xml";
        if (!file_exists($xmlPath)) {
            $alt = "$tmp/{$r['object_id']}_public.xml";
            if (file_exists($alt)) { $xmlPath = $alt; }
            else { $unresolved[] = $r; continue; }
        }
        $officers = parse_part_vii($xmlPath);
        if (!$officers) { lm("  no Part VII rows: {$r['ein']} {$r['tax_prd']}"); continue; }

        // Dedupe by person_name within a filing: same person can be both
        // trustee and paid officer — merge flags, take max comp/hours.
        $byName = [];
        foreach ($officers as $o) {
            $k = $o['name'];
            if (!isset($byName[$k])) { $byName[$k] = $o; continue; }
            $byName[$k]['trustee']      = $byName[$k]['trustee']      || $o['trustee'];
            $byName[$k]['officer']      = $byName[$k]['officer']      || $o['officer'];
            $byName[$k]['key_employee'] = $byName[$k]['key_employee'] || $o['key_employee'];
            $byName[$k]['high_comp']    = $byName[$k]['high_comp']    || $o['high_comp'];
            if (($o['comp'] ?? 0)  > ($byName[$k]['comp']  ?? 0))  $byName[$k]['comp']  = $o['comp'];
            if (($o['hours'] ?? 0) > ($byName[$k]['hours'] ?? 0))  $byName[$k]['hours'] = $o['hours'];
            if ($o['title'] && !$byName[$k]['title']) $byName[$k]['title'] = $o['title'];
        }

        try {
            $pdo->beginTransaction();
            $del->execute([$r['ein'], $r['tax_prd']]);
            foreach ($byName as $o) {
                $ins->execute([
                    $r['ein'], $r['tax_prd'],
                    substr($o['name'], 0, 255),
                    substr($o['title'], 0, 255),
                    classify_role($o),
                    $o['trustee'] ? 1 : 0,
                    $o['officer'] ? 1 : 0,
                    $o['key_employee'] ? 1 : 0,
                    $o['high_comp'] ? 1 : 0,
                    $o['comp'],
                    $o['hours'],
                ]);
                $rowsDone++;
            }
            $pdo->commit();
            $filingsDone++;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            lm("  ERR {$r['ein']} {$r['tax_prd']}: " . $e->getMessage());
        }
        if ($filingsDone % 10 === 0) lm("  $filingsDone filings done");
    }
    @shell_exec("rm -rf " . escapeshellarg($tmp));
    return $unresolved;
};

// --- First pass: process each batch as the index declared it ---
$unresolvedByBatch = [];
foreach ($filingsByBatch as $batch => $rows) {
    $left = $processBatch($batch, $rows);
    if ($left) $unresolvedByBatch[$batch] = $left;
}

// --- Sibling-batch fallback: the IRS index sometimes assigns a filing to
// "05A" when it's actually in "05B" / "05C" / "05D" (re-batching after
// the index entry was written). For each unresolved set, try sibling
// batches with the same year + numeric prefix but a later letter suffix. ---
function head_ok(string $url): bool {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: ' . UA],
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

foreach ($unresolvedByBatch as $batch => $rows) {
    // Parse "YYYY_TEOS_XML_NNA" → year, num, letter
    if (!preg_match('/^(\d{4})_TEOS_XML_(\d+)([A-Z])$/', $batch, $m)) {
        foreach ($rows as $r) lm("  XML not found: {$r['object_id']}_public.xml (in $batch)");
        continue;
    }
    [$_, $y, $num, $letter] = $m;
    $remaining = $rows;
    foreach (range(ord($letter) + 1, ord('Z')) as $code) {
        if (!$remaining) break;
        $sibBatch = sprintf('%s_TEOS_XML_%s%s', $y, $num, chr($code));
        $sibZip   = CACHE_DIR . "/$sibBatch.zip";
        if (!file_exists($sibZip)) {
            $url = "https://apps.irs.gov/pub/epostcard/990/xml/$y/$sibBatch.zip";
            if (!head_ok($url)) break; // no more siblings in this series
            lm("downloading sibling $sibBatch.zip …");
            if (!http_download($url, $sibZip)) { lm("  FAILED: $sibBatch"); continue; }
            lm('  ok ' . number_format(filesize($sibZip)) . ' bytes');
        }
        $remaining = $processBatch($sibBatch, $remaining);
    }
    foreach ($remaining as $r) lm("  XML not found anywhere: {$r['object_id']}_public.xml (declared $batch)");
}

lm("done: $filingsDone filings, $rowsDone officer rows");
