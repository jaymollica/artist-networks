<?php
/**
 * Visualization Lab — shared config + helpers.
 *
 * The lab takes a museum-written *visual description* of an artwork, feeds it to
 * a sample of text-to-image models (via OpenRouter), and shows the generated
 * images next to the original so you can see how faithfully each model — and the
 * description itself — reconstructs the work. Each render is scored two ways:
 * CLIP image-similarity (objective) and a vision-LLM judge (qualitative).
 *
 * Generation is live/on-demand but every result is cached in viz_lab_generations
 * keyed by (subject, model): the first visitor pays the API call, everyone after
 * sees the cached render. Per-IP + global rate limits cap runaway cost.
 */

declare(strict_types=1);

require_once __DIR__ . '/env.php';

const VIZ_SECRETS_DIR = '/etc/artist-networks';
const VIZ_CACHE_DIR   = __DIR__ . '/../viz-cache';        // generated images (gitignored)
const VIZ_CACHE_URL   = '/viz-cache';                     // public path to the same

/** Image-generation models we sample, in display order. Edit freely.
 *  OpenRouter only routes image *output* from Google and OpenAI, so the spread
 *  is across their tiers. (Qwen/FLUX/Stability/etc. would need a second
 *  provider such as Replicate or fal.ai.) */
const VIZ_MODELS = [
    'google/gemini-3-pro-image'     => 'Gemini 3 Pro Image',
    'google/gemini-3.1-flash-image' => 'Gemini 3.1 Flash Image',
    'google/gemini-2.5-flash-image' => 'Gemini 2.5 Flash Image',
    'openai/gpt-5.4-image-2'        => 'GPT-5.4 Image 2',
    'openai/gpt-5-image'            => 'GPT-5 Image',
    'openai/gpt-5-image-mini'       => 'GPT-5 Image Mini',
];

/** Vision model that critiques each render against the original. */
const VIZ_JUDGE_MODEL = 'anthropic/claude-sonnet-4.6';

/** Rate limits (real API calls only; cached hits are free and uncounted). */
const VIZ_RATE_PER_IP_HOUR  = 20;     // per visitor per rolling hour
const VIZ_RATE_GLOBAL_DAY   = 400;    // whole site per rolling 24h (cost backstop)

const VIZ_HTTP_REFERER = 'https://networks.vaguespac.es';
const VIZ_HTTP_TITLE   = 'Artist Networks — Visualization Lab';

/** Read a secret file from the secrets dir; null if missing/empty. */
function viz_secret(string $name): ?string {
    $p = VIZ_SECRETS_DIR . '/' . $name;
    if (!is_readable($p)) return null;
    $v = trim((string)file_get_contents($p));
    return $v === '' ? null : $v;
}

// Prefer .env (project-root, gitignored, Apache-denied); fall back to the
// legacy /etc/artist-networks/ secret files so nothing breaks mid-migration.
function viz_openrouter_key(): ?string { return env_get('OPENROUTER_KEY') ?? viz_secret('openrouter_key'); }
function viz_jina_key(): ?string       { return env_get('JINA_KEY')       ?? viz_secret('jina_key'); }

function viz_slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string)$s, '-') ?: 'item';
}

/**
 * PROJECT RULE: never send the artist's name or the artwork's title to an image
 * model. This strips them from the description used for generation + judging so
 * the model works from the visual content alone (and doesn't refuse to "copy" a
 * named work). The displayed museum description is left untouched.
 *
 * - The title is removed only as a whole phrase (so descriptive title words like
 *   "white" / "flower" that recur in the prose survive).
 * - The artist name is removed as a phrase and token-by-token, tolerating a
 *   1-character misspelling (PAMM's Alba text says "Herrara") and possessives.
 */
function viz_strip_identity(string $desc, ?string $title, ?string $artist): string {
    $out = $desc;

    // Title may be "English Title (Título en Español)"; strip the whole thing and
    // each part. Use letter/number lookarounds (not \b) so titles that start or
    // end in punctuation — e.g. a trailing ")" — still match.
    $titleVariants = [];
    $t = trim((string)$title);
    if ($t !== '') {
        $titleVariants[] = $t;
        if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/u', $t, $mm)) {
            $titleVariants[] = trim($mm[1]);
            $titleVariants[] = trim($mm[2]);
        }
    }
    $titleVariants = array_filter(array_unique($titleVariants), fn($v) => mb_strlen($v) >= 3);
    usort($titleVariants, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    foreach ($titleVariants as $tv) {
        $out = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($tv, '/') . "(?:[’']s)?(?![\p{L}\p{N}])/iu", '', $out);
    }

    $a = trim((string)$artist);
    if ($a !== '') {
        $out = preg_replace('/\b' . preg_quote($a, '/') . "(?:’s|'s)?\b/iu", '', $out);
        $stop = ['van', 'von', 'der', 'den', 'del', 'della', 'de', 'la', 'le', 'di', 'da', 'dos', 'the', 'and'];
        $tokens = [];
        foreach (preg_split('/\s+/u', mb_strtolower($a)) as $w) {
            $w = preg_replace('/[^\p{L}]/u', '', $w);
            if (mb_strlen($w) >= 4 && !in_array($w, $stop, true)) $tokens[] = $w;
        }
        if ($tokens) {
            $parts = preg_split('/(\s+)/u', $out, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($parts as $i => $tok) {
                if ($tok === '' || ctype_space($tok)) continue;
                $norm = mb_strtolower(preg_replace('/[^\p{L}]/u', '', $tok));
                if ($norm === '') continue;
                foreach ($tokens as $f) {
                    if ($norm === $f
                        || (mb_strlen($f) >= 5 && preg_match('/^[a-z]+$/', $f . $norm) && levenshtein($norm, $f) <= 1)) {
                        $parts[$i] = '';
                        break;
                    }
                }
            }
            $out = implode('', $parts);
        }
    }

    // Tidy artifacts left by removals.
    $out = preg_replace('/\bby\s*,/iu', '', $out);        // "by Carmen Herrera," -> ","
    $out = preg_replace('/\s+([,.;:])/u', '$1', $out);    // space before punctuation
    $out = preg_replace('/(,\s*){2,}/u', ', ', $out);     // doubled commas
    $out = preg_replace('/,\s*([.;:])/u', '$1', $out);    // ", ." -> "."
    $out = preg_replace('/\(\s*[,;]?\s*\)/u', '', $out);  // empty parens
    $out = preg_replace('/\s{2,}/u', ' ', $out);
    $out = preg_replace('/^[\s,;:.]+/u', '', $out);       // leading orphan punctuation
    return trim($out);
}

