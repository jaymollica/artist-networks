<?php
/**
 * Trustee networks — the social graph of museum boards. Who serves on
 * multiple boards, which museums share trustees, and which trustees act as
 * bridges between otherwise-disconnected institutions.
 *
 *   php scripts/insight_trustees.php
 *
 * Caveat: name matching is exact-string. Common names will alias; rare names
 * are reliable. Phase-2 work: canonicalize trustees with a `trustees` table
 * and resolve aliases.
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

/**
 * Canonicalize a trustee name for cross-museum matching.
 *
 * Output key: lowercase "firstname|generation|lastname". Middle names and
 * initials are dropped (handles "John H Mcfadden" ≡ "John Mcfadden").
 * Honorifics are stripped (Mr/Mrs/Ms/Dr/Sir/Esq/Phd/MD). Generational
 * suffixes (Jr/Sr/II/III/IV) are kept — "John Smith Jr" ≠ "John Smith III".
 *
 * Returns ['key' => string, 'display' => string]. Display is the longest
 * variant we've seen so output looks nicer.
 */
function canonical_name(string $raw): array {
    $orig = $raw;
    $s = strtolower(trim($raw));
    // strip honorific prefixes
    $s = preg_replace('/^(mr|mrs|ms|mister|miss|dr|sir|lord|lady|hon|prof|professor|rev|reverend|the\s+rev|the\s+hon)\.?\s+/i', '', $s);
    // strip honorific suffixes
    $s = preg_replace('/\s+(esq|esquire|phd|ph\.d|md|m\.d|jd|j\.d|cpa|c\.p\.a|dds|mba|mfa|cfa|cfp)\.?$/i', '', $s);
    // collapse whitespace
    $s = preg_replace('/\s+/', ' ', $s);
    $parts = explode(' ', trim($s));
    if (!$parts) return ['key' => $orig, 'display' => $raw];

    // generational suffix is the last word if it matches
    $suffix = '';
    if (count($parts) >= 2 && preg_match('/^(jr|sr|ii|iii|iv|v|vi)\.?$/', end($parts))) {
        $suffix = strtolower(rtrim(array_pop($parts), '.'));
    }
    if (count($parts) < 2) return ['key' => implode('|', [trim($s), '', '']), 'display' => $raw];
    $first = $parts[0];
    $last  = end($parts);
    // strip a trailing comma that sometimes lingers on the last token
    $last  = rtrim($last, ',');
    return ['key' => "$first|$suffix|$last", 'display' => $raw];
}

// ------------------------------------------------------------------
// Load board memberships. We treat any officer with role in (trustee, other)
// as a board member — `other` catches edge cases like ex-officio or honorary
// roles whose title didn't match the trustee regex.
// ------------------------------------------------------------------
$sql = "SELECT mo.person_name, mo.ein, mo.tax_period, m.slug, m.name, m.city, m.state
        FROM museum_officers mo
        JOIN museums m USING (ein)
        WHERE mo.role IN ('trustee','other')";
$memberships = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
lm('officer-filing rows: ' . count($memberships));

// Latest tax_period per museum is used to mark trustees as "departed" (their
// last appearance is older than the museum's most recent filing).
$latestByMuseum = [];
foreach ($pdo->query("SELECT ein, MAX(tax_period) AS latest FROM museum_officers GROUP BY ein") as $r) {
    $latestByMuseum[$r['ein']] = $r['latest'];
}

