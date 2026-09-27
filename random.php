<?php
/**
 * Pick a random well-connected artist and redirect to their network view.
 * "Well-connected" = ≥3 first-degree relationships, so the rendered graph
 * isn't an empty dot. The artist must also have a display-name alias so
 * the page can render their name in the header.
 */

include __DIR__ . '/settings.php';

$stmt = $pdo->query("
    SELECT a.ulan FROM artist_aliases a
    JOIN (
      SELECT artist_ulan FROM artist_relationships
      GROUP BY artist_ulan HAVING COUNT(*) >= 3
    ) r ON r.artist_ulan = a.ulan
    WHERE a.display = 1
    ORDER BY RAND() LIMIT 1
");
$ulan = (int)$stmt->fetchColumn();

if (!$ulan) {
    header('Location: /');
    exit;
}
header('Location: /?ulan=' . $ulan);
