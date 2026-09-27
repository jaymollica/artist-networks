<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=86400');

$ulan = $_GET['ulan'] ?? '';
if (!preg_match('/^\d{6,12}$/', $ulan)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid ulan']);
    exit;
}

$cacheDir = __DIR__ . '/.portrait-cache';
$cacheFile = $cacheDir . '/' . $ulan . '.json';
$ttl = 30 * 86400; // 30 days

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    readfile($cacheFile);
    exit;
}

$query = 'SELECT ?image WHERE { ?a wdt:P245 "' . $ulan . '" . ?a wdt:P18 ?image . } LIMIT 1';
$url   = 'https://query.wikidata.org/sparql?query=' . urlencode($query) . '&format=json';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => [
        'Accept: application/sparql-results+json',
        'User-Agent: artist-networks/1.0 (https://networks.vaguespac.es)',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 15,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$out = ['image' => null];
if ($body && $code >= 200 && $code < 300) {
    $j = json_decode($body, true);
    $b = $j['results']['bindings'][0] ?? null;
    if ($b && isset($b['image']['value'])) {
        $imgUrl = $b['image']['value'];
        if (strpos($imgUrl, 'http://') === 0) $imgUrl = 'https://' . substr($imgUrl, 7);
        $out['image'] = $imgUrl;
    }
}

$json = json_encode($out);
@file_put_contents($cacheFile, $json);
echo $json;