// Build trustee → set of EINs, keyed by canonical name. Track the longest
// observed display name and per-museum tenure (first_period, last_period).
$trustees = [];  // canon_key => ['display' => string, 'eins' => [ein => slug], 'tenures' => [ein => [first, last]]]
$museumByEin = [];
foreach ($memberships as $r) {
    $c = canonical_name($r['person_name']);
    $key = $c['key'];
    if (!isset($trustees[$key])) {
        $trustees[$key] = ['display' => $r['person_name'], 'eins' => [], 'tenures' => []];
    } elseif (strlen($r['person_name']) > strlen($trustees[$key]['display'])) {
        $trustees[$key]['display'] = $r['person_name'];
    }
    $trustees[$key]['eins'][$r['ein']] = $r['slug'];
    $p = $r['tax_period'];
    if (!isset($trustees[$key]['tenures'][$r['ein']])) {
        $trustees[$key]['tenures'][$r['ein']] = ['first' => $p, 'last' => $p];
    } else {
        $t =& $trustees[$key]['tenures'][$r['ein']];
        if ($p < $t['first']) $t['first'] = $p;
        if ($p > $t['last'])  $t['last']  = $p;
        unset($t);
    }
    $museumByEin[$r['ein']] = [
        'slug'    => $r['slug'],
        'name'    => $r['name'],
        'display' => '[' . $r['name'] . '](' . propublica_url($r['ein']) . ') (' . $r['city'] . ', ' . $r['state'] . ')',
    ];
}

/**
 * Format a (slug, first_period, last_period) cell as
 *   "[slug](url) (2018–2024)"               // current
 *   "[slug](url) (2014–2023, departed)"     // last seen older than museum's latest
 *   "[slug](url) (2024)"                    // single year
 */
function fmt_slug_with_tenure($ein, string $slug, array $tn, array $latestByMuseum): string {
    // EINs sometimes arrive as int (PHP foreach casts numeric-string array
    // keys). Normalize back to the zero-padded 9-char form.
    $einStr = str_pad((string)$ein, 9, '0', STR_PAD_LEFT);
    $firstY = (int)substr($tn['first'], 0, 4);
    $lastY  = (int)substr($tn['last'], 0, 4);
    $range  = ($firstY === $lastY) ? (string)$firstY : "{$firstY}–{$lastY}";
    $latest = $latestByMuseum[$einStr] ?? null;
    $departed = $latest && $tn['last'] < $latest;
    $suffix = ($departed ? "$range, departed" : $range);
    return sprintf('[%s](%s) (%s)', $slug, propublica_url($einStr), $suffix);
}

// Cross-board trustees only (≥2 museums)
$crossBoard = array_filter($trustees, fn($t) => count($t['eins']) >= 2);
uasort($crossBoard, fn($a, $b) => count($b['eins']) <=> count($a['eins']));
lm('cross-board trustees: ' . count($crossBoard));

// ------------------------------------------------------------------
// Museum-pair overlap: for each pair (A, B), count how many trustees serve both
// ------------------------------------------------------------------
$pairCounts = []; // "einA|einB" => count
$pairTrustees = []; // "einA|einB" => [display_name, ...]
foreach ($crossBoard as $key => $t) {
    $list = array_keys($t['eins']);
    sort($list);
    for ($i = 0; $i < count($list); $i++) {
        for ($j = $i + 1; $j < count($list); $j++) {
            $pkey = $list[$i] . '|' . $list[$j];
            $pairCounts[$pkey] = ($pairCounts[$pkey] ?? 0) + 1;
            $pairTrustees[$pkey][] = $t['display'];
        }
    }
}
arsort($pairCounts);
lm('museum-pair overlaps: ' . count($pairCounts));

// ------------------------------------------------------------------
// Build the museum-museum graph keyed by shared-trustee count, then compute
// degree centrality and betweenness via simple BFS (small graph: ≤43 nodes).
// ------------------------------------------------------------------
$adj = []; // ein => [ein => weight]
foreach ($pairCounts as $key => $cnt) {
    [$a, $b] = explode('|', $key);
    $adj[$a][$b] = $cnt;
    $adj[$b][$a] = $cnt;
}

// Degree centrality = number of unique museum neighbors (unweighted) and sum
// of weights (weighted)
$museumDegree = [];
foreach ($adj as $ein => $neighbors) {
    $museumDegree[$ein] = ['neighbors' => count($neighbors), 'weight' => array_sum($neighbors)];
}

