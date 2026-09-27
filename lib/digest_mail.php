<?php
/**
 * Helpers for the digest email pipeline. Used by subscribe.php, confirm.php,
 * unsubscribe.php and scripts/send_digest_email.php.
 */

declare(strict_types=1);

const DM_DB_NAME           = 'artist_networks';
const DM_DB_USER           = 'artist_networks';
const DM_DB_PASS_FILE      = '/etc/artist-networks/db_pass';
const DM_RESEND_KEY        = '/etc/artist-networks/resend_api_key';
const DM_RESEND_AUDIENCE   = '/etc/artist-networks/resend_audience_id'; // legacy single-audience fallback
const DM_AUDIENCE_DIR      = '/etc/artist-networks/audiences';
const DM_FROM              = 'Artist Networks <digest@vaguespac.es>';
const DM_REPLY_TO          = 'jaymollica@gmail.com';
const DM_SITE_URL          = 'https://networks.vaguespac.es';

function dm_pdo(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(
        'mysql:host=localhost;dbname=' . DM_DB_NAME . ';charset=utf8mb4',
        DM_DB_USER,
        trim((string)file_get_contents(DM_DB_PASS_FILE)),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}

function dm_token(): string {
    return bin2hex(random_bytes(24));
}

function dm_valid_email(string $e): bool {
    $e = trim($e);
    return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false && strlen($e) <= 255;
}

/**
 * Send a single email through Resend's transactional API.
 * Returns [bool $ok, int $httpCode, string $body|error].
 */
function dm_resend_send(array $to, string $subject, string $html, ?string $listUnsubUrl = null): array {
    if (!is_readable(DM_RESEND_KEY)) {
        return [false, 0, 'Resend API key file missing at ' . DM_RESEND_KEY];
    }
    $key = trim((string)file_get_contents(DM_RESEND_KEY));
    if ($key === '') return [false, 0, 'Resend API key file is empty'];

    $payload = [
        'from'      => DM_FROM,
        'to'        => $to,
        'reply_to'  => DM_REPLY_TO,
        'subject'   => $subject,
        'html'      => $html,
    ];
    if ($listUnsubUrl) {
        // RFC 8058 one-click unsubscribe
        $payload['headers'] = [
            'List-Unsubscribe'      => '<' . $listUnsubUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === '' && $err) return [false, $code, $err];
    return [$code >= 200 && $code < 300, $code, $body];
}

function dm_confirm_url(string $token): string  { return DM_SITE_URL . '/confirm.php?t=' . urlencode($token); }
function dm_unsub_url(string $token): string    { return DM_SITE_URL . '/unsubscribe.php?t=' . urlencode($token); }

/**
 * Resend API helper. Returns [bool $ok, int $httpCode, string $body|err].
 * Methods supported: GET, POST, DELETE. Body should be array (JSON-encoded) or null.
 */
function dm_resend_request(string $method, string $path, ?array $body = null): array {
    if (!is_readable(DM_RESEND_KEY)) return [false, 0, 'Resend API key file missing'];
    $key = trim((string)file_get_contents(DM_RESEND_KEY));
    if ($key === '') return [false, 0, 'Resend API key file is empty'];
    $ch = curl_init('https://api.resend.com' . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);
    $resp = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === '' && $err) return [false, $code, $err];
    return [$code >= 200 && $code < 300, $code, $resp];
}

/**
 * Resolve a `source` (e.g. "artist-networks") to its Resend audience id.
 * Looks at /etc/artist-networks/audiences/{source}, then falls back to the
 * legacy single-audience file. Returns null if no audience is configured.
 */
function dm_audience_id_for_source(?string $source = null): ?string {
    if ($source !== null && preg_match('/^[a-z0-9-]{1,64}$/', $source)) {
        $path = DM_AUDIENCE_DIR . '/' . $source;
        if (is_readable($path)) {
            $id = trim((string)file_get_contents($path));
            if ($id !== '') return $id;
        }
    }
    if (is_readable(DM_RESEND_AUDIENCE)) {
        $id = trim((string)file_get_contents(DM_RESEND_AUDIENCE));
        if ($id !== '') return $id;
    }
    return null;
}

/**
 * Add a confirmed subscriber to the Resend audience matching their source.
 * Best-effort — failures are logged but don't block the local subscription.
 */
function dm_audience_add(string $email, ?string $source = null): array {
    $aid = dm_audience_id_for_source($source);
    if (!$aid) return [false, 0, 'no audience id configured for source ' . ($source ?? 'default')];
    return dm_resend_request('POST', "/audiences/$aid/contacts", [
        'email'        => $email,
        'unsubscribed' => false,
    ]);
}

/**
 * Remove a subscriber from the Resend audience by email.
 */
function dm_audience_remove(string $email, ?string $source = null): array {
    $aid = dm_audience_id_for_source($source);
    if (!$aid) return [false, 0, 'no audience id configured for source ' . ($source ?? 'default')];
    return dm_resend_request('DELETE', "/audiences/$aid/contacts/" . rawurlencode($email));
}
