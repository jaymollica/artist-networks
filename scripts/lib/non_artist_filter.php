<?php
/**
 * Shared "not-an-artist" filter used by insight reports.
 *
 * Builds a temporary table `_non_artist_ulans` of ULANs that match patterns
 * indicating royals, popes, dukes, dauphins, institutions, manufactories,
 * etc. — entries Getty's vocabulary includes but that aren't actually working
 * artists for the purpose of our reports.
 *
 * Usage in a query:
 *   ... WHERE m.ulan NOT IN (SELECT ulan FROM _non_artist_ulans) ...
 *
 * Also returns the set as an int-keyed PHP array so callers can filter in
 * memory (used by graph-building code in insight_nepo.php).
 */

declare(strict_types=1);

function setup_non_artist_filter(PDO $pdo): array {
    $pdo->exec("CREATE TEMPORARY TABLE IF NOT EXISTS _non_artist_ulans (ulan INT PRIMARY KEY)");
    $pdo->exec("TRUNCATE TABLE _non_artist_ulans");

    // Pattern 1: titles/institutions embedded in the alias name
    $sql = "INSERT IGNORE INTO _non_artist_ulans (ulan)
            SELECT DISTINCT ulan FROM artist_aliases WHERE
                alias LIKE '%Emperor%' OR alias LIKE '%Empress%' OR
                alias LIKE '%King of %' OR alias LIKE '%King consort%' OR
                alias LIKE '%Queen of %' OR alias LIKE '%Queen consort%' OR
                alias LIKE '%Prince of %' OR alias LIKE '%Princess of %' OR
                alias LIKE '%Prince Royal%' OR alias LIKE '%Princess Royal%' OR
                alias LIKE '%Duke of %' OR alias LIKE '%Duchess of %' OR
                alias LIKE '%Duke consort%' OR alias LIKE '%Duchess consort%' OR
                alias LIKE '%Grand Duchess consort%' OR alias LIKE '%Grand Duke consort%' OR
                alias LIKE '%Grand-Duke of %' OR alias LIKE '%Grand Duke of %' OR
                alias LIKE '%Earl of %' OR alias LIKE '%Lord of %' OR
                alias LIKE '%Marquis of %' OR alias LIKE '%Marquise of %' OR
                alias LIKE '%Count of %' OR alias LIKE '%Countess of %' OR
                alias LIKE '%Baron of %' OR alias LIKE '%Baroness of %' OR
                alias LIKE '%Dauphin %' OR alias LIKE '%Dauphine %' OR
                alias LIKE '%Infante %' OR alias LIKE '%Infanta %' OR
                alias LIKE '%Stadholder%' OR alias LIKE '%Madame de %' OR
                alias LIKE '%Pope %' OR alias LIKE '%Saint %' OR
                alias LIKE '%Bishop %' OR alias LIKE '%Cardinal %' OR
                alias LIKE '%Tsar %' OR alias LIKE '%Czar %' OR
                alias LIKE '%Sultan %' OR alias LIKE '%Sheikh %' OR
                alias LIKE '%Holy Roman%' OR alias LIKE '%House of %' OR
                alias LIKE '%, Royal%' OR alias LIKE '%dynasty%' OR
                alias LIKE '%Bonaparte%' OR
                alias LIKE '%University%' OR alias LIKE '%College%' OR
                alias LIKE '%Company%' OR alias LIKE '%Corporation%' OR
                alias LIKE '%Foundation%' OR alias LIKE '%Institute%' OR
                alias LIKE '%Workshop%' OR alias LIKE '%Manufactory%' OR
                alias LIKE '%Society%' OR alias LIKE '%Museum%' OR
                alias LIKE '%Gallery%' OR alias LIKE '%Department%' OR
                alias LIKE '%Authority%'";
    $pdo->exec($sql);

    // Pattern 2: bio names a non-artist role AND no art-related role.
    // Bios are typically comma-delimited profession lists, e.g. "American
    // physician, architect, 1759-1828". If the only roles listed are
    // non-artistic (leader, politician, general, philosopher, etc.) we
    // exclude. If any art-keyword is present we keep — covers cases like
    // "physician, architect" where the person is a working architect.
    $nonArt = ['leader','chief','warrior','deity','politician','statesman','senator','governor',
               'monarch','sovereign','emperor','queen','king','prince','princess',
               'duke','duchess','noble','aristocrat','royal',
               'general','admiral','soldier','officer','warlord','tribal',
               'physician','surgeon','dentist','nurse',
               'philosopher','theologian','priest','preacher','monk','nun','abbot','prophet','saint',
               'mathematician','astronomer','chemist','biologist','physicist','geologist','botanist',
               'zoologist','naturalist','anthropologist','archaeologist','sociologist','economist',
               'historian','linguist','psychologist','psychoanalyst',
               'businessman','industrialist','merchant','banker','financier','entrepreneur',
               'explorer','missionary','colonist',
               'lawyer','judge','jurist','attorney','barrister',
               'diplomat','ambassador',
               'athlete','sportsman','footballer','boxer',
               'journalist','broadcaster','editor','publisher',
               'singer','musician','composer','conductor','pianist','violinist','guitarist',
               'novelist','poet','playwright','author','writer',
               'actor','actress','comedian',
               'inventor','engineer','scientist',
               'patron','collector','dealer','curator','critic'];
    // Art-related roles that, if present in the bio, keep the artist in.
    $art = ['painter','sculptor','photographer','designer','architect','illustrator',
            'engraver','etcher','draftsman','draughtsman','printmaker','lithographer',
            'cartographer','mapmaker','calligrapher','typographer',
            'ceramist','ceramicist','potter','weaver','jeweler','jeweller',
            'goldsmith','silversmith','metalsmith','blacksmith',
            'watercolorist','miniaturist','muralist','frescoist','mosaicist',
            'glassmaker','glassblower','glazier',
            'animator','filmmaker','cinematographer','cartoonist','comic',
            'woodcarver','woodcutter','sculptress','draftswoman','craftsman','craftswoman',
            'decorator','embroiderer','typesetter','book designer','art director',
            'art','arts'];

    $nonArtRe = '\\b(' . implode('|', $nonArt) . ')\\b';
    $artRe    = '\\b(' . implode('|', $art)    . ')\\b';
    $sql = "INSERT IGNORE INTO _non_artist_ulans (ulan)
            SELECT DISTINCT b.ulan FROM biographies b
            WHERE b.preferred = 1
              AND LOWER(b.biography) REGEXP :nonart
              AND LOWER(b.biography) NOT REGEXP :art";
    $st = $pdo->prepare($sql);
    $st->execute([':nonart' => $nonArtRe, ':art' => $artRe]);

    $set = [];
    foreach ($pdo->query("SELECT ulan FROM _non_artist_ulans")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        $set[(int)$u] = true;
    }
    return $set;
}
