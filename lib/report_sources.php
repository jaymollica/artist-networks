<?php
/**
 * Shared citation helpers for the insight reports. Each report calls
 *
 *   fwrite($fh, build_sources_footer(['getty', 'wikipedia_pv', ...]));
 *
 * to emit a uniform "Sources" footer with links to the upstream data
 * providers, and uses the URL helpers (propublica_url, met_object_url,
 * getty_ulan_url, wikipedia_article_url) to inline-link rows where useful.
 */

declare(strict_types=1);

const REPORT_SOURCES = [
    'getty' => [
        'name' => 'Getty Vocabularies (ULAN)',
        'url'  => 'https://www.getty.edu/research/tools/vocabularies/ulan/',
        'note' => 'Union List of Artist Names — biographical and relational data for artists, architects, designers, and other cultural producers.',
    ],
    'wikipedia_pv' => [
        'name' => 'Wikimedia Pageviews API',
        'url'  => 'https://wikitech.wikimedia.org/wiki/Analytics/AQS/Pageviews',
        'note' => 'Monthly pageviews per Wikipedia article, last 24 months.',
    ],
    'wikipedia_edits' => [
        'name' => 'MediaWiki Revisions API',
        'url'  => 'https://www.mediawiki.org/wiki/API:Revisions',
        'note' => 'Wikipedia article edit history (timestamps) and interwiki language link counts.',
    ],
    'wikidata' => [
        'name' => 'Wikidata',
        'url'  => 'https://www.wikidata.org/',
        'note' => 'ULAN → Wikipedia article mapping via the P245 (ULAN ID) property.',
    ],
    'met_oa' => [
        'name' => 'Met Open Access',
        'url'  => 'https://github.com/metmuseum/openaccess',
        'note' => 'The Metropolitan Museum of Art\'s public-domain collection metadata.',
    ],
    'met_api' => [
        'name' => 'Met Collection API',
        'url'  => 'https://metmuseum.github.io/',
        'note' => 'Per-object metadata including current gallery / on-view status.',
    ],
    'propublica' => [
        'name' => 'ProPublica Nonprofit Explorer',
        'url'  => 'https://projects.propublica.org/nonprofits/',
        'note' => 'IRS Form 990 financial line items + officer rosters for U.S. tax-exempt organizations.',
    ],
    'irs_990' => [
        'name' => 'IRS Form 990 filings',
        'url'  => 'https://www.irs.gov/charities-non-profits/form-990-series-downloads',
        'note' => 'Primary source for U.S. nonprofit financial disclosures and trustee/officer rosters (Part VII).',
    ],
    'art_newspaper' => [
        'name' => 'The Art Newspaper — annual visitor survey',
        'url'  => 'https://www.theartnewspaper.com/visitor-figures',
        'note' => 'Annual museum-attendance figures published each spring, covering the top ~100 most-visited art museums worldwide.',
    ],
];

function build_sources_footer(array $keys): string {
    $out  = "\n---\n\n## Sources\n\n";
    foreach ($keys as $k) {
        if (!isset(REPORT_SOURCES[$k])) continue;
        $s = REPORT_SOURCES[$k];
        $out .= "- [{$s['name']}]({$s['url']}) — {$s['note']}\n";
    }
    return $out;
}

function propublica_url($ein): string {
    // PHP numeric-string array keys become ints in foreach, so accept either.
    // EINs that begin with 0 (e.g. "042103607") need padding back to 9 chars
    // before building the URL, since ProPublica's path lookup is exact.
    return 'https://projects.propublica.org/nonprofits/organizations/'
         . str_pad((string)$ein, 9, '0', STR_PAD_LEFT);
}

function getty_ulan_url(int $ulan): string {
    return 'https://vocab.getty.edu/page/ulan/' . $ulan;
}

function wikipedia_article_url(string $enTitle): string {
    return 'https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $enTitle));
}