// Betweenness centrality on the unweighted graph using Brandes' algorithm.
// Small graph, exact is fine.
$bw = array_fill_keys(array_keys($adj), 0.0);
foreach (array_keys($adj) as $s) {
    $stack = [];
    $pred  = array_fill_keys(array_keys($adj), []);
    $sigma = array_fill_keys(array_keys($adj), 0.0); $sigma[$s] = 1.0;
    $dist  = array_fill_keys(array_keys($adj), -1);  $dist[$s]  = 0;
    $queue = [$s];
    while ($queue) {
        $v = array_shift($queue);
        $stack[] = $v;
        foreach (array_keys($adj[$v] ?? []) as $w) {
            if ($dist[$w] < 0) { $dist[$w] = $dist[$v] + 1; $queue[] = $w; }
            if ($dist[$w] === $dist[$v] + 1) {
                $sigma[$w] += $sigma[$v];
                $pred[$w][] = $v;
            }
        }
    }
    $delta = array_fill_keys(array_keys($adj), 0.0);
    while ($stack) {
        $w = array_pop($stack);
        foreach ($pred[$w] as $v) {
            $delta[$v] += ($sigma[$v] / $sigma[$w]) * (1 + $delta[$w]);
        }
        if ($w !== $s) $bw[$w] += $delta[$w];
    }
}
// Divide by 2 (undirected graph counts each pair twice)
foreach ($bw as $k => $v) $bw[$k] = $v / 2;
arsort($bw);

// Bridge edges: edges whose endpoints both have high betweenness, identifying
// the connectors between otherwise-separate clusters. Simpler proxy: find
// trustees who are the ONLY connection between two specific museums.
$onlyLinks = []; // 'einA|einB' => trustee_name (when count == 1)
foreach ($pairCounts as $key => $cnt) {
    if ($cnt === 1) $onlyLinks[$key] = $pairTrustees[$key][0];
}

// ------------------------------------------------------------------
// Output
// ------------------------------------------------------------------
$out = '/var/log/artist-networks/insight-trustees-' . date('Y-m-d') . '.md';
$fh  = fopen($out, 'w');
fwrite($fh, "# Trustee networks — who sits on which boards\n\n_" . date('c') . "_\n\n");
fwrite($fh, "Applying the artist-networks paradigm to the people who govern museums. Sources: IRS Form 990 Part VII officer rosters, last ~10 years per museum, surfaced via ProPublica.\n\n");
fwrite($fh, "**Caveat.** Name matching is exact-string. \"John Smith\" on Museum A and \"John Smith\" on Museum B is assumed to be the same person — false positives are possible for common names. Equally, \"Jane Doe\" and \"Jane M. Doe\" are treated as different people — false negatives are also possible. Phase-2 work will canonicalize trustees and resolve aliases.\n\n");

// Section A
fwrite($fh, "## Section A — Trustees on multiple boards\n\n");
fwrite($fh, "People who appear on the officer roster of two or more museums across the historical window. Each museum is annotated with the tenure year range we have on file; `departed` flags a trustee whose last appearance predates the museum's most recent filing.\n\n");
fwrite($fh, "| Boards | Trustee | Museums (tenure) |\n");
fwrite($fh, "|---:|---|---|\n");
$rank = 0;
foreach ($crossBoard as $key => $t) {
    if (++$rank > 50) break;
    $cells = [];
    foreach ($t['eins'] as $ein => $slug) {
        $tn = $t['tenures'][$ein] ?? null;
        $cells[] = $tn ? fmt_slug_with_tenure($ein, $slug, $tn, $latestByMuseum)
                       : sprintf('[%s](%s)', $slug, propublica_url($ein));
    }
    sort($cells);
    fwrite($fh, sprintf("| %d | %s | %s |\n", count($t['eins']), $t['display'], implode(', ', $cells)));
}
fwrite($fh, "\n");

