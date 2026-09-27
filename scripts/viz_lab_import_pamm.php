<?php
/**
 * Import Visualization Lab subjects from the Pérez Art Museum Miami (PAMM)
 * WordPress REST API. PAMM publishes genuine extended *visual descriptions* in
 * the ACF field `artwork_visual_description` on its `artwork` post type — the
 * accessibility artifact the lab is built around.
 *
 *   php scripts/viz_lab_import_pamm.php 2019.194 2019.195 2020.248
 *   php scripts/viz_lab_import_pamm.php https://www.pamm.org/en/artwork/2019.194
 *
 * Each artwork's image sits behind Cloudflare (blocks datacenter IPs), so we
 * mirror it locally at import time (viz_mirror_original falls back through the
 * weserv proxy) and store the local copy — runtime never hits PAMM again.
 */

declare(strict_types=1);
ini_set('memory_limit', '512M');

const DB_NAME      = 'artist_networks';
const DB_USER      = 'artist_networks';
const DB_PASS_FILE = '/etc/artist-networks/db_pass';
const PAMM_API     = 'https://www.pamm.org/wp-json/wp/v2';

$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    trim((string)file_get_contents(DB_PASS_FILE)),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

require_once __DIR__ . '/../lib/viz_lab.php';

// Separate the optional --lang=xx flag from the object-number/URL arguments.
// Spanish (or any non-en) descriptions become their own subjects (slug "…-es").
$lang = 'en';
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--lang=([a-z]{2})$/i', $a, $m)) { $lang = strtolower($m[1]); continue; }
    if (preg_match('#/(es|en)/(artwork|obra)/#i', $a, $m)) { $lang = strtolower($m[1]); }
    $args[] = $a;
}
if (!$args) {
    fwrite(STDERR, "usage: php viz_lab_import_pamm.php [--lang=es] <object-number|url> [more…]\n");
    exit(1);
}

function pamm_get(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) return null;
    $j = json_decode((string)$body, true);
    return is_array($j) ? $j : null;
}

function clean(?string $s): string {
    return trim(html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * PAMM's WP post slug isn't the object number and search doesn't index ACF, so
 * build a map object_number => post_id by paginating the (small) artwork set.
 */
function pamm_build_index(string $lang = 'en'): array {
    $index = [];
    for ($page = 1; $page <= 10; $page++) {
        $rows = pamm_get(PAMM_API . '/artwork?lang=' . $lang . '&per_page=100&page=' . $page . '&_fields=id,acf.artwork_object_number');
        if (!is_array($rows) || !$rows) break;
        foreach ($rows as $r) {
            $on = trim((string)($r['acf']['artwork_object_number'] ?? ''));
            if ($on !== '' && !empty($r['id'])) $index[$on] = (int)$r['id'];
        }
        if (count($rows) < 100) break;
    }
    return $index;
}

/** Resolve the artist name: ACF names field, else the first embedded artist's title. */
function pamm_artist(array $o): ?string {
    $n = clean($o['acf']['artwork_artist_names'] ?? '');
    if ($n !== '') return $n;
    foreach (($o['_embedded']['wp:term'] ?? []) as $group) {
        foreach ((array)$group as $term) {
            if (($term['taxonomy'] ?? '') === 'artist' && !empty($term['name'])) return clean($term['name']);
        }
    }
    $rel = $o['acf']['artist_artwork_relationships'] ?? null;
    if (is_array($rel) && $rel) {
        $first = $rel[0];
        $id = is_array($first) ? ($first['ID'] ?? $first['id'] ?? null) : (is_numeric($first) ? (int)$first : null);
        if (is_array($first) && !empty($first['post_title'])) return clean($first['post_title']);
        if ($id) {
            $a = pamm_get(PAMM_API . '/artist/' . (int)$id . '?lang=en');
            if (isset($a['title']['rendered'])) return clean($a['title']['rendered']);
        }
    }
    return null;
}

$upsertSel = $pdo->prepare('SELECT id, original_image_url, visual_description FROM viz_lab_subjects WHERE slug = ?');
$ins = $pdo->prepare(
    'INSERT INTO viz_lab_subjects
       (slug, group_key, title, lang, artist, museum, date_text, medium, original_image_url, visual_description, source_url, credit, sort_order, is_published)
     VALUES (:slug,:group_key,:title,:lang,:artist,:museum,:date_text,:medium,:img,:desc,:source,:credit,:sort,1)'
);
$upd = $pdo->prepare(
    'UPDATE viz_lab_subjects SET
       group_key=:group_key, title=:title, lang=:lang, artist=:artist, museum=:museum, date_text=:date_text, medium=:medium,
       original_image_url=:img, visual_description=:desc, source_url=:source, credit=:credit
     WHERE slug=:slug'
);
$clearGens = $pdo->prepare('DELETE FROM viz_lab_generations WHERE subject_id = ?');

echo "building PAMM artwork index ($lang)…\n";
$index = pamm_build_index($lang);
echo "  indexed " . count($index) . " artworks\n";

$ok = 0;
foreach ($args as $arg) {
    $objnum = preg_match('#/([0-9]{4}\.[0-9A-Za-z.\-]+)/?$#', $arg, $m) ? $m[1] : trim($arg);
    echo "→ $objnum\n";

    $id = $index[$objnum] ?? null;
    if (!$id) { fwrite(STDERR, "  ! object number not found in PAMM $lang index\n"); continue; }
    $o = pamm_get(PAMM_API . '/artwork/' . $id . '?lang=' . $lang . '&_embed=1');
    if (!$o) { fwrite(STDERR, "  ! API fetch failed for id $id\n"); continue; }

    $desc = clean($o['acf']['artwork_visual_description'] ?? '');
    if ($desc === '') { fwrite(STDERR, "  ! no visual description on this record — skipping\n"); continue; }

    $title    = clean($o['title']['rendered'] ?? $objnum);
    $groupKey = 'pamm-' . viz_slugify($objnum);
    $slug     = $groupKey . ($lang !== 'en' ? '-' . $lang : '');
    $imgRemote = $o['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '';
    if (!$imgRemote) { fwrite(STDERR, "  ! no featured image — skipping (lab needs an original to compare)\n"); continue; }

    $local = viz_mirror_original($slug, $imgRemote);
    if (!$local) { fwrite(STDERR, "  ! could not mirror image $imgRemote — skipping\n"); continue; }
    echo "  mirrored image → $local\n";

    $params = [
        ':slug'      => $slug,
        ':group_key' => $groupKey,
        ':title'     => $title,
        ':lang'      => $lang,
        ':artist'    => pamm_artist($o),
        ':museum'    => 'Pérez Art Museum Miami',
        ':date_text' => clean($o['acf']['artwork_date'] ?? '') ?: null,
        ':medium'    => clean($o['acf']['artwork_medium'] ?? '') ?: null,
        ':img'       => $local,
        ':desc'      => $desc,
        ':source'    => $o['link'] ?? null,
        ':credit'    => clean($o['acf']['artwork_credit_line'] ?? '') ?: null,
    ];

    $upsertSel->execute([$slug]);
    if ($existing = $upsertSel->fetch(PDO::FETCH_ASSOC)) {
        $upd->execute($params);
        if ($existing['visual_description'] !== $desc) { $clearGens->execute([(int)$existing['id']]); }
        echo "  updated: $title\n";
    } else {
        $ins->execute(array_merge($params, [':sort' => 0]));
        echo "  added: $title\n";
    }
    $ok++;
}

echo "done: $ok subject(s) imported\n";
