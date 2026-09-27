<?php
/**
 * One-time seeding of the `museums` table. Takes a hardcoded list of museum
 * names+cities and resolves each to its EIN via ProPublica's search API.
 *
 * Run with `--dry-run` to print resolutions without writing.
 * Re-runs are safe — uses INSERT IGNORE.
 *
 *   php scripts/museums_seed.php [--dry-run]
 */

declare(strict_types=1);
ini_set('memory_limit', '256M');

$opts   = getopt('', ['dry-run']);
$dryRun = isset($opts['dry-run']);

const DB_NAME      = 'artist_networks';
const DB_USER      = 'artist_networks';
const DB_PASS_FILE = '/etc/artist-networks/db_pass';
const UA           = 'artist-networks/1.0 (https://networks.vaguespac.es) museum-seed';

$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    trim((string)file_get_contents(DB_PASS_FILE)),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function lm(string $m): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $m . "\n"); }

// Seed list. EIN hardcoded where verified; otherwise null and resolved by name.
// Curated: most-visited / most-funded US art museums.
$seed = [
    ['ein' => '131624086', 'name' => 'Metropolitan Museum of Art',          'city' => 'New York',     'state' => 'NY', 'slug' => 'met'],
    ['ein' => null,        'name' => 'Museum of Modern Art',                'city' => 'New York',     'state' => 'NY', 'slug' => 'moma'],
    ['ein' => null,        'name' => 'Solomon R Guggenheim Foundation',     'city' => 'New York',     'state' => 'NY', 'slug' => 'guggenheim'],
    ['ein' => null,        'name' => 'Whitney Museum of American Art',      'city' => 'New York',     'state' => 'NY', 'slug' => 'whitney'],
    ['ein' => null,        'name' => 'Brooklyn Museum',                     'city' => 'Brooklyn',     'state' => 'NY', 'slug' => 'brooklyn'],
    ['ein' => null,        'name' => 'New Museum of Contemporary Art',      'city' => 'New York',     'state' => 'NY', 'slug' => 'new-museum'],
    ['ein' => null,        'name' => 'Studio Museum in Harlem',             'city' => 'New York',     'state' => 'NY', 'slug' => 'studio-museum'],
    ['ein' => '237397946', 'name' => 'Dia Center for the Arts',             'city' => 'New York',     'state' => 'NY', 'slug' => 'dia'],
    ['ein' => null,        'name' => 'Frick Collection',                    'city' => 'New York',     'state' => 'NY', 'slug' => 'frick'],
    ['ein' => null,        'name' => 'Museum of the Moving Image',          'city' => 'Astoria',      'state' => 'NY', 'slug' => 'moving-image'],

    ['ein' => '952264067', 'name' => 'Los Angeles County Museum of Art',    'city' => 'Los Angeles',  'state' => 'CA', 'slug' => 'lacma'],
    ['ein' => null,        'name' => 'Museum of Contemporary Art',          'city' => 'Los Angeles',  'state' => 'CA', 'slug' => 'moca-la'],
    ['ein' => null,        'name' => 'San Francisco Museum of Modern Art',  'city' => 'San Francisco','state' => 'CA', 'slug' => 'sfmoma'],
    ['ein' => null,        'name' => 'Fine Arts Museums of San Francisco',  'city' => 'San Francisco','state' => 'CA', 'slug' => 'famsf'],
    ['ein' => null,        'name' => 'Hammer Museum',                       'city' => 'Los Angeles',  'state' => 'CA', 'slug' => 'hammer'],
    ['ein' => null,        'name' => 'Broad Art Foundation',                'city' => 'Los Angeles',  'state' => 'CA', 'slug' => 'broad'],
    ['ein' => null,        'name' => 'Getty Trust',                         'city' => 'Los Angeles',  'state' => 'CA', 'slug' => 'getty'],
    ['ein' => null,        'name' => 'Art Institute of Chicago',            'city' => 'Chicago',      'state' => 'IL', 'slug' => 'aic'],
    // MCA Chicago — no clear ProPublica match; add EIN manually when found

    ['ein' => null,        'name' => 'Museum of Fine Arts',                 'city' => 'Boston',       'state' => 'MA', 'slug' => 'mfa-boston'],
    ['ein' => null,        'name' => 'Isabella Stewart Gardner Museum',     'city' => 'Boston',       'state' => 'MA', 'slug' => 'gardner'],
    ['ein' => null,        'name' => 'Institute of Contemporary Art',       'city' => 'Boston',       'state' => 'MA', 'slug' => 'ica-boston'],
    ['ein' => null,        'name' => 'Massachusetts Museum of Contemporary Art', 'city' => 'North Adams', 'state' => 'MA', 'slug' => 'mass-moca'],
    // Harvard Art Museums — shares EIN with Harvard University; would muddy data, skip for now

    ['ein' => null,        'name' => 'Philadelphia Museum of Art',          'city' => 'Philadelphia', 'state' => 'PA', 'slug' => 'pma'],
    ['ein' => null,        'name' => 'Barnes Foundation',                   'city' => 'Philadelphia', 'state' => 'PA', 'slug' => 'barnes'],
    ['ein' => '250965280', 'name' => 'Carnegie Institute',                  'city' => 'Pittsburgh',   'state' => 'PA', 'slug' => 'carnegie'],

    ['ein' => null,        'name' => 'Detroit Institute of Arts',           'city' => 'Detroit',      'state' => 'MI', 'slug' => 'dia-detroit'],
    ['ein' => null,        'name' => 'Cleveland Museum of Art',             'city' => 'Cleveland',    'state' => 'OH', 'slug' => 'cleveland'],
    ['ein' => '310536653', 'name' => 'Cincinnati Museum Association',       'city' => 'Cincinnati',   'state' => 'OH', 'slug' => 'cincinnati'],
    ['ein' => null,        'name' => 'Indianapolis Museum of Art',          'city' => 'Indianapolis', 'state' => 'IN', 'slug' => 'newfields'],
    ['ein' => null,        'name' => 'Walker Art Center',                   'city' => 'Minneapolis',  'state' => 'MN', 'slug' => 'walker'],
    ['ein' => '410693915', 'name' => 'Minneapolis Society of Fine Arts',    'city' => 'Minneapolis',  'state' => 'MN', 'slug' => 'mia'],
    // Saint Louis Art Museum — funded by special tax district, no separate 501c3
    ['ein' => '446012977', 'name' => 'Nelson Gallery Foundation',           'city' => 'Kansas City',  'state' => 'MO', 'slug' => 'nelson-atkins'],
    ['ein' => '756036226', 'name' => 'Kimbell Art Foundation',              'city' => 'Fort Worth',   'state' => 'TX', 'slug' => 'kimbell'],
    ['ein' => null,        'name' => 'Dallas Museum of Art',                'city' => 'Dallas',       'state' => 'TX', 'slug' => 'dma'],
    ['ein' => null,        'name' => 'Menil Foundation',                    'city' => 'Houston',      'state' => 'TX', 'slug' => 'menil'],
    ['ein' => null,        'name' => 'Museum of Fine Arts',                 'city' => 'Houston',      'state' => 'TX', 'slug' => 'mfa-houston'],

    ['ein' => '580633971', 'name' => 'Robert W Woodruff Arts Center',       'city' => 'Atlanta',      'state' => 'GA', 'slug' => 'high'],
    ['ein' => null,        'name' => 'Norton Museum of Art',                'city' => 'West Palm Beach','state' => 'FL', 'slug' => 'norton'],
    ['ein' => null,        'name' => 'Perez Art Museum Miami',              'city' => 'Miami',        'state' => 'FL', 'slug' => 'pamm'],
    ['ein' => null,        'name' => 'Institute of Contemporary Art Miami', 'city' => 'Miami',        'state' => 'FL', 'slug' => 'ica-miami'],
    ['ein' => null,        'name' => 'Glenstone Foundation',                'city' => 'Potomac',      'state' => 'MD', 'slug' => 'glenstone'],
    ['ein' => null,        'name' => 'Walters Art Museum',                  'city' => 'Baltimore',    'state' => 'MD', 'slug' => 'walters'],
    ['ein' => null,        'name' => 'Phillips Collection',                 'city' => 'Washington',   'state' => 'DC', 'slug' => 'phillips'],
];

