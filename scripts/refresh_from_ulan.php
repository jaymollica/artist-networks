<?php
/**
 * Refresh artist data from Getty ULAN (vocab.getty.edu).
 *
 * Modes:
 *   --dry-run     fetch and diff only; no DB writes
 *   --limit=N     cap total ULANs processed (testing)
 *   --no-discover skip 1-hop expansion to new related ULANs
 *   --no-backup   skip the pre-run mysqldump
 *   --batch=N     ULANs per SPARQL VALUES batch (default 100)
 *   --digest=PATH override digest output path
 */

declare(strict_types=1);
ini_set('memory_limit', '1024M');
date_default_timezone_set('UTC');

$opts = getopt('', ['dry-run', 'limit::', 'no-discover', 'no-backup', 'batch::', 'digest::']);
$DRY_RUN     = isset($opts['dry-run']);
$LIMIT       = isset($opts['limit']) ? (int)$opts['limit'] : 0;
$DISCOVER    = !isset($opts['no-discover']);
$BACKUP      = !isset($opts['no-backup']);
$BATCH_SIZE  = isset($opts['batch']) ? max(10, (int)$opts['batch']) : 100;
$DIGEST_PATH = $opts['digest'] ?? ('/var/log/artist-networks/digest-' . date('Y-m-d-Hi') . '.md');

const DB_NAME    = 'artist_networks';
const DB_USER    = 'artist_networks';
const DB_PASS_FILE = '/root/.artist_networks_db_pass';
const SPARQL_URL = 'https://vocab.getty.edu/sparql.json';
const HTTP_UA    = 'artist-networks-refresh/1.0 (+https://networks.vaguespac.es)';
const BACKUP_DIR = '/var/backups/artist-networks';
const LOG_PATH   = '/var/log/artist-networks/refresh.log';

// ---------- bootstrap ----------

function logmsg(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    fwrite(STDERR, $line);
    @file_put_contents(LOG_PATH, $line, FILE_APPEND);
}

function fail(string $msg): never {
    logmsg('FATAL: ' . $msg);
    exit(1);
}

if (!is_readable(DB_PASS_FILE)) fail('cannot read ' . DB_PASS_FILE);
$dbpass = trim((string)file_get_contents(DB_PASS_FILE));
$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    $dbpass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_FOUND_ROWS => true]
);

logmsg(sprintf('starting refresh: dry_run=%s discover=%s backup=%s batch=%d limit=%d',
    $DRY_RUN ? 'yes' : 'no',
    $DISCOVER ? 'yes' : 'no',
    $BACKUP ? 'yes' : 'no',
    $BATCH_SIZE,
    $LIMIT
));

// ---------- backup ----------

if ($BACKUP && !$DRY_RUN) {
    $backupFile = BACKUP_DIR . '/pre-refresh-' . date('Ymd-His') . '.sql.gz';
    $cmd = sprintf(
        'mysqldump --single-transaction --quick --skip-lock-tables -u%s -p%s %s | gzip -1 > %s',
        escapeshellarg(DB_USER),
        escapeshellarg($dbpass),
        escapeshellarg(DB_NAME),
        escapeshellarg($backupFile)
    );
    logmsg("backing up DB to $backupFile");
    exec($cmd, $out, $rc);
    if ($rc !== 0) fail("mysqldump failed (rc=$rc)");
}

// ---------- HTTP / SPARQL client ----------

function http_post(string $url, array $form, int $retries = 3): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($form),
        CURLOPT_HTTPHEADER     => ['Accept: application/sparql-results+json', 'User-Agent: ' . HTTP_UA],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 120,
    ]);
    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body !== false && $code >= 200 && $code < 300) {
            curl_close($ch);
            return $body;
        }
        $err = curl_error($ch) ?: ('http ' . $code);
        logmsg("SPARQL attempt $attempt/$retries failed: $err — backing off");
        sleep((int) min(30, pow(2, $attempt)));
    }
    curl_close($ch);
    fail("SPARQL request failed after $retries attempts");
}

function sparql_query(string $query): array {
    $body = http_post(SPARQL_URL, ['query' => $query]);
    $data = json_decode($body, true);
    if (!isset($data['results']['bindings'])) fail('malformed SPARQL response');
    return $data['results']['bindings'];
}

function sparql_values(array $ulans): string {
    $items = [];
    foreach ($ulans as $u) {
        $items[] = 'ulan:' . (int)$u;
    }
    return implode(' ', $items);
}

