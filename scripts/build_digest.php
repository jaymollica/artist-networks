<?php
/**
 * Build the monthly Artist Networks digest from the most recent run of each
 * insight report. Writes a single markdown file to /var/log/artist-networks/
 * which send_digest_email.php picks up.
 *
 * This runs after every weekly insights refresh, but only the first-Sunday
 * build is emailed. Changes are diffed against the report runs as of the last
 * digest actually sent, so the email covers everything since subscribers last
 * heard from us rather than just the final week.
 *
 *   php scripts/build_digest.php [--date=YYYY-MM-DD] [--out=path]
 *
 * For each report registered in lib/reports_catalog.php this includes the
 * title, blurb, the first H2 section header, and the first ~3 sample rows /
 * lines from that section. The full report stays one click away.
 */

declare(strict_types=1);

const LOG_DIR = '/var/log/artist-networks';
const SITE_URL = 'https://networks.vaguespac.es';

$opts    = getopt('', ['date::', 'out::']);
$date    = $opts['date'] ?? date('Y-m-d');
$outPath = $opts['out']  ?? (LOG_DIR . '/digest-' . $date . '.md');

$reports = require __DIR__ . '/../lib/reports_catalog.php';
$reports = array_filter($reports, fn($r) => empty($r['hidden']));
require_once __DIR__ . '/../lib/digest_mail.php';

function lm(string $m): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $m . "\n"); }

/**
 * Date (YYYY-MM-DD) of the last digest emailed to subscribers before $date,
 * or null if none has been sent. Best-effort: on a DB failure we return null
 * and the diffs fall back to the immediately prior run.
 */
