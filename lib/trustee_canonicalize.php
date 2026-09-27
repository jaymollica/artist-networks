<?php
/**
 * Trustee name canonicalization, shared by the insight report and the
 * live trustee-network page.
 *
 * Strategy: lowercase, strip honorifics (Mr/Mrs/Dr/Esq/PhD/MD/etc.), drop
 * middle names/initials, keep generational suffixes (Jr/Sr/II/III/IV).
 * Composite key = "firstname|generation|lastname".
 *
 * Aggressive enough to collapse "John H Mcfadden Esq" ≡ "John Mcfadden",
 * conservative enough to keep "John Smith Jr" ≠ "John Smith III".
 */

declare(strict_types=1);

function trustee_canonical(string $raw): array {
    $orig = $raw;
    $s = strtolower(trim($raw));
    $s = preg_replace('/^(mr|mrs|ms|mister|miss|dr|sir|lord|lady|hon|prof|professor|rev|reverend|the\s+rev|the\s+hon)\.?\s+/i', '', $s);
    $s = preg_replace('/\s+(esq|esquire|phd|ph\.d|md|m\.d|jd|j\.d|cpa|c\.p\.a|dds|mba|mfa|cfa|cfp)\.?$/i', '', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    $parts = $s ? explode(' ', trim($s)) : [];
    if (!$parts) return ['key' => $orig, 'display' => $raw];

    $suffix = '';
    if (count($parts) >= 2 && preg_match('/^(jr|sr|ii|iii|iv|v|vi)\.?$/', end($parts))) {
        $suffix = strtolower(rtrim(array_pop($parts), '.'));
    }
    if (count($parts) < 2) return ['key' => trim($s) . '||', 'display' => $raw];
    $first = $parts[0];
    $last  = rtrim(end($parts), ',');
    return ['key' => "$first|$suffix|$last", 'display' => $raw];
}