// ---------- per-batch fetchers ----------

function fetch_names(array $ulans): array {
    // returns [ulan => ['prefGVP'=>str|null, 'prefLabels'=>[lang=>str], 'altLabels'=>set<str>]]
    $values = sparql_values($ulans);
    $q = <<<SPARQL
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
PREFIX gvp: <http://vocab.getty.edu/ontology#>
PREFIX ulan: <http://vocab.getty.edu/ulan/>
SELECT ?u ?prefGVP ?prefLabel ?altLabel WHERE {
  VALUES ?u { $values }
  OPTIONAL { ?u gvp:prefLabelGVP/gvp:term ?prefGVP }
  OPTIONAL { ?u skos:prefLabel ?prefLabel }
  OPTIONAL { ?u skos:altLabel ?altLabel }
}
SPARQL;
    $rows = sparql_query($q);
    $out = [];
    foreach ($rows as $r) {
        $u = (int) basename($r['u']['value']);
        $out[$u] ??= ['prefGVP' => null, 'prefLabels' => [], 'altLabels' => []];
        if (isset($r['prefGVP']))  $out[$u]['prefGVP'] = $r['prefGVP']['value'];
        if (isset($r['prefLabel'])) $out[$u]['prefLabels'][$r['prefLabel']['value']] = true;
        if (isset($r['altLabel']))  $out[$u]['altLabels'][$r['altLabel']['value']] = true;
    }
    return $out;
}