lm('seed candidates: ' . count($seed));

// resolve nulls via ProPublica search
function search_ein(string $name, string $state, ?string $cityHint = null): ?array {
    // Quoted phrase narrows fuzzy matching; state[id]=XX is the actual filter
    // syntax (not state=XX).
    $url = 'https://projects.propublica.org/nonprofits/api/v2/search.json?'
         . http_build_query(['q' => '"' . $name . '"', 'state[id]' => $state]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: ' . UA, 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = (string)curl_exec($ch);
    curl_close($ch);
    $j = json_decode($body, true);
    $orgs = $j['organizations'] ?? [];
    if (!$orgs) return null;
    // Prefer city match when there's a hint, otherwise take the first.
    $best = null; $bestScore = -1;
    foreach ($orgs as $o) {
        $score = 0;
        $orgName = strtolower($o['name'] ?? '');
        $needle  = strtolower($name);
        foreach (preg_split('/\s+/', $needle) as $w) {
            if (strlen($w) >= 4 && stripos($orgName, $w) !== false) $score++;
        }
        if ($cityHint && stripos((string)($o['city'] ?? ''), $cityHint) !== false) $score += 5;
        // Penalize obvious "Friends of"/"Council of"/"Society of" decorations
        if (preg_match('/^(friends|council|society|associates|trustees) of /i', $o['name'] ?? '')) $score -= 3;
        if ($score > $bestScore) { $bestScore = $score; $best = $o; }
    }
    if (!$best || $bestScore < 2) return null;
    return ['ein' => str_pad((string)$best['ein'], 9, '0', STR_PAD_LEFT),
            'name' => $best['name'],
            'city' => $best['city'] ?? '',
            'state' => $best['state'] ?? ''];
}

$ins = $pdo->prepare('INSERT IGNORE INTO museums (ein, name, city, state, slug, note) VALUES (?, ?, ?, ?, ?, ?)');
$resolved = 0; $skipped = 0;
foreach ($seed as $row) {
    $ein  = $row['ein'];
    $note = '';
    if (!$ein) {
        usleep(500000); // 500ms — be polite to ProPublica
        $hit = search_ein($row['name'], $row['state'], $row['city']);
        if (!$hit) {
            lm("  MISS: {$row['name']} ({$row['state']}) — no match");
            $skipped++;
            continue;
        }
        $ein  = $hit['ein'];
        $note = 'matched: ' . $hit['name'];
    }
    lm("  OK: $ein  {$row['name']}  ({$row['city']}, {$row['state']})" . ($note ? "  [$note]" : ''));
    if (!$dryRun) $ins->execute([$ein, $row['name'], $row['city'], $row['state'], $row['slug'], $note]);
    $resolved++;
}
lm("resolved: $resolved, skipped: $skipped");
