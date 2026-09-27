<?php
/**
 * Tiny .env loader. Reads KEY=VALUE pairs from the project-root .env file once
 * and caches them. Supports # comments, blank lines, and optional surrounding
 * single/double quotes. Real process environment variables (getenv) win over
 * the file, so deploys can still override.
 *
 *   env_get('OPENROUTER_KEY')            // string|null
 *   env_get('FOO', 'default')
 *
 * The .env lives in the web root but Apache denies all hidden paths (see the
 * vhost LocationMatch), and it is gitignored. Never echo its values.
 */

declare(strict_types=1);

const ENV_FILE = __DIR__ . '/../.env';

function env_all(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    if (is_readable(ENV_FILE)) {
        foreach (file(ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (!str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            if ($k !== '') $cache[$k] = $v;
        }
    }
    return $cache;
}

function env_get(string $key, ?string $default = null): ?string {
    $fromProc = getenv($key);
    if ($fromProc !== false && $fromProc !== '') return $fromProc;
    $all = env_all();
    $v = $all[$key] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}