function fetch_biographies(array $ulans): array {
    // returns [ulan => [['text'=>str,'birth'=>?int,'death'=>?int,'preferred'=>bool], ...]]
    $values = sparql_values($ulans);
    $q = <<<SPARQL
PREFIX foaf: <http://xmlns.com/foaf/0.1/>
PREFIX gvp: <http://vocab.getty.edu/ontology#>
PREFIX ulan: <http://vocab.getty.edu/ulan/>
SELECT ?u ?bio ?desc ?birth ?death ?prefBio WHERE {
  VALUES ?u { $values }
  ?u foaf:focus/gvp:biography ?bio .
  OPTIONAL { ?bio gvp:estStart ?birth }
  OPTIONAL { ?bio gvp:estEnd ?death }
  OPTIONAL { ?bio <http://schema.org/description> ?desc }
  OPTIONAL { ?u foaf:focus/gvp:biographyPreferred ?prefBio }
}
SPARQL;
    $rows = sparql_query($q);
    $out = [];
    $seen = [];
    foreach ($rows as $r) {
        $u   = (int) basename($r['u']['value']);
        $bio = $r['bio']['value'];
        $key = $u . '|' . $bio;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $text = $r['desc']['value'] ?? '';
        if ($text === '') continue;
        $out[$u][] = [
            'text'      => $text,
            'birth'     => isset($r['birth']) ? (int)$r['birth']['value'] : 0,
            'death'     => isset($r['death']) ? (int)$r['death']['value'] : 0,
            'preferred' => isset($r['prefBio']) && $r['prefBio']['value'] === $bio,
        ];
    }
    return $out;
}

function fetch_notes(array $ulans): array {
    // returns [ulan => [text, ...]]
    $values = sparql_values($ulans);
    $q = <<<SPARQL
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
PREFIX dc: <http://purl.org/dc/elements/1.1/>
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX ulan: <http://vocab.getty.edu/ulan/>
SELECT ?u ?note WHERE {
  VALUES ?u { $values }
  ?u skos:scopeNote ?n .
  ?n rdf:value ?note .
}
SPARQL;
    $rows = sparql_query($q);
    $out = [];
    foreach ($rows as $r) {
        $u = (int) basename($r['u']['value']);
        $out[$u][] = $r['note']['value'];
    }
    return $out;
}

function fetch_relationships(array $ulans): array {
    // returns [ulan => [['type'=>int, 'target'=>int], ...]]
    // Getty exposes relationships via per-type predicates gvp:ulan{TYPE}_{label};
    // we cast a wide net by enumerating all gvp:ulan* predicates whose object is another ULAN.
    $values = sparql_values($ulans);
    $q = <<<SPARQL
PREFIX gvp: <http://vocab.getty.edu/ontology#>
PREFIX ulan: <http://vocab.getty.edu/ulan/>
SELECT ?u ?p ?target WHERE {
  VALUES ?u { $values }
  ?u ?p ?target .
  FILTER(STRSTARTS(STR(?p), "http://vocab.getty.edu/ontology#ulan"))
  FILTER(STRSTARTS(STR(?target), "http://vocab.getty.edu/ulan/"))
  FILTER(REGEX(STR(?target), "/ulan/[0-9]+\$"))
}
SPARQL;
    $rows = sparql_query($q);
    $out  = [];
    foreach ($rows as $r) {
        $u = (int) basename($r['u']['value']);
        $p = $r['p']['value'];
        // predicate looks like http://vocab.getty.edu/ontology#ulan1102_student_of-person
        if (!preg_match('~#ulan(\d+)_~', $p, $m)) continue;
        $type   = (int) $m[1];
        $target = (int) basename($r['target']['value']);
        $out[$u][] = ['type' => $type, 'target' => $target];
    }
    return $out;
}

function fetch_relationship_type(int $code): ?array {
    $q = <<<SPARQL
PREFIX gvp: <http://vocab.getty.edu/ontology#>
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
SELECT ?label ?reciprocal WHERE {
  ?p a gvp:RelationshipType ;
     gvp:relTypeID "$code" ;
     skos:prefLabel ?label .
  OPTIONAL { ?p gvp:reciprocal/gvp:relTypeID ?reciprocal }
  FILTER(LANG(?label) = "en" || LANG(?label) = "")
}
LIMIT 1
SPARQL;
    $rows = sparql_query($q);
    if (!$rows) return null;
    return [
        'label'      => $rows[0]['label']['value'],
        'reciprocal' => isset($rows[0]['reciprocal']) ? (int)$rows[0]['reciprocal']['value'] : 0,
    ];
}

// ---------- DB diff/apply helpers ----------

function fetch_db_state(PDO $pdo, array $ulans): array {
    if (!$ulans) return [];
    $place = implode(',', array_fill(0, count($ulans), '?'));
    $state = [];
    foreach ($ulans as $u) {
        $state[(int)$u] = ['aliases' => [], 'bios' => [], 'notes' => [], 'rels' => []];
    }

    $st = $pdo->prepare("SELECT id, ulan, alias, preferred, display FROM artist_aliases WHERE ulan IN ($place)");
    $st->execute(array_values($ulans));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $state[(int)$r['ulan']]['aliases'][$r['alias']] = $r;
    }

    $st = $pdo->prepare("SELECT id, ulan, biography, birth_year, death_year, preferred FROM biographies WHERE ulan IN ($place)");
    $st->execute(array_values($ulans));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $state[(int)$r['ulan']]['bios'][$r['biography']] = $r;
    }

    $st = $pdo->prepare("SELECT id, ulan, note FROM artist_notes WHERE ulan IN ($place)");
    $st->execute(array_values($ulans));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $state[(int)$r['ulan']]['notes'][$r['note']] = $r;
    }

    $st = $pdo->prepare("SELECT id, artist_ulan, related_ulan, relationship_type, start, end, notes FROM artist_relationships WHERE artist_ulan IN ($place)");
    $st->execute(array_values($ulans));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $key = $r['related_ulan'] . '|' . $r['relationship_type'];
        $state[(int)$r['artist_ulan']]['rels'][$key] = $r;
    }
    return $state;
}

function ensure_relationship_type(PDO $pdo, int $code, array &$cache, array &$digest, bool $dryRun): bool {
    if (isset($cache[$code])) return true;
    $st = $pdo->prepare('SELECT 1 FROM relationship_types WHERE getty_id=?');
    $st->execute([$code]);
    if ($st->fetchColumn()) { $cache[$code] = true; return true; }

    $info = fetch_relationship_type($code);
    if (!$info) {
        logmsg("WARN: unknown relationship type $code, skipping");
        return false;
    }
    $digest['new_relationship_types'][] = ['code' => $code, 'label' => $info['label']];
    if (!$dryRun) {
        $st = $pdo->prepare('INSERT INTO relationship_types (getty_id, relationship, reciprocal) VALUES (?, ?, ?)');
        $st->execute([$code, $info['label'], $info['reciprocal']]);
    }
    $cache[$code] = true;
    return true;
}

// ---------- main loop ----------

logmsg('loading seed ULAN list from artist_relationships…');
$seed = $pdo->query('SELECT DISTINCT artist_ulan FROM artist_relationships')->fetchAll(PDO::FETCH_COLUMN);
$seed = array_map('intval', $seed);
logmsg('seed size: ' . count($seed));

$queue        = $seed;
$processed    = [];
$discovered   = [];
$relTypeCache = [];

