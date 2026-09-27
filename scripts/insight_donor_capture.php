<?php
/**
 * Donor-capture index — measures whether a museum's revenue mix tilts toward
 * donor dependence vs audience-driven program revenue, plus signs of
 * "monetize the building" behavior (rentals, store, special events).
 *
 *   php scripts/insight_donor_capture.php
 *
 * Uses financial line items from `museum_filings` plus optional annual
 * attendance from `museum_attendance` (curated from The Art Newspaper's
 * annual survey). When attendance data is present, the report surfaces
 * "audience flight" — museums whose attendance is flat-or-down while
 * contribution revenue rises, i.e. leaning on donors as the public drifts.
 */

declare(strict_types=1);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../lib/report_sources.php';

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

// Helper: percentage with sign-preserving handling for negative line items
function pct(?int $part, ?int $whole): ?float {
    if (!$whole || $part === null) return null;
    return 100.0 * $part / $whole;
}
function fmtPct(?float $p): string { return $p === null ? '—' : sprintf('%.1f%%', $p); }
function fmtMoney(?int $v): string { return $v === null ? '—' : '$' . number_format($v); }

// ------------------------------------------------------------------
// Pull each museum's most recent filing
// ------------------------------------------------------------------
$sql = "SELECT m.slug, m.name, m.city, m.state, m.ein,
               f.tax_period, f.totrevenue, f.totcntrbgfts, f.totprgmrevnue,
               f.grsincmembers, f.grsincother, f.netrntlinc, f.invstmntinc,
               f.netincsales, f.netincfndrsng, f.royaltsinc, f.totassetsend,
               f.pdf_url
        FROM museums m
        JOIN museum_filings f USING (ein)
        WHERE f.return_type = '990'
          AND (m.ein, f.tax_period) IN (
              SELECT ein, MAX(tax_period) FROM museum_filings
              WHERE return_type = '990' GROUP BY ein
          )";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
lm('museums with a current filing: ' . count($rows));

// Pre-compute composition + composite score per museum
$museums = [];
foreach ($rows as $r) {
    $tot   = (int)($r['totrevenue'] ?? 0);
    if ($tot <= 0) continue;
    $contr = (int)($r['totcntrbgfts'] ?? 0);
    $prog  = (int)($r['totprgmrevnue'] ?? 0);
    $inv   = (int)($r['invstmntinc']   ?? 0);
    $rent  = (int)($r['netrntlinc']    ?? 0);
    $sales = (int)($r['netincsales']   ?? 0);
    $fnd   = (int)($r['netincfndrsng'] ?? 0);
    $roy   = (int)($r['royaltsinc']    ?? 0);
    $other = $rent + $sales + $fnd + $roy;
    // "Monetize the building" = revenue from things that aren't core mission delivery.
    // Excludes investment income (that's endowment yield, not building monetization).
    $contrShare = pct($contr, $tot);
    $progShare  = pct($prog,  $tot);
    $invShare   = pct($inv,   $tot);
    $otherShare = pct($other, $tot);

    // Donor-capture index (preliminary). Higher = more donor-led.
    // Composition: weight contribution share + non-program revenue, subtract program share.
    $index = ($contrShare ?? 0) + 0.5 * ($otherShare ?? 0) - 0.7 * ($progShare ?? 0);

    $museums[] = [
        'slug' => $r['slug'], 'name' => $r['name'],
        'city' => $r['city'], 'state' => $r['state'], 'ein' => $r['ein'],
        'tax_period' => $r['tax_period'], 'pdf_url' => $r['pdf_url'],
        'tot' => $tot, 'contr' => $contr, 'prog' => $prog, 'inv' => $inv,
        'rent' => $rent, 'sales' => $sales, 'fnd' => $fnd, 'roy' => $roy,
        'contr_share' => $contrShare, 'prog_share' => $progShare,
        'inv_share' => $invShare, 'other_share' => $otherShare,
        'index' => $index,
    ];
}

// Helper to format a museum cell with name + ProPublica link + optional 990 PDF
function museum_cell(array $m): string {
    $out = sprintf('[%s](%s)', $m['name'], propublica_url($m['ein']));
    if (!empty($m['pdf_url'])) {
        $out .= sprintf(' ([990](%s))', $m['pdf_url']);
    }
    return $out;
}