// Section B
fwrite($fh, "## Section B — Museum pairs with the most trustee overlap\n\n");
fwrite($fh, "Pairs of museums that share the most board members. High overlap signals a shared donor/governance circle — common for geographically co-located museums or those serving overlapping collector bases.\n\n");
fwrite($fh, "| Shared trustees | Museum A | Museum B | Names |\n");
fwrite($fh, "|---:|---|---|---|\n");
$rank = 0;
foreach ($pairCounts as $key => $cnt) {
    if (++$rank > 30) break;
    [$a, $b] = explode('|', $key);
    $names = $pairTrustees[$key];
    sort($names);
    fwrite($fh, sprintf("| %d | %s | %s | %s |\n",
        $cnt,
        $museumByEin[$a]['display'] ?? $a,
        $museumByEin[$b]['display'] ?? $b,
        implode('; ', array_slice($names, 0, 6)) . (count($names) > 6 ? '; …' : '')
    ));
}
fwrite($fh, "\n");

// Section C — Museum centrality
fwrite($fh, "## Section C — Most-connected museums (board-graph centrality)\n\n");
fwrite($fh, "Museums whose boards overlap with the most other museum boards. Higher rank = more embedded in the cross-institutional trustee network.\n\n");
fwrite($fh, "| Distinct museum neighbors | Total shared-trustee edges | Betweenness | Museum |\n");
fwrite($fh, "|---:|---:|---:|---|\n");
$rows = [];
foreach ($museumDegree as $ein => $d) {
    $rows[] = ['ein' => $ein, 'neighbors' => $d['neighbors'], 'weight' => $d['weight'], 'bw' => $bw[$ein] ?? 0];
}
usort($rows, fn($a, $b) => $b['neighbors'] <=> $a['neighbors'] ?: $b['weight'] <=> $a['weight']);
foreach (array_slice($rows, 0, 25) as $r) {
    fwrite($fh, sprintf("| %d | %d | %.2f | %s |\n",
        $r['neighbors'], $r['weight'], $r['bw'],
        $museumByEin[$r['ein']]['display'] ?? $r['ein']
    ));
}
fwrite($fh, "\n");

// ------------------------------------------------------------------
// Sections E & F — comings and goings.
// "Joiner" = trustee whose first appearance at this museum is the museum's
// latest filing.  "Departure" = trustee whose last appearance at this
// museum is the museum's *prior* filing (i.e. they were there last cycle,
// not in the latest one).  Trustees that never appear in the latest filing
// but disappeared more than one cycle ago aren't surfaced — those are old
// history, not news.
// ------------------------------------------------------------------
$periodsByMuseum = []; // ein => sorted ascending list of distinct tax_periods
foreach ($pdo->query("SELECT DISTINCT ein, tax_period FROM museum_officers ORDER BY ein, tax_period") as $r) {
    $periodsByMuseum[$r['ein']][] = $r['tax_period'];
}
$priorByMuseum = []; // ein => second-most-recent tax_period (or null)
foreach ($periodsByMuseum as $ein => $list) {
    $priorByMuseum[$ein] = count($list) >= 2 ? $list[count($list) - 2] : null;
}

$joiners = [];   // ['ein', 'year', 'trustee_key', 'trustee_display', 'board_count', 'other_eins']
$departures = []; // same shape, 'year' = year of last appearance
foreach ($trustees as $key => $t) {
    foreach ($t['tenures'] as $ein => $tn) {
        $latest = $latestByMuseum[$ein] ?? null;
        $prior  = $priorByMuseum[$ein]  ?? null;
        if (!$latest) continue;
        $boardCount = count($t['eins']);
        $other = array_diff(array_keys($t['eins']), [$ein]);
        if ($tn['first'] === $latest) {
            $joiners[] = [
                'ein' => $ein, 'year' => (int)substr($latest, 0, 4),
                'trustee_key' => $key, 'trustee_display' => $t['display'],
                'board_count' => $boardCount, 'other_eins' => $other,
            ];
        }
        if ($prior && $tn['last'] === $prior) {
            $departures[] = [
                'ein' => $ein, 'year' => (int)substr($prior, 0, 4),
                'trustee_key' => $key, 'trustee_display' => $t['display'],
                'board_count' => $boardCount, 'other_eins' => $other,
            ];
        }
    }
}
// Sort: cross-board trustees first (more interesting), then recency.
usort($joiners,    fn($a, $b) => $b['board_count'] <=> $a['board_count'] ?: $b['year'] <=> $a['year']);
usort($departures, fn($a, $b) => $b['board_count'] <=> $a['board_count'] ?: $b['year'] <=> $a['year']);
lm('joiners: ' . count($joiners) . ', departures: ' . count($departures));