$digest = [
    'started_at'        => date('c'),
    'mode'              => $DRY_RUN ? 'dry-run' : 'apply',
    'seed_count'        => count($seed),
    'processed_count'   => 0,
    'discovered_count'  => 0,
    'aliases_added'     => 0,
    'aliases_updated'   => 0,
    'aliases_removed_from_getty' => 0,
    'bios_added'        => 0,
    'bios_updated'      => 0,
    'bios_removed_from_getty'    => 0,
    'notes_added'       => 0,
    'notes_removed_from_getty'   => 0,
    'rels_added'        => 0,
    'rels_removed_from_getty'    => 0,
    'preferred_name_changes' => [], // [ulan, old, new]
    'new_relationship_types' => [],
    'sample_added_artists'   => [],
    'sample_added_relationships' => [],
    'errors'            => [],
];

$totalDone = 0;
while ($queue) {
    $batch = array_splice($queue, 0, $BATCH_SIZE);
    $batch = array_values(array_unique(array_diff($batch, array_keys($processed))));
    if (!$batch) continue;
    if ($LIMIT && $totalDone + count($batch) > $LIMIT) {
        $batch = array_slice($batch, 0, $LIMIT - $totalDone);
        if (!$batch) break;
    }

    logmsg(sprintf('batch: %d ULANs (queue %d, processed %d)', count($batch), count($queue), $totalDone));

    try {
        $names = fetch_names($batch);
        $bios  = fetch_biographies($batch);
        $notes = fetch_notes($batch);
        $rels  = fetch_relationships($batch);
    } catch (Throwable $e) {
        logmsg('batch failed: ' . $e->getMessage());
        $digest['errors'][] = $e->getMessage();
        foreach ($batch as $u) $processed[$u] = true;
        $totalDone += count($batch);
        if ($LIMIT && $totalDone >= $LIMIT) { logmsg('limit reached'); break; }
        continue;
    }

    $dbState = fetch_db_state($pdo, $batch);

    if (!$DRY_RUN) $pdo->beginTransaction();
    try {
        foreach ($batch as $u) {
            $processed[$u] = true;

            // --- aliases ---
            $gettyAliases = []; // alias_text => ['preferred'=>bool, 'display'=>bool]
            $info = $names[$u] ?? null;
            if ($info) {
                if ($info['prefGVP']) {
                    $gettyAliases[$info['prefGVP']] = ['preferred' => true, 'display' => true];
                }
                foreach (array_keys($info['prefLabels']) as $pl) {
                    if (!isset($gettyAliases[$pl])) $gettyAliases[$pl] = ['preferred' => true, 'display' => false];
                }
                foreach (array_keys($info['altLabels']) as $al) {
                    if (!isset($gettyAliases[$al])) $gettyAliases[$al] = ['preferred' => false, 'display' => false];
                }
            }
            $dbAliases = $dbState[$u]['aliases'] ?? [];

            // detect preferred-name change
            $oldDisplay = null; foreach ($dbAliases as $a => $row) if ($row['display']) { $oldDisplay = $a; break; }
            $newDisplay = null; foreach ($gettyAliases as $a => $f) if ($f['display']) { $newDisplay = $a; break; }
            if ($oldDisplay !== null && $newDisplay !== null && $oldDisplay !== $newDisplay) {
                $digest['preferred_name_changes'][] = ['ulan' => $u, 'old' => $oldDisplay, 'new' => $newDisplay];
            }

            foreach ($gettyAliases as $alias => $flags) {
                if (!isset($dbAliases[$alias])) {
                    $digest['aliases_added']++;
                    if (count($digest['sample_added_artists']) < 30 && $flags['display']) {
                        $digest['sample_added_artists'][] = ['ulan' => $u, 'name' => $alias];
                    }
                    if (!$DRY_RUN) {
                        $st = $pdo->prepare('INSERT INTO artist_aliases (ulan, alias, preferred, display) VALUES (?, ?, ?, ?)');
                        $st->execute([$u, $alias, (int)$flags['preferred'], (int)$flags['display']]);
                    }
                } else {
                    $row = $dbAliases[$alias];
                    if ((int)$row['preferred'] !== (int)$flags['preferred'] || (int)$row['display'] !== (int)$flags['display']) {
                        $digest['aliases_updated']++;
                        if (!$DRY_RUN) {
                            $st = $pdo->prepare('UPDATE artist_aliases SET preferred=?, display=? WHERE id=?');
                            $st->execute([(int)$flags['preferred'], (int)$flags['display'], $row['id']]);
                        }
                    }
                }
            }
            // also clear stale display=1 on rows Getty no longer marks as display
            foreach ($dbAliases as $alias => $row) {
                if (!isset($gettyAliases[$alias])) {
                    $digest['aliases_removed_from_getty']++;
                    if ((int)$row['display'] === 1 || (int)$row['preferred'] === 1) {
                        // demote so the new Getty preferred wins as the visible display name
                        $digest['aliases_updated']++;
                        if (!$DRY_RUN) {
                            $st = $pdo->prepare('UPDATE artist_aliases SET preferred=0, display=0 WHERE id=?');
                            $st->execute([$row['id']]);
                        }
                    }
                }
            }

            // --- biographies ---
            $gettyBios = $bios[$u] ?? [];
            $dbBios    = $dbState[$u]['bios'] ?? [];
            foreach ($gettyBios as $b) {
                if (!isset($dbBios[$b['text']])) {
                    $digest['bios_added']++;
                    if (!$DRY_RUN) {
                        $st = $pdo->prepare('INSERT INTO biographies (ulan, biography, birth_year, death_year, preferred) VALUES (?, ?, ?, ?, ?)');
                        $st->execute([$u, $b['text'], $b['birth'], $b['death'], (int)$b['preferred']]);
                    }
                } else {
                    $row = $dbBios[$b['text']];
                    if ((int)$row['birth_year'] !== $b['birth']
                        || (int)$row['death_year'] !== $b['death']
                        || (int)$row['preferred'] !== (int)$b['preferred']) {
                        $digest['bios_updated']++;
                        if (!$DRY_RUN) {
                            $st = $pdo->prepare('UPDATE biographies SET birth_year=?, death_year=?, preferred=? WHERE id=?');
                            $st->execute([$b['birth'], $b['death'], (int)$b['preferred'], $row['id']]);
                        }
                    }
                }
            }
            $gettyBioTexts = array_column($gettyBios, 'text');
            foreach ($dbBios as $text => $row) {
                if (!in_array($text, $gettyBioTexts, true)) $digest['bios_removed_from_getty']++;
            }

            // --- notes ---
            $gettyNotes = $notes[$u] ?? [];
            $dbNotes    = $dbState[$u]['notes'] ?? [];
            foreach ($gettyNotes as $n) {
                if (!isset($dbNotes[$n])) {
                    $digest['notes_added']++;
                    if (!$DRY_RUN) {
                        $st = $pdo->prepare('INSERT INTO artist_notes (ulan, note) VALUES (?, ?)');
                        $st->execute([$u, $n]);
                    }
                }
            }
            foreach ($dbNotes as $n => $row) {
                if (!in_array($n, $gettyNotes, true)) $digest['notes_removed_from_getty']++;
            }

            // --- relationships ---
            $gettyRels = $rels[$u] ?? [];
            $dbRels    = $dbState[$u]['rels'] ?? [];
            foreach ($gettyRels as $r) {
                $key = $r['target'] . '|' . $r['type'];
                if (isset($dbRels[$key])) continue;
                if (!ensure_relationship_type($pdo, $r['type'], $relTypeCache, $digest, $DRY_RUN)) continue;
                $digest['rels_added']++;
                if (count($digest['sample_added_relationships']) < 30) {
                    $digest['sample_added_relationships'][] = ['from' => $u, 'to' => $r['target'], 'type' => $r['type']];
                }
                if (!$DRY_RUN) {
                    $st = $pdo->prepare('INSERT INTO artist_relationships (artist_ulan, relationship_type, related_ulan, start, end, notes) VALUES (?, ?, ?, 0, 0, "")');
                    $st->execute([$u, $r['type'], $r['target']]);
                }
                if ($DISCOVER && !isset($processed[$r['target']]) && !isset($discovered[$r['target']])) {
                    $discovered[$r['target']] = true;
                    $queue[] = $r['target'];
                }
            }
            foreach ($dbRels as $key => $row) {
                [$tgt, $typ] = explode('|', $key);
                $stillThere = false;
                foreach ($gettyRels as $r) if ($r['target'] === (int)$tgt && $r['type'] === (int)$typ) { $stillThere = true; break; }
                if (!$stillThere) $digest['rels_removed_from_getty']++;
            }
        }

        if (!$DRY_RUN) $pdo->commit();
    } catch (Throwable $e) {
        if (!$DRY_RUN && $pdo->inTransaction()) $pdo->rollBack();
        logmsg('apply error: ' . $e->getMessage());
        $digest['errors'][] = $e->getMessage();
    }

    $totalDone += count($batch);
    if ($LIMIT && $totalDone >= $LIMIT) { logmsg('limit reached'); break; }
}