// ------------------------------------------------------------------
// Output
// ------------------------------------------------------------------
$out = '/var/log/artist-networks/insight-donor-capture-' . date('Y-m-d') . '.md';
$fh  = fopen($out, 'w');
fwrite($fh, "# Donor-capture index — museum revenue mix\n\n_" . date('c') . "_\n\n");
fwrite($fh, "How much of each museum's revenue comes from donors vs from the audience (program revenue: tickets, membership, exhibition fees). Donor-led museums depend on a small number of large gifts; audience-led museums depend on broad public engagement. \"Building monetization\" tracks revenue from rentals, retail, and fundraising events — the operating model where the museum is a venue more than a program.\n\n");
fwrite($fh, "Financial figures from the most recent IRS Form 990 each museum has filed via ProPublica's Nonprofit Explorer. Where annual attendance is available, Section F pairs it with contribution-revenue trends to surface museums whose audiences are drifting while donor revenue rises.\n\n");

// Section A — Composite donor-capture index ranking
fwrite($fh, "## Section A — Composite donor-capture index\n\n");
fwrite($fh, "Higher score = more donor-dependent revenue mix. Combines contribution share, non-program/non-investment share (rentals/store/events), and a negative weight on program share.\n\n");
fwrite($fh, "| Rank | Index | Museum | Filing | Contribution share | Program share | Other (rentals/store/events) |\n");
fwrite($fh, "|---:|---:|---|---|---:|---:|---:|\n");
usort($museums, fn($a, $b) => $b['index'] <=> $a['index']);
$rank = 1;
foreach ($museums as $m) {
    fwrite($fh, sprintf("| %d | %.0f | %s (%s, %s) | %s | %s | %s | %s |\n",
        $rank++, $m['index'],
        museum_cell($m), $m['city'], $m['state'],
        substr($m['tax_period'], 0, 4) . '-' . substr($m['tax_period'], 4, 2),
        fmtPct($m['contr_share']), fmtPct($m['prog_share']), fmtPct($m['other_share'])
    ));
}
fwrite($fh, "\n");

// Section B — Audience-led end of the spectrum
fwrite($fh, "## Section B — Most audience-led (highest program-revenue share)\n\n");
fwrite($fh, "Museums whose revenue is most weighted toward tickets, membership, and exhibition fees — the audience-engagement signal.\n\n");
fwrite($fh, "| Rank | Program share | Museum | Total revenue | Program revenue | Contribution revenue |\n");
fwrite($fh, "|---:|---:|---|---:|---:|---:|\n");
usort($museums, fn($a, $b) => ($b['prog_share'] ?? -1) <=> ($a['prog_share'] ?? -1));
$rank = 1;
foreach (array_slice($museums, 0, 15) as $m) {
    fwrite($fh, sprintf("| %d | %s | %s | %s | %s | %s |\n",
        $rank++, fmtPct($m['prog_share']),
        museum_cell($m),
        fmtMoney($m['tot']),
        fmtMoney($m['prog']),
        fmtMoney($m['contr'])
    ));
}
fwrite($fh, "\n");

// Section C — Building monetization
fwrite($fh, "## Section C — Most \"building-monetized\" (rentals + retail + events)\n\n");
fwrite($fh, "Museums earning the largest share of revenue from rentals, the gift shop, and special events — the operating model where the museum functions as a venue more than as a program.\n\n");
fwrite($fh, "| Rank | Other share | Net rental | Net retail | Net fundraising events | Museum |\n");
fwrite($fh, "|---:|---:|---:|---:|---:|---|\n");
usort($museums, fn($a, $b) => ($b['other_share'] ?? -1) <=> ($a['other_share'] ?? -1));
$rank = 1;
foreach (array_slice($museums, 0, 15) as $m) {
    fwrite($fh, sprintf("| %d | %s | %s | %s | %s | %s |\n",
        $rank++, fmtPct($m['other_share']),
        fmtMoney($m['rent']), fmtMoney($m['sales']), fmtMoney($m['fnd']),
        museum_cell($m)
    ));
}
fwrite($fh, "\n");

