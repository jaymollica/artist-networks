<?php
/**
 * Image-to-image semantic similarity for the Visualization Lab.
 *
 * Uses Jina's hosted CLIP (jina-clip-v2) to embed the original and generated
 * images, then returns cosine similarity in [0,1]. Kept behind one function so
 * the provider can be swapped (local CLIP, Replicate, etc.) without touching
 * callers. Returns null when no key is configured, so the lab degrades to
 * "side-by-side + judge only" rather than failing.
 *
 *   clip_similarity($origUrl, $genUrl) -> float|null
 */

declare(strict_types=1);

require_once __DIR__ . '/viz_lab.php';

function clip_similarity(string $originalUrl, string $generatedUrl): ?float {
    $key = viz_jina_key();
    if (!$key) return null;

    $payload = [
        'model' => 'jina-clip-v2',
        'input' => [
            ['image' => $originalUrl],
            ['image' => $generatedUrl],
        ],
    ];

    $ch = curl_init('https://api.jina.ai/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code >= 400) return null;
    $j = json_decode((string)$body, true);
    $a = $j['data'][0]['embedding'] ?? null;
    $b = $j['data'][1]['embedding'] ?? null;
    if (!is_array($a) || !is_array($b) || count($a) !== count($b)) return null;

    $dot = 0.0; $na = 0.0; $nb = 0.0;
    foreach ($a as $i => $va) {
        $vb = $b[$i];
        $dot += $va * $vb; $na += $va * $va; $nb += $vb * $vb;
    }
    if ($na <= 0 || $nb <= 0) return null;
    $cos = $dot / (sqrt($na) * sqrt($nb));
    return max(0.0, min(1.0, $cos));   // clamp to [0,1] for display
}
