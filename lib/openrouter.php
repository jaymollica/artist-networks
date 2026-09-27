<?php
/**
 * Minimal OpenRouter client for the Visualization Lab.
 *
 *   or_generate_image($key, $model, $prompt)  -> ['mime'=>, 'data'=>(binary)]   (throws on failure)
 *   or_judge($key, $model, $origUrl, $genUrl, $description) -> ['score'=>int, 'notes'=>string]
 *
 * Both go through POST /chat/completions. Image-output models return the picture
 * as a base64 data URL in choices[0].message.images[]; we decode it to binary.
 */

declare(strict_types=1);

require_once __DIR__ . '/viz_lab.php';

class OpenRouterError extends RuntimeException {}

function or_post(string $key, array $payload, int $timeout = 180): array {
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
            'HTTP-Referer: ' . VIZ_HTTP_REFERER,
            'X-Title: ' . VIZ_HTTP_TITLE,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new OpenRouterError("network error: $err");
    $json = json_decode((string)$body, true);
    if ($code >= 400 || !is_array($json)) {
        $msg = $json['error']['message'] ?? substr((string)$body, 0, 300);
        throw new OpenRouterError("HTTP $code: $msg");
    }
    if (isset($json['error'])) {
        throw new OpenRouterError($json['error']['message'] ?? 'unknown OpenRouter error');
    }
    return $json;
}

/** Generate an image from a text prompt. Returns ['mime'=>, 'data'=>binary]. */
function or_generate_image(string $key, string $model, string $prompt): array {
    // Explicit image-generation directive — without it, OpenAI image models on
    // OpenRouter treat a bare descriptive paragraph as text to edit and reply in
    // prose. The same wrapper is sent to every model, so comparisons stay fair;
    // the judge still receives the unwrapped description separately.
    $directive = "Create a single image based solely on the following description. "
               . "Aim to match the described composition, orientation, proportions, colors, and medium as closely as you can. "
               . "Output only the image: no text, captions, frames, or borders.\n\n"
               . "DESCRIPTION:\n" . $prompt;
    $resp = or_post($key, [
        'model'      => $model,
        'modalities' => ['image', 'text'],
        'messages'   => [
            ['role' => 'user', 'content' => $directive],
        ],
    ], 280);   // some image models (gpt-5.4-image-2) routinely run past the 180s default

    $images = $resp['choices'][0]['message']['images'] ?? [];
    if (!$images) {
        $txt = $resp['choices'][0]['message']['content'] ?? '';
        throw new OpenRouterError('model returned no image' . ($txt ? ' (' . substr((string)$txt, 0, 120) . ')' : ''));
    }
    $url = $images[0]['image_url']['url'] ?? '';
    if (!preg_match('#^data:([^;]+);base64,(.*)$#s', $url, $m)) {
        throw new OpenRouterError('unexpected image payload format');
    }
    $bin = base64_decode($m[2], true);
    if ($bin === false || $bin === '') throw new OpenRouterError('could not decode image');
    return ['mime' => $m[1], 'data' => $bin];
}

/**
 * Ask a vision model how well the generated image matches the original, given the
 * museum description. Returns ['score'=>0..100, 'notes'=>string].
 */
function or_judge(string $key, string $model, string $originalUrl, string $generatedUrl, string $description): array {
    $sys = 'You are an art-historical image evaluator. You will see an ORIGINAL artwork image, '
         . 'a museum VISUAL DESCRIPTION of it, and a GENERATED image produced by a text-to-image model '
         . 'from that description alone (it never saw the original). Judge how faithfully the generated '
         . 'image reconstructs the original artwork — composition, subject, palette, medium, mood. '
         . 'Reply with ONLY a JSON object: {"score": <integer 0-100>, "notes": "<1-2 sentence critique '
         . 'naming the most notable match and the most notable miss>"}. No markdown, no extra text.';

    $resp = or_post($key, [
        'model'    => $model,
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => "VISUAL DESCRIPTION:\n" . $description],
                ['type' => 'text', 'text' => 'ORIGINAL artwork:'],
                ['type' => 'image_url', 'image_url' => ['url' => $originalUrl]],
                ['type' => 'text', 'text' => 'GENERATED from the description:'],
                ['type' => 'image_url', 'image_url' => ['url' => $generatedUrl]],
            ]],
        ],
    ], 120);

    $content = $resp['choices'][0]['message']['content'] ?? '';
    if (is_array($content)) {
        // some models return content as an array of parts
        $content = implode(' ', array_map(fn($p) => is_array($p) ? ($p['text'] ?? '') : (string)$p, $content));
    }
    if (!preg_match('/\{.*\}/s', (string)$content, $m)) {
        return ['score' => null, 'notes' => 'Judge returned no parseable verdict.'];
    }
    $j = json_decode($m[0], true);
    $score = isset($j['score']) ? max(0, min(100, (int)$j['score'])) : null;
    $notes = isset($j['notes']) ? trim((string)$j['notes']) : '';
    return ['score' => $score, 'notes' => $notes];
}
