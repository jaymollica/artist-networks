<?php
/**
 * Seed / upsert Visualization Lab subjects from data/viz_lab_subjects.json.
 * Curated by hand — edit the JSON, then run:
 *
 *   php scripts/viz_lab_seed.php
 *
 * Upserts on `slug`. Existing generations are left untouched unless the
 * original image URL or description changes, in which case stale generations
 * for that subject are cleared so they regenerate against the new input.
 */

declare(strict_types=1);

const DB_NAME      = 'artist_networks';
const DB_USER      = 'artist_networks';
const DB_PASS_FILE = '/etc/artist-networks/db_pass';

$pdo = new PDO(
    'mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    trim((string)file_get_contents(DB_PASS_FILE)),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

require_once __DIR__ . '/../lib/viz_lab.php';

$file = __DIR__ . '/../data/viz_lab_subjects.json';
$rows = json_decode((string)file_get_contents($file), true);
if (!is_array($rows)) { fwrite(STDERR, "cannot parse $file\n"); exit(1); }

$sel = $pdo->prepare('SELECT id, original_image_url, visual_description FROM viz_lab_subjects WHERE slug = ?');
$ins = $pdo->prepare(
    'INSERT INTO viz_lab_subjects
       (slug, title, artist, ulan, museum, date_text, medium, original_image_url, visual_description, source_url, credit, sort_order, is_published)
     VALUES (:slug,:title,:artist,:ulan,:museum,:date_text,:medium,:img,:desc,:source,:credit,:sort,:pub)'
);
$upd = $pdo->prepare(
    'UPDATE viz_lab_subjects SET
       title=:title, artist=:artist, ulan=:ulan, museum=:museum, date_text=:date_text, medium=:medium,
       original_image_url=:img, visual_description=:desc, source_url=:source, credit=:credit,
       sort_order=:sort, is_published=:pub
     WHERE slug=:slug'
);
$clearGens = $pdo->prepare('DELETE FROM viz_lab_generations WHERE subject_id = ?');

$added = $updated = $cleared = 0;
foreach ($rows as $r) {
    if (empty($r['title']) || empty($r['original_image_url']) || empty($r['visual_description'])) {
        fwrite(STDERR, "skipping incomplete row: " . ($r['slug'] ?? $r['title'] ?? '?') . "\n");
        continue;
    }
    $slug = !empty($r['slug']) ? viz_slugify($r['slug']) : viz_slugify($r['title']);
    $params = [
        ':slug'      => $slug,
        ':title'     => $r['title'],
        ':artist'    => $r['artist']    ?? null,
        ':ulan'      => isset($r['ulan']) && $r['ulan'] !== null ? (int)$r['ulan'] : null,
        ':museum'    => $r['museum']     ?? null,
        ':date_text' => $r['date_text']  ?? null,
        ':medium'    => $r['medium']     ?? null,
        ':img'       => $r['original_image_url'],
        ':desc'      => $r['visual_description'],
        ':source'    => $r['source_url'] ?? null,
        ':credit'    => $r['credit']     ?? null,
        ':sort'      => (int)($r['sort_order'] ?? 0),
        ':pub'       => array_key_exists('is_published', $r) ? (int)(bool)$r['is_published'] : 1,
    ];

    $sel->execute([$slug]);
    $existing = $sel->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $upd->execute($params);
        $updated++;
        if ($existing['original_image_url'] !== $r['original_image_url']
            || $existing['visual_description'] !== $r['visual_description']) {
            $clearGens->execute([(int)$existing['id']]);
            $cleared += $clearGens->rowCount();
        }
    } else {
        $ins->execute($params);
        $added++;
    }
    echo "  ok: $slug\n";
}

echo "done: $added added, $updated updated, $cleared stale generations cleared\n";