// Section D — Bridge trustees (sole link between two museums)
fwrite($fh, "## Section D — Bridge trustees (sole link between two museums)\n\n");
fwrite($fh, "Trustees who provide the *only* observed board overlap between a given pair of museums. Removing this person would disconnect those two institutions in the trustee graph.\n\n");
fwrite($fh, "| Bridge trustee | Museum A | Museum B |\n");
fwrite($fh, "|---|---|---|\n");
// Order bridges by the betweenness of one of their endpoints so the most
// structurally important come first.
$bridgeRows = [];
foreach ($onlyLinks as $key => $name) {
    [$a, $b] = explode('|', $key);
    $score = ($bw[$a] ?? 0) + ($bw[$b] ?? 0);
    $bridgeRows[] = ['name' => $name, 'a' => $a, 'b' => $b, 'score' => $score];
}
usort($bridgeRows, fn($x, $y) => $y['score'] <=> $x['score']);
foreach (array_slice($bridgeRows, 0, 30) as $r) {
    fwrite($fh, sprintf("| %s | %s | %s |\n",
        $r['name'],
        $museumByEin[$r['a']]['name'] ?? $r['a'],
        $museumByEin[$r['b']]['name'] ?? $r['b']
    ));
}
fwrite($fh, "\n");

// Section E — Recently joined trustees
fwrite($fh, "## Section E — Recently joined\n\n");
fwrite($fh, sprintf("%d trustees joined a board this cycle (their first appearance at a museum equals that museum's most recent filing). Showing the top 50, sorted with cross-board figures first — these are the people bringing connections from other institutions into a board for the first time.\n\n", count($joiners)));
fwrite($fh, "| Year | Museum | New trustee | Also on |\n");
fwrite($fh, "|---|---|---|---|\n");
$rank = 0;
foreach ($joiners as $j) {
    if (++$rank > 50) break;
    $also = '';
    if ($j['other_eins']) {
        $others = [];
        foreach ($j['other_eins'] as $oe) $others[] = $museumByEin[$oe]['slug'] ?? $oe;
        sort($others);
        $also = implode(', ', $others);
    } else {
        $also = '—';
    }
    fwrite($fh, sprintf("| %d | %s | %s | %s |\n",
        $j['year'],
        $museumByEin[$j['ein']]['display'] ?? $j['ein'],
        $j['trustee_display'],
        $also
    ));
}
fwrite($fh, "\n");

// Section F — Recently departed trustees
fwrite($fh, "## Section F — Recently departed\n\n");
fwrite($fh, sprintf("%d trustees rotated off this cycle (appeared in a museum's prior filing but not the latest one). Showing the top 50, sorted with cross-board figures first.\n\n", count($departures)));
fwrite($fh, "| Last year | Museum | Departed trustee | Still on |\n");
fwrite($fh, "|---|---|---|---|\n");
$rank = 0;
foreach ($departures as $d) {
    if (++$rank > 50) break;
    // "Still on" lists boards where their last appearance equals that
    // museum's latest filing (i.e. still current there).
    $still = [];
    foreach ($d['other_eins'] as $oe) {
        $tn = $trustees[$d['trustee_key']]['tenures'][$oe] ?? null;
        $latestOther = $latestByMuseum[$oe] ?? null;
        if ($tn && $latestOther && $tn['last'] === $latestOther) {
            $still[] = $museumByEin[$oe]['slug'] ?? $oe;
        }
    }
    sort($still);
    fwrite($fh, sprintf("| %d | %s | %s | %s |\n",
        $d['year'],
        $museumByEin[$d['ein']]['display'] ?? $d['ein'],
        $d['trustee_display'],
        $still ? implode(', ', $still) : '—'
    ));
}
fwrite($fh, "\n");

fwrite($fh, build_sources_footer(['propublica', 'irs_990']));

fclose($fh);
lm('wrote ' . $out);
echo $out . "\n";
