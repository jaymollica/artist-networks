<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=43200');

$ulan = $_GET['ulan'] ?? '';
$limit = isset($_GET['limit']) ? max(1, min(12, (int)$_GET['limit'])) : 6;

if (!preg_match('/^\d{6,12}$/', $ulan)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid ulan']);
    exit;
}

require_once __DIR__ . '/settings.php';

// fetch ranked candidate works for this artist
$st = $pdo->prepare(
    'SELECT id, object_id, title, object_date, object_name, link, primary_image_small, image_fetched_at
     FROM met_works WHERE ulan = ? ORDER BY rank_score DESC, id ASC LIMIT ?'
);
$st->bindValue(1, (int)$ulan, PDO::PARAM_INT);
// pull twice the cap so we can skip image-less works
$st->bindValue(2, $limit * 3, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$out = [];
$updateImg = $pdo->prepare('UPDATE met_works SET primary_image_small = ?, image_fetched_at = NOW() WHERE id = ?');

foreach ($rows as $r) {
    if (count($out) >= $limit) break;

    $img = $r['primary_image_small'];
    $fetched = $r['image_fetched_at'];

    // If we've never tried, hit the Met API once to populate; cache the answer
    // (even an empty string) so we don't retry forever.
    if ($fetched === null) {
        $img = met_image_for($r['object_id']);
        $updateImg->execute([$img ?? '', $r['id']]);
    }

    if (!$img) continue;
    $out[] = [
        'object_id' => (int)$r['object_id'],
        'title'     => $r['title'],
        'date'      => $r['object_date'],
        'kind'      => $r['object_name'],
        'image'     => $img,
        'link'      => $r['link'],
    ];
}

echo json_encode(['ulan' => (int)$ulan, 'works' => $out]);

function met_image_for(int $objectId): ?string {
    $ch = curl_init('https://collectionapi.metmuseum.org/public/collection/v1/objects/' . $objectId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['User-Agent: artist-networks/1.0 (https://networks.vaguespac.es)'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$body || $code < 200 || $code >= 300) return null;
    $j = json_decode($body, true);
    return $j['primaryImageSmall'] ?: null;
}
