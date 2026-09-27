<?php
/**
 * Refresh museum financials + officers for every row in `museums`.
 *
 *   - Financials: ProPublica JSON API per EIN (free, structured)
 *   - Officers:   scrape ProPublica's HTML filing pages (no structured
 *                 endpoint exposes officers as JSON)
 *
 *   php scripts/museums_refresh.php [--limit=N] [--ein=131624086] [--skip-officers]
 *
 * Re-runs are safe (REPLACE INTO). Per-request polite delay.
 */

declare(strict_types=1);
ini_set('memory_limit', '512M');

$opts          = getopt('', ['limit::', 'ein::', 'skip-officers']);
$limit         = isset($opts['limit'])         ? (int)$opts['limit'] : 0;
$onlyEin       = isset($opts['ein'])           ? (string)$opts['ein'] : null;
$skipOfficers  = isset($opts['skip-officers']);

const DB_NAME      = 'artist_networks';
const DB_USER      = 'artist_networks';
const DB_PASS_FILE = '/etc/artist-networks/db_pass';
const UA           = 'artist-networks/1.0 (https://networks.vaguespac.es) museum-refresh';

$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    trim((string)file_get_contents(DB_PASS_FILE)),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function lm(string $m): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $m . "\n"); }

function http_get(string $url, string $accept = 'application/json'): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: ' . UA, 'Accept: ' . $accept],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 25,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

// ------------------------------------------------------------------
// Load museums to process
// ------------------------------------------------------------------
$where = $onlyEin ? 'WHERE ein = ?' : '';
$sql   = "SELECT ein, name, slug FROM museums $where ORDER BY slug";
$st    = $pdo->prepare($sql);
$st->execute($onlyEin ? [$onlyEin] : []);
$museums = $st->fetchAll(PDO::FETCH_ASSOC);
if ($limit > 0) $museums = array_slice($museums, 0, $limit);
lm('museums to refresh: ' . count($museums));

