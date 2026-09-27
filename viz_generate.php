<?php
/**
 * Visualization Lab — live generation endpoint (AJAX, JSON).
 *
 * POST subject=<id|slug>&model=<openrouter id>[&force=1]
 *
 * Returns the (cached or freshly generated) render for that subject+model, with
 * CLIP similarity and the vision-LLM judge verdict. Cache hits are free; real
 * generations are rate-limited per IP and globally.
 */

declare(strict_types=1);

require __DIR__ . '/settings.php';                 // $pdo (web credentials)
require_once __DIR__ . '/lib/viz_lab.php';
require_once __DIR__ . '/lib/openrouter.php';
require_once __DIR__ . '/lib/image_similarity.php';

header('Content-Type: application/json');

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') fail('POST required', 405);

$model = (string)($_POST['model'] ?? '');
if (!isset(VIZ_MODELS[$model])) fail('Unknown model.');

$subjectKey = trim((string)($_POST['subject'] ?? ''));
if ($subjectKey === '') fail('Missing subject.');

$stmt = ctype_digit($subjectKey)
    ? $pdo->prepare('SELECT * FROM viz_lab_subjects WHERE id = ? AND is_published = 1')
    : $pdo->prepare('SELECT * FROM viz_lab_subjects WHERE slug = ? AND is_published = 1');
$stmt->execute([$subjectKey]);
$subject = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$subject) fail('Subject not found.', 404);

$force = !empty($_POST['force']);

/** Shape a generation row for the client. */
function viz_present(array $g, string $label): array {
    return [
        'model'           => $g['model'],
        'label'           => $label,
        'status'          => $g['status'],
        'image_url'       => $g['image_path'] ? VIZ_CACHE_URL . '/' . $g['image_path'] : null,
        'clip_similarity' => $g['clip_similarity'] !== null ? round((float)$g['clip_similarity'], 4) : null,
        'judge_score'     => $g['judge_score'] !== null ? (int)$g['judge_score'] : null,
        'judge_notes'     => $g['judge_notes'],
        'latency_ms'      => $g['latency_ms'] !== null ? (int)$g['latency_ms'] : null,
        'error'           => $g['error'],
    ];
}

// Serve cache unless forced.
if (!$force) {
    $c = $pdo->prepare("SELECT * FROM viz_lab_generations
                        WHERE subject_id = ? AND model = ? AND status = 'done'
                        ORDER BY id DESC LIMIT 1");
    $c->execute([(int)$subject['id'], $model]);
    if ($cached = $c->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode(['ok' => true, 'cached' => true, 'generation' => viz_present($cached, VIZ_MODELS[$model])]);
        exit;
    }
}

// Real generation past this point — guard cost.
$key = viz_openrouter_key();
if (!$key) fail('Image generation is not configured yet (no OpenRouter key).', 503);
if (!viz_cache_ready()) fail('Image cache directory is not writable.', 500);

$ip = viz_client_ip();
[$allowed, $why] = viz_rate_check($pdo, $ip);
if (!$allowed) fail($why, 429);

@set_time_limit(360);

// PROJECT RULE: strip artist name + artwork title before the model sees the text.
$prompt = viz_strip_identity($subject['visual_description'], $subject['title'], $subject['artist']);
$t0 = microtime(true);
try {
    $img = or_generate_image($key, $model, $prompt);
} catch (Throwable $e) {
    $err = substr($e->getMessage(), 0, 1000);
    $pdo->prepare("INSERT INTO viz_lab_generations (subject_id, model, status, error, requester_ip)
                   VALUES (?, ?, 'error', ?, ?)")
        ->execute([(int)$subject['id'], $model, $err, $ip]);
    fail('Generation failed: ' . $err, 502);
}
$latency = (int)round((microtime(true) - $t0) * 1000);

// Persist the image.
$ext = match ($img['mime']) {
    'image/png'  => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    default      => 'png',
};
$fname = $subject['slug'] . '__' . preg_replace('/[^a-z0-9]+/i', '-', $model) . '-' . substr(md5($prompt . $latency . $ip), 0, 6) . '.' . $ext;
file_put_contents(VIZ_CACHE_DIR . '/' . $fname, $img['data']);
$genUrl = VIZ_HTTP_REFERER . VIZ_CACHE_URL . '/' . $fname;

// Score it (best-effort; failures leave nulls, never break the render).
$origUrl = viz_abs_url($subject['original_image_url']);
$clip = null;
try { $clip = clip_similarity($origUrl, $genUrl); } catch (Throwable $e) {}

$judgeScore = null; $judgeNotes = null;
try {
    $verdict = or_judge($key, VIZ_JUDGE_MODEL, $origUrl, $genUrl, $prompt);
    $judgeScore = $verdict['score'];
    $judgeNotes = $verdict['notes'];
} catch (Throwable $e) {
    $judgeNotes = 'Judge unavailable: ' . substr($e->getMessage(), 0, 200);
}

$ins = $pdo->prepare(
    "INSERT INTO viz_lab_generations
       (subject_id, model, status, image_path, image_mime, latency_ms, clip_similarity, judge_score, judge_notes, requester_ip)
     VALUES (?, ?, 'done', ?, ?, ?, ?, ?, ?, ?)"
);
$ins->execute([
    (int)$subject['id'], $model, $fname, $img['mime'], $latency, $clip, $judgeScore, $judgeNotes, $ip,
]);

$row = $pdo->query('SELECT * FROM viz_lab_generations WHERE id = ' . (int)$pdo->lastInsertId())->fetch(PDO::FETCH_ASSOC);
echo json_encode(['ok' => true, 'cached' => false, 'generation' => viz_present($row, VIZ_MODELS[$model])]);