// Section D — Trend: contribution share, latest vs 5 years prior
fwrite($fh, "## Section D — Drift toward (or away from) donor dependence\n\n");
fwrite($fh, "Change in contribution share between the latest filing and the filing ~5 years prior. Positive = museum is becoming more donor-dependent over time.\n\n");
fwrite($fh, "| Δ contribution share | Latest | 5yr prior | Museum |\n");
fwrite($fh, "|---:|---:|---:|---|\n");
$trend = [];
$sql = "SELECT m.slug, m.name, m.ein,
               (SELECT f.totcntrbgfts FROM museum_filings f WHERE f.ein=m.ein AND f.return_type='990' ORDER BY f.tax_period DESC LIMIT 1) AS latest_contr,
               (SELECT f.totrevenue   FROM museum_filings f WHERE f.ein=m.ein AND f.return_type='990' ORDER BY f.tax_period DESC LIMIT 1) AS latest_rev,
               (SELECT f.tax_period   FROM museum_filings f WHERE f.ein=m.ein AND f.return_type='990' ORDER BY f.tax_period DESC LIMIT 1) AS latest_period,
               (SELECT f.totcntrbgfts FROM museum_filings f WHERE f.ein=m.ein AND f.return_type='990' ORDER BY f.tax_period DESC LIMIT 1 OFFSET 5) AS prior_contr,
               (SELECT f.totrevenue   FROM museum_filings f WHERE f.ein=m.ein AND f.return_type='990' ORDER BY f.tax_period DESC LIMIT 1 OFFSET 5) AS prior_rev,
               (SELECT f.tax_period   FROM museum_filings f WHERE f.ein=m.ein AND f.return_type='990' ORDER BY f.tax_period DESC LIMIT 1 OFFSET 5) AS prior_period
        FROM museums m";
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!$r['latest_rev'] || !$r['prior_rev']) continue;
    $latestShare = 100.0 * (int)$r['latest_contr'] / (int)$r['latest_rev'];
    $priorShare  = 100.0 * (int)$r['prior_contr']  / (int)$r['prior_rev'];
    $trend[] = ['name' => $r['name'], 'ein' => $r['ein'],
                'latest' => $latestShare, 'prior' => $priorShare,
                'delta' => $latestShare - $priorShare,
                'latest_period' => $r['latest_period'], 'prior_period' => $r['prior_period']];
}
usort($trend, fn($a, $b) => abs($b['delta']) <=> abs($a['delta']));
foreach (array_slice($trend, 0, 20) as $t) {
    fwrite($fh, sprintf("| %+.1f pp | %.1f%% (%s) | %.1f%% (%s) | [%s](%s) |\n",
        $t['delta'],
        $t['latest'], substr($t['latest_period'], 0, 4),
        $t['prior'],  substr($t['prior_period'], 0, 4),
        $t['name'], propublica_url($t['ein'])
    ));
}
fwrite($fh, "\n");

