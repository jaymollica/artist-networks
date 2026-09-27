<?php
/**
 * Shared catalog of insight reports — used by reports.php (web listing) and
 * scripts/build_digest.php (monthly email digest assembler). When a new
 * report is added, register it here.
 */

// Cadence values: 'weekly' (wiki-driven, real weekly churn), 'monthly' (Met
// catalog refreshes 1st of month), 'annual' (IRS 990 filings, one per museum
// per year), 'stable' (Getty graph — refreshed weekly but rarely moves at the
// top). Used by build_digest.php to frame each section honestly.
//
// 'hidden' => true keeps a report out of the reports.php listing and the
// email digest. It is still generated and still reachable by direct URL.

return [
    'moneyball' => [
        'slug'  => 'moneyball',
        'title' => 'Moneyball — underexhibited works',
        'blurb' => 'Met Museum works worth a second look that aren\'t currently on view.',
        'prefix'=> 'insight-moneyball-',
        'cadence' => 'monthly',
    ],
    'triangulation' => [
        'slug'  => 'triangulation',
        'title' => 'Triangulation candidates',
        'blurb' => 'Pairs of artists who share many mutual connections but have no direct Getty edge. Strong candidates for relationships Getty may have missed.',
        'prefix'=> 'insight-triangulation-',
        'cadence' => 'stable',
    ],
    'chains' => [
        'slug'  => 'chains',
        'title' => 'Longest mentorship chains',
        'blurb' => 'Longest unbroken teacher → student successions in the Getty graph. Some chains span 500+ years.',
        'prefix'=> 'insight-chains-',
        'cadence' => 'stable',
    ],
    'hubs' => [
        'slug'  => 'hubs',
        'title' => 'Hub artists per era',
        'blurb' => 'Most-connected artists born in each decade. Surfaces who was central to their generation\'s social network.',
        'prefix'=> 'insight-hubs-',
        'cadence' => 'stable',
    ],
    'gaps' => [
        'slug'  => 'gaps',
        'title' => 'Gaps & anomalies',
        'blurb' => 'Artists famous on Wikipedia but sparsely connected in Getty; asymmetric mentorship pairs; Met-collected artists missing from Getty\'s graph.',
        'prefix'=> 'insight-gaps-',
        'cadence' => 'monthly',
    ],
    'sleeper' => [
        'slug'  => 'sleeper',
        'title' => 'Sleeper signals',
        'blurb' => 'Wikipedia *edit* activity tracked over 24 months. Rising stars (accelerating edits), cross-cultural reach (interwiki languages), and the decelerating canon.',
        'prefix'=> 'insight-sleeper-',
        'cadence' => 'weekly',
    ],
    'nepo' => [
        'slug'  => 'nepo',
        'title' => 'Nepo babies',
        'blurb' => 'Artists whose Getty position is shaped by family: children of famous artist-parents, multi-generational dynasties (Brueghel, Peale, Nampeyo), and spouses who married into prominence.',
        'prefix'=> 'insight-nepo-',
        'cadence' => 'stable',
    ],
    'concentration' => [
        'slug'  => 'concentration',
        'title' => 'Concentration index',
        'blurb' => 'How concentrated is cultural attention, and is it getting worse? Tracks Wikipedia pageview Gini month-by-month, within-cohort polarization by birth decade, and Met on-view share.',
        'prefix'=> 'insight-concentration-',
        'cadence' => 'monthly',
    ],
    'donor-capture' => [
        'slug'  => 'donor-capture',
        'title' => 'Donor-capture index',
        'blurb' => 'Which major US museums are most dependent on donors vs. audience-driven program revenue, and which are most "building-monetized" via rentals, retail, and events. From IRS 990 filings.',
        'prefix'=> 'insight-donor-capture-',
        'cadence' => 'annual',
        'hidden' => true,
    ],
    'trustees' => [
        'slug'  => 'trustees',
        'title' => 'Trustee networks',
        'blurb' => 'The social graph of museum boards: who serves on multiple boards, which museums share trustees, and which trustees bridge otherwise-disconnected institutions.',
        'prefix'=> 'insight-trustees-',
        'interactive_url' => '/trustees.php',
        'cadence' => 'annual',
        'hidden' => true,
    ],
];