function last_sent_digest_date(string $date): ?string {
    try {
        // The LIKE pattern matches only full digests (digest-YYYY-MM-DD.md),
        // not ad-hoc --digest= paths.
        $st = dm_pdo()->prepare("SELECT MAX(last_digest_name) FROM digest_subscribers
                                 WHERE last_digest_name LIKE 'digest-____-__-__.md'
                                   AND last_digest_name < ?");
        $st->execute(['digest-' . $date . '.md']);
        $name = $st->fetchColumn();
        return $name ? substr((string)$name, 7, 10) : null;
    } catch (Throwable $e) {
        lm('last-sent lookup failed, diffing against the prior run: ' . $e->getMessage());
        return null;
    }
}

function latest_run(string $prefix): ?array {
    $paths = glob(LOG_DIR . '/' . $prefix . '*.md') ?: [];
    if (!$paths) return null;
    usort($paths, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $path = $paths[0];
    if (!preg_match('/-(\d{4}-\d{2}-\d{2})\.md$/', $path, $m)) return null;
    return ['date' => $m[1], 'path' => $path];
}

/**
 * The run to diff against. With $onOrBefore (YYYY-MM-DD) set, this is the
 * newest run dated on or before it — i.e. what the last emailed digest saw.
 * Without it, the run immediately before $skipPath.
 */
function prior_run(string $prefix, string $skipPath, ?string $onOrBefore = null): ?array {
    $paths = glob(LOG_DIR . '/' . $prefix . '*.md') ?: [];
    $paths = array_values(array_filter($paths, function ($p) use ($skipPath, $onOrBefore) {
        if ($p === $skipPath) return false;
        if ($onOrBefore === null) return true;
        return preg_match('/-(\d{4}-\d{2}-\d{2})\.md$/', $p, $m) && $m[1] <= $onOrBefore;
    }));
    if (!$paths) return null;
    usort($paths, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $path = $paths[0];
    if (!preg_match('/-(\d{4}-\d{2}-\d{2})\.md$/', $path, $m)) return null;
    return ['date' => $m[1], 'path' => $path];
}

/**
 * Pull the first content table out of a report and return its data rows
 * keyed by an "identity" string (the first markdown-linked name in the row,
 * stripped to its display text). Order is preserved → keys are rank.
 *
 * Returns [identity => rank, ...] where rank starts at 1. Skip if no table.
 */
function first_table_identities(string $md, int $limit = 10): array {
    $lines = preg_split('/\r?\n/', $md);
    $tableStart = false;
    $rows = [];
    $rank = 0;
    foreach ($lines as $line) {
        $isPipe = preg_match('/^\s*\|/', $line);
        if (!$tableStart) {
            if ($isPipe) $tableStart = $line;
            continue;
        }
        // skip the markdown separator row (--- |---: etc.)
        if (preg_match('/^\s*\|[-:|\s]+\|\s*$/', $line)) continue;
        if (!$isPipe) break;
        // grab the first markdown link's display text from the row
        if (preg_match('/\[([^\]]+)\]\([^)]+\)/', $line, $m)) {
            $id = trim($m[1]);
            if (!isset($rows[$id])) {
                $rows[$id] = ++$rank;
                if ($rank >= $limit) break;
            }
        }
    }
    return $rows;
}

/**
 * Narrative diff between current and prior runs of the same report. Surfaces
 * top-10 newcomers, dropouts, and major climbers. Returns a short markdown
 * paragraph, or '' if there's nothing meaningful to say.
 */
function narrate_diff(string $currentMd, ?string $priorMd, ?string $priorDate = null): string {
    if ($priorMd === null) return '';
    $cur = first_table_identities($currentMd, 10);
    $old = first_table_identities($priorMd, 10);
    if (!$cur || !$old) return '';
    $newcomers = array_diff_key($cur, $old);
    $dropouts  = array_diff_key($old, $cur);
    $bigMoves  = [];
    foreach ($cur as $id => $rank) {
        if (!isset($old[$id])) continue;
        $delta = $old[$id] - $rank;
        if (abs($delta) >= 3) $bigMoves[$id] = $delta;
    }

    $parts = [];
    if ($newcomers) {
        $names = array_slice(array_keys($newcomers), 0, 4);
        $parts[] = '**New in the top 10:** ' . implode(', ', $names) . '.';
    }
    if ($dropouts) {
        $names = array_slice(array_keys($dropouts), 0, 4);
        $parts[] = '**Dropped out:** ' . implode(', ', $names) . '.';
    }
    if ($bigMoves) {
        $moves = [];
        foreach (array_slice($bigMoves, 0, 3, true) as $id => $delta) {
            $arrow = $delta > 0 ? '↑' : '↓';
            $moves[] = "$id $arrow" . abs($delta);
        }
        $parts[] = '**Movers:** ' . implode(', ', $moves) . '.';
    }
    if (!$parts) return '';
    $prefix = $priorDate ? "_Since the $priorDate run:_ " : '';
    return $prefix . implode(' ', $parts) . "\n\n";
}

/**
 * Trustees-specific narrative. IRS 990 filings refresh annually per museum,
 * so the joined/departed totals are stable across most weekly runs. When the
 * numbers haven't moved we say so explicitly rather than implying churn.
 */
function narrate_trustees(string $md, ?string $priorMd = null, ?string $priorDate = null): string {
    $joined   = preg_match('/^(\d[\d,]*) trustees joined a board/m', $md, $m1) ? (int)str_replace(',', '', $m1[1]) : 0;
    $departed = preg_match('/^(\d[\d,]*) trustees rotated off/m',    $md, $m2) ? (int)str_replace(',', '', $m2[1]) : 0;
    if (!$joined && !$departed) return '';

    $priorJoined = $priorDeparted = null;
    if ($priorMd !== null) {
        if (preg_match('/^(\d[\d,]*) trustees joined a board/m', $priorMd, $pm1)) $priorJoined   = (int)str_replace(',', '', $pm1[1]);
        if (preg_match('/^(\d[\d,]*) trustees rotated off/m',    $priorMd, $pm2)) $priorDeparted = (int)str_replace(',', '', $pm2[1]);
    }

    $parts = [];
    if ($joined)   $parts[] = "$joined joiners";
    if ($departed) $parts[] = "$departed departures";
    $totals = implode(', ', $parts);

    if ($priorJoined !== null && $priorDeparted !== null && $joined === $priorJoined && $departed === $priorDeparted) {
        $since = $priorDate ? " — no change since $priorDate" : '';
        return "_Across the latest filings on file:_ {$totals}{$since}.\n\n";
    }
    if ($priorJoined !== null || $priorDeparted !== null) {
        $dJ = $joined   - (int)$priorJoined;
        $dD = $departed - (int)$priorDeparted;
        $deltaBits = [];
        if ($dJ) $deltaBits[] = sprintf('%+d joiners', $dJ);
        if ($dD) $deltaBits[] = sprintf('%+d departures', $dD);
        $delta = $deltaBits ? ' (' . implode(', ', $deltaBits) . ' since last digest)' : '';
        return "_Across the latest filings on file:_ {$totals}{$delta}.\n\n";
    }
    return "_Across the latest filings on file:_ {$totals}.\n\n";
}

/**
 * Donor-capture: detect museums whose tax_period advanced since the prior
 * digest's run, i.e. a fresh 990 was ingested. Returns a (ein => [name, prior,
 * current]) map sorted by museum name.
 *
 * The Section A table row format is:
 *   | rank | index | [Museum](propublica-url-ending-in-{EIN})…optional 990… (City, ST) | YYYY-MM | …
 */
function donor_capture_new_filings(string $currentMd, ?string $priorMd): array {
    if ($priorMd === null) return [];
    $pattern = '~\|\s*\d+\s*\|\s*-?\d+\s*\|\s*\[([^\]]+)\]\(https://projects\.propublica\.org/nonprofits/organizations/(\d+)\)[^|]*\|\s*(\d{4}-\d{2})\s*\|~';
    $extract = function(string $md) use ($pattern): array {
        preg_match_all($pattern, $md, $m, PREG_SET_ORDER);
        $rows = [];
        foreach ($m as $r) {
            // First occurrence per EIN (Section A is the canonical table)
            if (!isset($rows[$r[2]])) $rows[$r[2]] = ['name' => $r[1], 'period' => $r[3]];
        }
        return $rows;
    };
    $cur = $extract($currentMd);
    $old = $extract($priorMd);
    $advanced = [];
    foreach ($cur as $ein => $r) {
        if (!isset($old[$ein])) continue;
        if ($r['period'] > $old[$ein]['period']) {
            $advanced[$ein] = [
                'name'    => $r['name'],
                'prior'   => $old[$ein]['period'],
                'current' => $r['period'],
            ];
        }
    }
    uasort($advanced, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $advanced;
}

/**
 * Render the new-filings note appended inside the donor-capture section.
 */
function narrate_donor_capture(string $currentMd, ?string $priorMd, ?string $priorDate): string {
    $advanced = donor_capture_new_filings($currentMd, $priorMd);
    if (!$advanced) return '';
    $bits = [];
    foreach ($advanced as $r) {
        $bits[] = sprintf('%s (%s → %s)', $r['name'], $r['prior'], $r['current']);
    }
    $since = $priorDate ? " since $priorDate" : '';
    return "_New 990 filings$since:_ " . implode('; ', $bits) . ".\n\n";
}

/**
 * One-line cadence note appended to each section's "Latest run" footer so
 * readers understand why a section may look unchanged. Returns '' for an
 * unrecognized cadence.
 */
function cadence_note(?string $cadence): string {
    switch ($cadence) {
        case 'weekly':  return ' · Wikipedia signals refresh weekly';
        case 'monthly': return ' · Met catalog and Wikipedia stats refresh monthly';
        case 'annual':  return ' · IRS 990 filings refresh annually per museum';
        case 'stable':  return ' · Getty graph rarely changes between runs';
        default:        return '';
    }
}

/**
 * Collect "headline" change candidates for a single report. Each item is
 * ['magnitude' => float, 'text' => string, 'report' => title, 'url' => string].
 *
 * Magnitude is derived from the *actual numbers* in each event (percentage
 * points, person counts, rank deltas) rather than hand-picked category
 * priors. The digest sorts all events across all reports by magnitude and
 * floats the top few to the "Top changes this month" section.
 *
 * Calibration: each event type's natural unit is scaled to put it on a
 * roughly comparable axis with other event types (a 50-percentage-point
 * audience-flight gap ≈ a 200-person trustee churn ≈ a #1 newcomer with a
 * 9-place displacement). The scaling constants are explicit below and are
 * the only category-level priors — once we have ~10 weekly snapshots of
 * each report's top-N, we should revisit this with per-report variance
 * baselines (z-scores against each report's own churn distribution).
 */
function collect_headlines(array $r, string $currentMd, ?string $priorMd, string $reportUrl): array {
    $out = [];
    $key = $r['slug'];

    // Donor-capture audience-flight ⚠ events: magnitude = pp gap between the
    // contribution-revenue and attendance changes. LACMA at -3.1% / +50.4%
    // yields a 53.5-pp gap. Anything ≥20pp is genuinely interesting.
    //
    // Inputs (museum_attendance CSV + 990 contribution revenue) only refresh
    // monthly at most, so without gating the same row repeats every digest.
    // Skip rows whose (museum, attendance%, contribution%) tuple is identical
    // in the prior digest's run.
    if ($key === 'donor-capture' || $key === 'donor_capture') {
        // New 990 filings since the prior digest — emit one aggregated headline.
        // Magnitude scales with the number of museums; even a single new filing
        // should surface (≥20, well above the 12 floor).
        $newFilings = donor_capture_new_filings($currentMd, $priorMd);
        if ($newFilings) {
            $names = array_map(fn($r) => $r['name'], array_values($newFilings));
            $shown = array_slice($names, 0, 3);
            $more  = count($names) - count($shown);
            $list  = implode(', ', $shown) . ($more > 0 ? sprintf(' (+%d more)', $more) : '');
            $count = count($newFilings);
            $out[] = [
                'magnitude' => max(20.0, $count * 10.0),
                'text'   => $count === 1
                    ? "**New 990 filing**: $list"
                    : "**$count museums posted new 990 filings**: $list",
                'report' => $r['title'],
                'url'    => $reportUrl,
            ];
        }

        $pattern = '/\|\s*⚠\s*\|\s*\[([^\]]+)\]\([^)]+\)[^|]*\|\s*([+-][\d.]+)%\s*\((\d{4})→(\d{4})\)\s*\|\s*([+-][\d.]+)%/u';
        $priorRows = [];
        if ($priorMd !== null && preg_match_all($pattern, $priorMd, $pm, PREG_SET_ORDER)) {
            foreach ($pm as $row) $priorRows[$row[1] . '|' . $row[2] . '|' . $row[5]] = true;
        }
        if (preg_match_all($pattern, $currentMd, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $sig = $row[1] . '|' . $row[2] . '|' . $row[5];
                if (isset($priorRows[$sig])) continue;
                $attDelta = (float)$row[2];
                $contrDelta = (float)$row[5];
                $gap = $contrDelta - $attDelta; // signed; positive = contribution rising while attendance flat/down
                $out[] = [
                    'magnitude' => abs($gap),
                    'text'   => sprintf(
                        "**%s**: attendance %+.1f%% (%s→%s) while contribution revenue %+.1f%% — a %.0f-point gap",
                        $row[1], $attDelta, $row[3], $row[4], $contrDelta, abs($gap)
                    ),
                    'report' => $r['title'],
                    'url'    => $reportUrl,
                ];
            }
        }
    }

    // Trustees churn: magnitude = (joiners + departures) ÷ 4. Scaling so a
    // ~200-person cycle (a typical full-year XML ingest) sits near a 50-pp
    // donor-capture gap.
    //
    // The joined/departed totals reflect each museum's *latest filing vs. its
    // prior filing*. Filings only roll over when a new 990 is ingested
    // (monthly cron, but each museum files annually) — so the numbers stay
    // static across most runs. Skip the headline unless either total
    // actually moved since the prior digest.
    if ($key === 'trustees') {
        $joined   = preg_match('/^(\d[\d,]*) trustees joined a board/m', $currentMd, $m1) ? (int)str_replace(',', '', $m1[1]) : 0;
        $departed = preg_match('/^(\d[\d,]*) trustees rotated off/m',    $currentMd, $m2) ? (int)str_replace(',', '', $m2[1]) : 0;
        $priorJoined = $priorDeparted = null;
        if ($priorMd !== null) {
            if (preg_match('/^(\d[\d,]*) trustees joined a board/m', $priorMd, $pm1)) $priorJoined   = (int)str_replace(',', '', $pm1[1]);
            if (preg_match('/^(\d[\d,]*) trustees rotated off/m',    $priorMd, $pm2)) $priorDeparted = (int)str_replace(',', '', $pm2[1]);
        }
        $changedVsPrior = ($priorJoined === null && $priorDeparted === null)
            ? true // first run with no prior to compare against — surface once
            : ($joined !== $priorJoined || $departed !== $priorDeparted);
        $churn = $joined + $departed;
        if ($churn > 0 && $changedVsPrior) {
            $deltaJoined   = $priorJoined   === null ? $joined   : $joined   - $priorJoined;
            $deltaDeparted = $priorDeparted === null ? $departed : $departed - $priorDeparted;
            $bits = [];
            if ($deltaJoined)   $bits[] = sprintf('**%+d new trustees** joined museum boards', $deltaJoined);
            if ($deltaDeparted) $bits[] = sprintf('%+d rotated off', $deltaDeparted);
            $netChurn = abs($deltaJoined) + abs($deltaDeparted);
            $out[] = [
                'magnitude' => $netChurn / 4.0,
                'text'   => implode(', ', $bits) . " since last digest",
                'report' => $r['title'],
                'url'    => $reportUrl,
            ];
        }
        // Trustees top-1 newcomer is also worth surfacing if the prior run
        // exists; reuse the generic logic below.
    }

    // Generic newcomers + movers for every report that has a prior run we
    // can diff against. Magnitude scales by displacement / rank delta.
    if ($priorMd !== null) {
        $cur = first_table_identities($currentMd, 10);
        $old = first_table_identities($priorMd, 10);
        if ($cur && $old) {
            // Newcomers: magnitude = (11 − rank) × 4. Rank 1 = 40, rank 10 = 4.
            // A rank-1 newcomer pushes 9 incumbents down; that's the surprise.
            foreach (array_diff_key($cur, $old) as $id => $rank) {
                $out[] = [
                    'magnitude' => (11 - $rank) * 4.0,
                    'text'   => "**$id** enters {$r['title']} at #$rank",
                    'report' => $r['title'],
                    'url'    => $reportUrl,
                ];
            }
            // Movers: magnitude = |Δrank| × 4. Ignore small ≤2 noise; a
            // 10-place jump (40) sits near a rank-1 newcomer.
            foreach ($cur as $id => $rank) {
                if (!isset($old[$id])) continue;
                $delta = $old[$id] - $rank;
                if (abs($delta) < 3) continue;
                $arrow = $delta > 0 ? 'climbs' : 'falls';
                $out[] = [
                    'magnitude' => abs($delta) * 4.0,
                    'text'   => "**$id** $arrow from #{$old[$id]} to #$rank in {$r['title']}",
                    'report' => $r['title'],
                    'url'    => $reportUrl,
                ];
            }
            // Dropouts: someone in the previous top 3 now absent from top 10
            // is a strong signal. magnitude = (4 − prior_rank) × 6 + 10.
            foreach (array_diff_key($old, $cur) as $id => $priorRank) {
                if ($priorRank > 3) continue;
                $out[] = [
                    'magnitude' => (4 - $priorRank) * 6.0 + 10,
                    'text'   => "**$id** falls out of {$r['title']} (was #$priorRank)",
                    'report' => $r['title'],
                    'url'    => $reportUrl,
                ];
            }
        }
    }

    return $out;
}

/**
 * Extract a compact teaser from a report markdown body. Returns markdown
 * containing (optionally) a section header, a brief prose lede, and either
 * the first table (up to 3 data rows) or the first 3 bullets/numbered items.
 *
 * Handles reports with three different shapes:
 *  - prose → table directly (Triangulation)
 *  - prose → H2 → table  (Sleeper, Gaps, Trustees)
 *  - prose → H2 → bullets (Chains, Hubs)
 * Meta sections (Sources, Methodology, Caveat, Legend) are skipped.
 */
function build_teaser(string $md, int $maxRows = 3, int $maxBullets = 3, int $maxProse = 2): string {
    $rawLines = preg_split('/\r?\n/', $md);

    // 1) Strip H1, italic timestamp lines (`_..._`), and leading blanks.
    $lines = [];
    foreach ($rawLines as $line) {
        if (preg_match('/^#\s+/', $line)) continue;
        if (preg_match('/^_.*_\s*$/', trim($line))) continue;
        $lines[] = $line;
    }
    while ($lines && trim($lines[0]) === '') array_shift($lines);

    // 2) Drop "meta" H2 sections entirely (Sources, Methodology, Caveat, etc.).
    $metaPattern = '/^(sources|methodology|caveat|about|note|legend)\b/i';
    $filtered = [];
    $inSkip = false;
    foreach ($lines as $line) {
        if (preg_match('/^##\s+(.+)/', $line, $m)) {
            $name = preg_replace('/^Section\s+[A-Z]\s*[—–-]\s*/u', '', trim($m[1]));
            $inSkip = (bool)preg_match($metaPattern, $name);
            if ($inSkip) continue;
        }
        if (!$inSkip) $filtered[] = $line;
    }

    // 3) Walk: capture section (first H2), prose (up to N lines), then first
    //    table OR bullets. Stop at the next H2.
    $section    = null;
    $prose      = [];
    $tableLines = [];
    $tableData  = 0;
    $bullets    = [];

    foreach ($filtered as $line) {
        $s = trim($line);

        if (preg_match('/^##\s+(.+)/', $line, $m)) {
            if ($section !== null) break;
            $section = preg_replace('/^Section\s+[A-Z]\s*[—–-]\s*/u', '', trim($m[1]));
            continue;
        }

        if (preg_match('/^\s*\|/', $line)) {
            if (count($tableLines) < 2) {
                $tableLines[] = $line;
            } elseif ($tableData < $maxRows) {
                $tableLines[] = $line; $tableData++;
            } else {
                break;
            }
            continue;
        }

        if (preg_match('/^(\d+\.|[-*])\s+/', $line)) {
            if (count($bullets) < $maxBullets) $bullets[] = $line;
            else break;
            continue;
        }

        if ($s === '') {
            if ($tableLines && $tableData >= 1) break;
            if ($bullets) break;
            continue;
        }

        // Plain prose / bold leader line — collect a couple of lines max,
        // and only before we've hit a table/bullets.
        if (!$tableLines && !$bullets && count($prose) < $maxProse) {
            $prose[] = $line;
        }
    }

    $out = '';
    if ($section)    $out .= "**$section**\n\n";
    if ($prose)      $out .= implode("\n", $prose) . "\n\n";
    if (count($tableLines) >= 3) $out .= implode("\n", $tableLines) . "\n\n";
    elseif ($bullets)            $out .= implode("\n", $bullets) . "\n\n";
    return $out;
}

// --- Assemble digest --------------------------------------------------

$niceDate = date('F j, Y', strtotime($date));
$md  = "# Artist Networks digest — $niceDate\n\n";
// Only mention the cadences of reports that are actually in this digest.
$cadenceBlurbs = [
    'weekly'  => 'Wikipedia signals weekly',
    'monthly' => 'Met catalog monthly',
    'annual'  => 'IRS 990 filings annually per museum',
];
$cadences = array_intersect_key($cadenceBlurbs, array_flip(array_column($reports, 'cadence')));
$md .= "A monthly roundup of findings across the Artist Networks insight suite. Reports update at different cadences: " . implode(', ', $cadences) . ". Each section links to the full report.\n\n";

// Diff baseline: the runs the last emailed digest was built from.
$baseline = last_sent_digest_date($date);
lm('diff baseline: ' . ($baseline ?? 'prior run (no earlier digest sent)'));

// Precompute each report's run + URL once so the TOC links match the
// section headers. (Intra-page anchors are unreliable in email clients —
// Gmail's web client strips name/id attributes — so the TOC links straight
// to the full report on the site.)
$resolved = [];
$headlines = [];
foreach ($reports as $key => $r) {
    $run = latest_run($r['prefix']);
    $url = SITE_URL . '/reports.php?r=' . urlencode($r['slug']);
    if ($run) $url .= '&d=' . urlencode($run['date']);
    $resolved[$key] = ['report' => $r, 'run' => $run, 'url' => $url];

    if ($run) {
        $body  = (string)file_get_contents($run['path']);
        $prior = prior_run($r['prefix'], $run['path'], $baseline);
        $priorBody = $prior ? (string)file_get_contents($prior['path']) : null;
        foreach (collect_headlines($r, $body, $priorBody, $url) as $h) {
            $headlines[] = $h;
        }
    }
}

// Surface the top headlines across all reports — the "what's surprising
// this month" lede. Ordering is by the *actual magnitudes* of each event
// (pp gap, person churn, rank displacement), not by category priors.
// Capped at 6 so the digest stays scannable; lowest-signal events filter
// out at a magnitude floor of 12.
usort($headlines, fn($a, $b) => $b['magnitude'] <=> $a['magnitude']);
$topHeadlines = array_values(array_filter(
    array_slice($headlines, 0, 8),
    fn($h) => $h['magnitude'] >= 12
));
$topHeadlines = array_slice($topHeadlines, 0, 6);

if ($topHeadlines) {
    $md .= "## Top changes this month\n\n";
    $md .= "Ordered by the magnitude of each event, across every report.\n\n";
    foreach ($topHeadlines as $h) {
        $md .= "- {$h['text']} — [{$h['report']}]({$h['url']})\n";
    }
    $md .= "\n---\n\n";
}

$tocItems = [];
foreach ($resolved as $key => $x) {
    $tocItems[] = "- [{$x['report']['title']}]({$x['url']})";
}
$md .= "## In this digest\n\n" . implode("\n", $tocItems) . "\n\n---\n\n";

$missing = [];
foreach ($resolved as $key => $x) {
    $r = $x['report'];
    $run = $x['run'];
    $reportUrl = $x['url'];

    $md .= "## [{$r['title']}]($reportUrl)\n\n";
    $md .= "*{$r['blurb']}*\n\n";

    if (!$run) {
        $missing[] = $r['title'];
        $md .= "_No recent report file found — this section will refresh next run._\n\n";
    } else {
        $body = (string)file_get_contents($run['path']);
        if ($body !== '') {
            $md .= build_teaser($body);
        }
        // Narrative diff vs the prior digest's run, if any.
        $prior = prior_run($r['prefix'], $run['path'], $baseline);
        $priorBody = $prior ? (string)file_get_contents($prior['path']) : null;
        $narrative = narrate_diff($body, $priorBody, $prior['date'] ?? null);
        // Trustees gets a dedicated coming-and-going narrative instead of the
        // generic top-10 diff, since the joins/departures sections are exact.
        if ($key === 'trustees') {
            $tn = narrate_trustees($body, $priorBody, $prior['date'] ?? null);
            if ($tn) $narrative = $tn;
        }
        // Donor-capture: prepend a "new 990 filings since…" line when the
        // monthly XML ingest has picked up newer tax periods for any museum.
        if ($key === 'donor-capture' || $key === 'donor_capture') {
            $df = narrate_donor_capture($body, $priorBody, $prior['date'] ?? null);
            if ($df) $narrative = $df . $narrative;
        }
        if ($narrative !== '') $md .= $narrative;

        $md .= "[Read the full {$r['title']} report →]($reportUrl)";
        if (!empty($r['interactive_url'])) {
            $md .= " · [Try the interactive view →](" . SITE_URL . $r['interactive_url'] . ")";
        }
        $md .= "\n\n";
        $md .= "_Latest run: {$run['date']}" . cadence_note($r['cadence'] ?? null) . "_\n\n";
    }
    $md .= "---\n\n";
}

$md .= "## About Artist Networks\n\n";
$md .= "Artist Networks maps the social graph of art history, museums, and cultural attention.\n";
$md .= "Sources, methodology, and the full code base are at [networks.vaguespac.es/about.html](" . SITE_URL . "/about.html).\n";

if ($missing) {
    lm('reports missing a recent file: ' . implode(', ', $missing));
}

file_put_contents($outPath, $md);
lm("wrote $outPath (" . number_format(strlen($md)) . " bytes)");
echo $outPath . "\n";