$digest['processed_count']  = count($processed);
$digest['discovered_count'] = count($discovered);
$digest['finished_at']      = date('c');

// ---------- digest ----------

function name_for(PDO $pdo, int $ulan): string {
    static $cache = [];
    if (isset($cache[$ulan])) return $cache[$ulan];
    $st = $pdo->prepare('SELECT alias FROM artist_aliases WHERE ulan=? AND display=1 LIMIT 1');
    $st->execute([$ulan]);
    $name = (string)($st->fetchColumn() ?: '');
    if ($name === '') {
        $st = $pdo->prepare('SELECT alias FROM artist_aliases WHERE ulan=? ORDER BY preferred DESC LIMIT 1');
        $st->execute([$ulan]);
        $name = (string)($st->fetchColumn() ?: ('ULAN ' . $ulan));
    }
    return $cache[$ulan] = $name;
}

function rel_label(PDO $pdo, int $code): string {
    static $cache = [];
    if (isset($cache[$code])) return $cache[$code];
    $st = $pdo->prepare('SELECT relationship FROM relationship_types WHERE getty_id=? LIMIT 1');
    $st->execute([$code]);
    return $cache[$code] = (string)($st->fetchColumn() ?: ('type ' . $code));
}

$md  = "# Artist Networks — dataset refresh\n\n";
$md .= "- **Mode:** {$digest['mode']}\n";
$md .= "- **Started:** {$digest['started_at']}\n";
$md .= "- **Finished:** {$digest['finished_at']}\n";
$md .= "- **Artists processed:** {$digest['processed_count']} (seed {$digest['seed_count']}, discovered {$digest['discovered_count']})\n\n";