// Section E — Compensation concentration (top exec / total expense)
fwrite($fh, "## Section E — Paid-exec compensation concentration\n\n");
fwrite($fh, "Top-paid officer's compensation as a share of total functional expense, latest filing. High share suggests a few execs absorb a disproportionate share of operating budget.\n\n");
fwrite($fh, "| Top paid | % of expenses | Museum | Total expenses |\n");
fwrite($fh, "|---:|---:|---|---:|\n");
$compRows = $pdo->query("
    SELECT m.name, m.ein,
           (SELECT MAX(mo.compensation) FROM museum_officers mo
              WHERE mo.ein = m.ein) AS top_pay,
           (SELECT totfuncexpns FROM museum_filings f
              WHERE f.ein = m.ein AND f.return_type='990'
              ORDER BY f.tax_period DESC LIMIT 1) AS expenses
    FROM museums m
    HAVING top_pay IS NOT NULL AND expenses IS NOT NULL AND expenses > 0
    ORDER BY top_pay / expenses DESC
    LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
foreach ($compRows as $c) {
    $pct = 100.0 * (int)$c['top_pay'] / (int)$c['expenses'];
    fwrite($fh, sprintf("| %s | %.3f%% | [%s](%s) | %s |\n",
        fmtMoney((int)$c['top_pay']), $pct,
        $c['name'], propublica_url($c['ein']),
        fmtMoney((int)$c['expenses'])
    ));
}
fwrite($fh, "\n");

// ------------------------------------------------------------------
// Section F — Audience flight: attendance trend vs donor-revenue trend
// ------------------------------------------------------------------
// Attendance comes from museum_attendance (curated from The Art Newspaper's
// annual visitor survey). We pair each museum's most recent two attendance
// years with its two most recent fiscal filings, computing YoY % change for
// both. Museums with attendance flat-or-down AND contribution revenue up
// are the "audience drifting, donors stepping in" signal — exactly the
// dynamic Pérez Art Museum's revenue-side experiments (rooftop billboards
// for ads, etc.) make visible.
$attRows = $pdo->query("SELECT ein, year, attendance FROM museum_attendance
                        ORDER BY ein, year")->fetchAll(PDO::FETCH_ASSOC);
$attByEin = [];
foreach ($attRows as $r) {
    $ein = str_pad((string)$r['ein'], 9, '0', STR_PAD_LEFT);
    $attByEin[$ein][(int)$r['year']] = (int)$r['attendance'];
}

$filingsByEin = [];
foreach ($pdo->query("SELECT ein, tax_period, totcntrbgfts, totprgmrevnue, totrevenue
                      FROM museum_filings WHERE return_type='990'
                      ORDER BY ein, tax_period")->fetchAll(PDO::FETCH_ASSOC) as $f) {
    $ein = str_pad((string)$f['ein'], 9, '0', STR_PAD_LEFT);
    $filingsByEin[$ein][$f['tax_period']] = $f;
}

$audience = [];
foreach ($museums as $m) {
    $ein = $m['ein'];
    $att = $attByEin[$ein] ?? [];
    if (count($att) < 2) continue;
    ksort($att);
    $years = array_keys($att);
    $latestY = end($years);
    $priorY  = $years[count($years) - 2];
    $attDelta = ($att[$latestY] - $att[$priorY]) / max(1, $att[$priorY]) * 100.0;

    // Match the two most recent filings.
    $filings = $filingsByEin[$ein] ?? [];
    if (count($filings) < 2) continue;
    ksort($filings);
    $fkeys = array_keys($filings);
    $latestF = $filings[end($fkeys)];
    $priorF  = $filings[$fkeys[count($fkeys) - 2]];
    if (!$latestF['totcntrbgfts'] || !$priorF['totcntrbgfts']) continue;
    $contrDelta = ((int)$latestF['totcntrbgfts'] - (int)$priorF['totcntrbgfts'])
                  / max(1, (int)$priorF['totcntrbgfts']) * 100.0;
    $perAttendee = $att[$latestY] > 0
        ? (int)$latestF['totcntrbgfts'] / $att[$latestY] : null;

    $audience[] = [
        'museum'      => $m,
        'latest_year' => $latestY, 'prior_year' => $priorY,
        'att_latest'  => $att[$latestY], 'att_prior' => $att[$priorY],
        'att_delta'   => $attDelta,
        'contr_delta' => $contrDelta,
        'per_attendee'=> $perAttendee,
    ];
}

fwrite($fh, "## Section F — Audience flight (attendance vs donor revenue)\n\n");
if (!$audience) {
    fwrite($fh, "_No museum has both two years of attendance data and two filings on file yet. Add rows to `data/museum_attendance.csv` and re-run `scripts/ingest_attendance.php` to populate this section._\n\n");
} else {
    fwrite($fh, "Cross-references the museum's two most recent attendance years (from The Art Newspaper's annual visitor survey) with its two most recent IRS 990 filings. Museums flagged with **⚠** have **attendance flat-or-down while contribution revenue is up** — the signal that audience interest is fading and donors are filling the gap. *Contribution / visitor* shows how much donor money each visitor effectively brings in.\n\n");
    fwrite($fh, "| Flag | Museum | Attendance Δ | Contribution Δ | Latest attendance | Contribution / visitor |\n");
    fwrite($fh, "|---|---|---:|---:|---:|---:|\n");
    usort($audience, fn($a, $b) => $b['contr_delta'] - $b['att_delta'] <=> $a['contr_delta'] - $a['att_delta']);
    foreach ($audience as $a) {
        $flag = ($a['att_delta'] <= 0 && $a['contr_delta'] >= 5) ? '⚠' : '';
        fwrite($fh, sprintf(
            "| %s | %s | %+.1f%% (%d→%d) | %+.1f%% | %s | %s |\n",
            $flag,
            museum_cell($a['museum']),
            $a['att_delta'], $a['prior_year'], $a['latest_year'],
            $a['contr_delta'],
            number_format($a['att_latest']),
            $a['per_attendee'] === null ? '—' : '$' . number_format($a['per_attendee'], 2)
        ));
    }
    fwrite($fh, "\n");

    // Coverage footer — distinguish "no attendance data", "only one year of
    // attendance", and "has attendance but contribution data is missing".
    $haveTwoYearEins = array_unique(array_map(fn($a) => $a['museum']['ein'], $audience));
    $oneYear = []; $zeroYear = []; $noContr = [];
    foreach ($museums as $m) {
        if (in_array($m['ein'], $haveTwoYearEins, true)) continue;
        $attCount = isset($attByEin[$m['ein']]) ? count($attByEin[$m['ein']]) : 0;
        if ($attCount === 0)      $zeroYear[] = $m['name'];
        elseif ($attCount === 1)  $oneYear[]  = $m['name'];
        else                      $noContr[]  = $m['name']; // 2+ attendance years but filings/contribution missing
    }
    sort($oneYear); sort($zeroYear); sort($noContr);
    if ($oneYear) {
        fwrite($fh, "_Only one year of attendance on file (need ≥2 for YoY): " . implode('; ', $oneYear) . "._\n\n");
    }
    if ($noContr) {
        fwrite($fh, "_Has multi-year attendance but 990 contribution data isn't comparable (likely a 990-PF private foundation): " . implode('; ', $noContr) . "._\n\n");
    }
    if ($zeroYear) {
        fwrite($fh, "_No attendance data on file: " . implode('; ', $zeroYear) . "._\n\n");
    }
}

fwrite($fh, build_sources_footer(['propublica', 'irs_990', 'art_newspaper']));

fclose($fh);
lm('wrote ' . $out);
echo $out . "\n";