/** Best-effort client IP, honoring a single trusted proxy hop if present. */
function viz_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr($ip, 0, 45);
}

/**
 * Returns [allowed(bool), reason(string)]. Counts only successfully-created
 * generation rows (status done|error) — cache hits never reach here.
 */
function viz_rate_check(PDO $pdo, string $ip): array {
    // Server-local calls (admin backfills, cron pre-warming) are never limited.
    // Public requests carry their real peer IP, which can't be spoofed to loopback.
    if ($ip === '127.0.0.1' || $ip === '::1') return [true, ''];
    $perIp = (int)$pdo->query(
        "SELECT COUNT(*) FROM viz_lab_generations
         WHERE requester_ip = " . $pdo->quote($ip) . "
           AND created_at > (NOW() - INTERVAL 1 HOUR)"
    )->fetchColumn();
    if ($perIp >= VIZ_RATE_PER_IP_HOUR) {
        return [false, 'Hourly generation limit reached for your connection. Try again later.'];
    }
    $global = (int)$pdo->query(
        "SELECT COUNT(*) FROM viz_lab_generations
         WHERE created_at > (NOW() - INTERVAL 1 DAY)"
    )->fetchColumn();
    if ($global >= VIZ_RATE_GLOBAL_DAY) {
        return [false, 'The lab has hit its daily generation budget. Check back tomorrow.'];
    }
    return [true, ''];
}

function viz_cache_ready(): bool {
    if (!is_dir(VIZ_CACHE_DIR)) @mkdir(VIZ_CACHE_DIR, 0775, true);
    return is_dir(VIZ_CACHE_DIR) && is_writable(VIZ_CACHE_DIR);
}

const VIZ_ORIG_DIR = VIZ_CACHE_DIR . '/orig';   // locally-mirrored original artwork images
const VIZ_ORIG_URL = VIZ_CACHE_URL . '/orig';

/** Make a stored image reference absolute so off-site fetchers (CLIP, judge) can load it. */
function viz_abs_url(string $u): string {
    return preg_match('#^https?://#i', $u) ? $u : VIZ_HTTP_REFERER . '/' . ltrim($u, '/');
}

/**
 * Fetch image bytes, returning [data, mime] or null. Tries the URL directly,
 * then falls back to the weserv.nl image proxy for hosts that block datacenter
 * IPs (e.g. Cloudflare JS challenges on AIC / PAMM asset URLs).
 */
function viz_fetch_image_bytes(string $url): ?array {
    $ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    $tries = [$url, 'https://images.weserv.nl/?url=' . rawurlencode(preg_replace('#^https?://#i', '', $url))];
    foreach ($tries as $try) {
        $ch = curl_init($try);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_REFERER        => $url,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = explode(';', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0];
        curl_close($ch);
        if ($body !== false && $code < 400 && str_starts_with($mime, 'image/') && strlen($body) > 1024) {
            return [$body, $mime];
        }
    }
    return null;
}

/**
 * Download a remote original into viz-cache/orig/{slug}.ext and return its local
 * relative URL (e.g. /viz-cache/orig/foo.jpg), or null if it couldn't be fetched.
 */
function viz_mirror_original(string $slug, string $remoteUrl): ?string {
    if (!is_dir(VIZ_ORIG_DIR)) @mkdir(VIZ_ORIG_DIR, 0775, true);
    if (!is_dir(VIZ_ORIG_DIR) || !is_writable(VIZ_ORIG_DIR)) return null;
    $got = viz_fetch_image_bytes($remoteUrl);
    if ($got === null) return null;
    [$data, $mime] = $got;
    $ext = match ($mime) {
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'jpg',
    };
    $fname = $slug . '.' . $ext;
    if (file_put_contents(VIZ_ORIG_DIR . '/' . $fname, $data) === false) return null;
    return VIZ_ORIG_URL . '/' . $fname;
}