$md .= "## Summary of changes\n\n";
$md .= "| Category | Added | Updated | Removed |\n";
$md .= "|---|---:|---:|---:|\n";
$md .= "| Aliases (incl. preferred names) | {$digest['aliases_added']} | {$digest['aliases_updated']} | {$digest['aliases_removed_from_getty']} |\n";
$md .= "| Biographies | {$digest['bios_added']} | {$digest['bios_updated']} | {$digest['bios_removed_from_getty']} |\n";
$md .= "| Notes | {$digest['notes_added']} | — | {$digest['notes_removed_from_getty']} |\n";
$md .= "| Relationships | {$digest['rels_added']} | — | {$digest['rels_removed_from_getty']} |\n";
$md .= "| New relationship types | " . count($digest['new_relationship_types']) . " | — | — |\n\n";

if ($digest['preferred_name_changes']) {
    $md .= "## Preferred-name changes (top 20)\n\n";
    foreach (array_slice($digest['preferred_name_changes'], 0, 20) as $c) {
        $md .= "- **{$c['ulan']}**: `{$c['old']}` → `{$c['new']}`\n";
    }
    $md .= "\n";
}

if ($digest['new_relationship_types']) {
    $md .= "## New relationship types\n\n";
    foreach ($digest['new_relationship_types'] as $t) {
        $md .= "- `{$t['code']}` — {$t['label']}\n";
    }
    $md .= "\n";
}

if ($digest['sample_added_artists']) {
    $md .= "## Newly discovered artists (sample of " . count($digest['sample_added_artists']) . ")\n\n";
    foreach (array_slice($digest['sample_added_artists'], 0, 20) as $a) {
        $md .= "- [{$a['name']}](/?ulan={$a['ulan']})\n";
    }
    $md .= "\n";
}

if ($digest['sample_added_relationships']) {
    $md .= "## New relationships (sample of " . count($digest['sample_added_relationships']) . ")\n\n";
    foreach (array_slice($digest['sample_added_relationships'], 0, 20) as $r) {
        $from = name_for($pdo, $r['from']);
        $to   = name_for($pdo, $r['to']);
        $type = rel_label($pdo, $r['type']);
        $md  .= "- *{$from}* — {$type} — *{$to}*\n";
    }
    $md .= "\n";
}

if ($digest['errors']) {
    $md .= "## Errors\n\n";
    foreach (array_slice($digest['errors'], 0, 20) as $e) $md .= "- $e\n";
    $md .= "\n";
}

@file_put_contents($DIGEST_PATH, $md);
logmsg("digest written to $DIGEST_PATH");
echo $md;
