<?php
/**
 * Inject anchor IDs into <h2>/<h3> elements of a rendered HTML fragment and
 * build a Table of Contents that deep-links into each section.
 *
 *   [$bodyHtml, $tocHtml] = inject_toc($bodyHtml);
 *
 * Returns the body with IDs added, and a TOC fragment (empty string if there
 * aren't enough headings to bother).
 */

declare(strict_types=1);

function toc_slug(string $text, array &$used): string {
    $s = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s) ?? '';
    $s = trim($s, '-');
    if ($s === '') $s = 'section';
    $base = $s; $i = 2;
    while (isset($used[$s])) { $s = $base . '-' . $i++; }
    $used[$s] = true;
    return $s;
}

function inject_toc(string $html, int $minHeadings = 2): array {
    if (!preg_match_all('/<h([23])([^>]*)>(.+?)<\/h\1>/su', $html, $matches, PREG_OFFSET_CAPTURE)) {
        return [$html, ''];
    }
    if (count($matches[0]) < $minHeadings) return [$html, ''];

    $used = [];
    $entries = []; // [level, slug, text]

    // Walk from the end so offsets stay valid while we splice.
    $tags = [];
    foreach ($matches[0] as $i => $m) {
        $tags[] = [
            'offset' => $m[1],
            'len'    => strlen($m[0]),
            'level'  => (int)$matches[1][$i][0],
            'attrs'  => $matches[2][$i][0],
            'inner'  => $matches[3][$i][0],
        ];
    }

    // assign slugs in document order so duplicate-handling matches the TOC
    foreach ($tags as &$t) {
        $plain = trim(strip_tags($t['inner']));
        $t['slug'] = toc_slug($plain, $used);
        $t['text'] = $plain;
    }
    unset($t);

    // splice id="..." into each heading from the end
    foreach (array_reverse($tags) as $t) {
        $attrs = $t['attrs'];
        // remove any pre-existing id="..."
        $attrs = preg_replace('/\s+id="[^"]*"/i', '', $attrs);
        $newOpen = '<h' . $t['level'] . ' id="' . htmlspecialchars($t['slug'], ENT_QUOTES) . '"' . $attrs . '>';
        $newClose = '</h' . $t['level'] . '>';
        $html = substr_replace($html, $newOpen . $t['inner'] . $newClose, $t['offset'], $t['len']);
    }

    // build TOC (h2 = top-level item, h3 = nested)
    $toc = '<nav class="report-toc" aria-labelledby="report-toc-label">';
    $toc .= '<h2 id="report-toc-label" class="report-toc-label">Contents</h2>';
    $toc .= '<ol class="report-toc-list">';
    $h2Open  = false;
    $subOpen = false;
    foreach ($tags as $t) {
        $href = htmlspecialchars($t['slug'], ENT_QUOTES);
        $text = htmlspecialchars($t['text']);
        if ($t['level'] === 2) {
            if ($subOpen) { $toc .= '</ol>'; $subOpen = false; }
            if ($h2Open)  { $toc .= '</li>'; }
            $toc .= '<li><a href="#' . $href . '">' . $text . '</a>';
            $h2Open = true;
        } else { // h3
            if (!$h2Open) {
                // h3 without a preceding h2 — give it an outer li
                $toc .= '<li>';
                $h2Open = true;
            }
            if (!$subOpen) {
                $toc .= '<ol class="report-toc-sub">';
                $subOpen = true;
            }
            $toc .= '<li><a href="#' . $href . '">' . $text . '</a></li>';
        }
    }
    if ($subOpen) $toc .= '</ol>';
    if ($h2Open)  $toc .= '</li>';
    $toc .= '</ol></nav>';

    return [$html, $toc];
}