$insFiling = $pdo->prepare("REPLACE INTO museum_filings
    (ein, tax_period, return_type, totrevenue, totcntrbgfts, totprgmrevnue,
     grsincmembers, grsincother, netrntlinc, invstmntinc, netincsales,
     netincfndrsng, royaltsinc, totfuncexpns, totassetsend,
     pdf_url, source, ingested_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'propublica', NOW())");
$insOfficer = $pdo->prepare("REPLACE INTO museum_officers
    (ein, tax_period, person_name, title_raw, role, compensation, hours_per_week, ingested_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");

// ------------------------------------------------------------------
// Title normalizer: classify Part VII title text → role
// ------------------------------------------------------------------
function classify_role(string $title, ?int $compensation, ?float $hours): string {
    $t = strtolower($title);
    // Compensation > $50k is overwhelmingly paid staff, regardless of what
    // the title text contains. Real trustees serve unpaid or with token comp.
    if ($compensation !== null && $compensation > 50000) return 'paid_exec';
    // Unambiguous trustee/board markers
    if (preg_match('/\btrustee\b|\bchair\b|\bvice ?chair\b|\bchairman\b|\bchairwoman\b|\bchairperson\b|\bboard member\b|\bemeritus\b|\bex[- ]officio\b|\bhonorary\b|\bcouncil member\b|\bgovernor\b|\bregent\b|\bcommissioner\b|\bpresident of board\b/', $t)) {
        return 'trustee';
    }
    // Catch unpaid "President" / "Director" / "Secretary" / "Treasurer" with no
    // compensation — these are governance roles, not staff.
    if (preg_match('/\b(president|director|secretary|treasurer|vice president)\b/', $t)
        && !preg_match('/\bof\b|chief|executive|managing|deputy|senior|operating|financial|technology|communications|advancement|development|curatorial|education/', $t)
        && ($compensation === null || $compensation < 5000)) {
        return 'trustee';
    }
    // Anything else with meaningful exec language
    if (preg_match('/\bchief\b|\bsenior\b|\bdeputy\b|\bexecutive\b|\bcurator\b|\bregistrar\b|\bofficer\b|\bcontroller\b|\bcoo\b|\bcfo\b|\bceo\b|\bdirector of\b/', $t)) {
        return 'paid_exec';
    }
    return 'other';
}

// ------------------------------------------------------------------
// HTML officer parser. ProPublica renders officer table as:
//   <th>Key Employees and Officers</th>
//   followed by per-officer rows containing
//     <div>NAME</div>
//     <span>(TITLE)</span>
//     numeric cells for hours, salary
// We extract via DOMDocument + XPath.
// ------------------------------------------------------------------
/**
 * Parse ALL officer tables on the org page (one per filing year) and return
 * them keyed by table index. Caller maps index → tax_period via the JSON
 * filings list (which is also ordered newest-first).
 */
function parse_officers_html(string $html): array {
    $tables = [];
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    if (!$doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING)) return $tables;
    libxml_clear_errors();
    $xp = new DOMXPath($doc);

    $tableNodes = $xp->query("//table[contains(@class,'employees')]");
    if (!$tableNodes) return $tables;
    foreach ($tableNodes as $i => $tbl) {
        $rows = $xp->query(".//tr[contains(@class,'employee-row')]", $tbl);
        $officers = [];
        if ($rows) foreach ($rows as $tr) {
            $name = '';
            $title = '';
            $salary = null;
            $firstTd = $xp->query("./td[1]", $tr);
            if ($firstTd && $firstTd->length) {
                $td = $firstTd->item(0);
                $clone = $td->cloneNode(true);
                $spans = $xp->query(".//span", $clone);
                foreach ($spans as $s) $s->parentNode->removeChild($s);
                $name = trim(preg_replace('/\s+/', ' ', $clone->textContent));
                $titleNode = $xp->query("./span[1]", $td);
                if ($titleNode && $titleNode->length) {
                    $title = trim($titleNode->item(0)->textContent, " \t\n\r\0\x0B()");
                }
            }
            $numTd = $xp->query("./td[contains(@class,'table__td--numeric')][1]", $tr);
            if ($numTd && $numTd->length) {
                $txt = trim($numTd->item(0)->textContent);
                $n = (int)preg_replace('/[^\d]/', '', $txt);
                if ($n > 0) $salary = $n;
            }
            if ($name && strlen($name) <= 200
                && stripos($name, 'see filing') === false
                && stripos($name, 'other people') === false
                && !preg_match('/^\s*→?\s*$/', $name)) {
                $officers[] = ['name' => $name, 'title' => $title, 'hours' => null, 'salary' => $salary];
            }
        }
        $tables[] = $officers;
    }
    return $tables;
}

// ------------------------------------------------------------------
// Process each museum
// ------------------------------------------------------------------
$filingCount = 0;
$officerCount = 0;
foreach ($museums as $m) {
    $ein = $m['ein'];
    lm("→ {$m['slug']} ($ein) {$m['name']}");

    // 1. Financials from JSON API
    [$code, $body] = http_get("https://projects.propublica.org/nonprofits/api/v2/organizations/$ein.json");
    usleep(500000); // 500ms
    if ($code !== 200) {
        lm("  financials: HTTP $code — skipping");
        continue;
    }
    $d = json_decode($body, true);
    $filings = $d['filings_with_data'] ?? [];
    lm("  filings with data: " . count($filings));
    foreach ($filings as $f) {
        $taxPrd = (string)($f['tax_prd'] ?? '');
        if (!preg_match('/^\d{6}$/', $taxPrd)) continue;
        // Skip 990T (unrelated business income tax) — keep only base 990s
        $returnType = '990';
        $insFiling->execute([
            $ein, $taxPrd, $returnType,
            $f['totrevenue'] ?? null,
            $f['totcntrbgfts'] ?? null,
            $f['totprgmrevnue'] ?? null,
            $f['grsincmembers'] ?? null,
            $f['grsincother'] ?? null,
            $f['netrntlinc'] ?? null,
            $f['invstmntinc'] ?? null,
            $f['netincsales'] ?? null,
            $f['netincfndrsng'] ?? null,
            $f['royaltsinc'] ?? null,
            $f['totfuncexpns'] ?? null,
            $f['totassetsend'] ?? null,
            $f['pdf_url'] ?? null,
        ]);
        $filingCount++;
    }

    if ($skipOfficers) continue;

    // 2. Officers from the org's main HTML page. ProPublica renders Part VII
    // officer lists under <table class="employees"> with one table per filing
    // year (newest first). The JSON filings list is also newest-first, so we
    // align them by index to attach the right tax_period to each table.
    if (!$filings) { lm('  no filing for officers'); continue; }
    [$code, $orgHtml] = http_get("https://projects.propublica.org/nonprofits/organizations/$ein", 'text/html');
    usleep(500000);
    if ($code !== 200) { lm("  org page: HTTP $code — skipping officers"); continue; }
    $tables = parse_officers_html($orgHtml);
    $totalParsed = 0;
    foreach ($tables as $idx => $officers) {
        $taxPrd = (string)($filings[$idx]['tax_prd'] ?? '');
        if (!preg_match('/^\d{6}$/', $taxPrd)) continue;
        foreach ($officers as $o) {
            $role = classify_role($o['title'] ?? '', $o['salary'] ?? null, $o['hours'] ?? null);
            $insOfficer->execute([
                $ein, $taxPrd,
                substr($o['name'], 0, 255),
                substr($o['title'] ?? '', 0, 255),
                $role,
                $o['salary'] ?? null,
                $o['hours'] ?? null,
            ]);
            $officerCount++;
            $totalParsed++;
        }
    }
    lm("  officers parsed across " . count($tables) . " filing tables: $totalParsed");
}

lm("done: $filingCount filing rows, $officerCount officer rows");
