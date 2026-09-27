<?php

  header('Content-type: application/json');

  include('settings.php');

  if(isset($_GET['q'])) {

    $q = $_GET['q'];

    $artists = $nw->getArtistByName($q);

    $json=array();

    foreach ($artists as $artist) {
      array_push($json, array($artist['alias'], $artist['ulan']) );
    }

    echo json_encode($json);
  }
  elseif(isset($_GET['ulan'])) {

    $ulan = $_GET['ulan'];

    if( isset($_GET['depth']) && is_numeric($_GET['depth']) ) {
      $depth = $_GET['depth'];
    }
    else {
      $depth = 2;
    }

    $network = $nw->getNetwork($ulan);
    foreach ($network AS $k => $n) {
      $network[$k]['degree'] = 1;
    }

    if ($depth >= 2) {
      foreach ($network as $n) {
        $second = $nw->getNetwork($n['related_ulan']);
        foreach ($second AS $k => $n2) {
          $second[$k]['degree'] = 2;
        }
        $network = array_merge($network, $second);
      }
    }

    $data = $nw->prepareNetworkForVisualization($network, $ulan);

    echo json_encode($data);
  }
  elseif(isset($_GET['note'])) {

    $ulan = $_GET['note'];
    $note = $nw->getArtistNote($ulan);
    $bio = $nw->getArtistBio($ulan);
    $rels = $nw->getSemanticGraph($ulan);

    $info = array(
      'note' => $note,
      'bio' => $bio,
      'rels' => $rels,
    );

    echo json_encode($info);
  }
  elseif(isset($_GET['trustees_q'])) {

    // Autocomplete for the trustee networks page.
    $q = trim((string)$_GET['trustees_q']);
    if ($q === '' || strlen($q) < 2) { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("SELECT canonical_key, display_name, board_count
        FROM trustees WHERE display_name LIKE ?
        ORDER BY board_count DESC, display_name LIMIT 12");
    $stmt->execute(['%' . $q . '%']);
    echo json_encode($stmt->fetchAll(PDO::FETCH_NUM));

  }
  elseif(isset($_GET['trustees_directory'])) {

    // Directory data for the trustees browse panel: top multi-board
    // trustees + all museums (with trustee counts) for click-to-open
    // navigation alongside the search box.
    $bridges = $pdo->query("SELECT canonical_key, display_name, board_count
        FROM trustees WHERE board_count > 1
        ORDER BY board_count DESC, display_name LIMIT 50")->fetchAll(PDO::FETCH_NUM);
    $museums = $pdo->query("SELECT m.ein, m.name, m.city, m.state, m.slug,
        (SELECT COUNT(*) FROM trustees t WHERE FIND_IN_SET(m.ein, t.museum_eins) > 0) AS trustee_count
        FROM museums m ORDER BY m.name")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['bridges' => $bridges, 'museums' => $museums]);

  }
  elseif(isset($_GET['museum_trustees'])) {

    // Trustees for a single museum, ordered so bridge trustees surface first.
    $ein = (string)$_GET['museum_trustees'];
    $stmt = $pdo->prepare("SELECT canonical_key, display_name, board_count
        FROM trustees WHERE FIND_IN_SET(?, museum_eins) > 0
        ORDER BY board_count DESC, display_name");
    $stmt->execute([$ein]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_NUM));

  }
  elseif(isset($_GET['trustees_graph'])) {

    // Trustee social-network data: nodes = trustees, links = co-board edges.
    // Optional ?depth=2 expands to neighbors-of-neighbors.
    $centerKey = (string)$_GET['trustees_graph'];
    $depth     = (isset($_GET['depth']) && (int)$_GET['depth'] === 2) ? 2 : 1;

    // Resolve center trustee
    $stmt = $pdo->prepare('SELECT canonical_key, display_name, museum_eins, board_count FROM trustees WHERE canonical_key = ?');
    $stmt->execute([$centerKey]);
    $center = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$center) { echo json_encode(['error' => 'unknown trustee']); exit; }

    // Get museum metadata + latest filing period per museum (used for
    // "departed" / "current" classification).
    $museumRows = $pdo->query("SELECT ein, name, city, state, slug FROM museums")->fetchAll(PDO::FETCH_ASSOC);
    $museum = [];
    foreach ($museumRows as $m) $museum[$m['ein']] = $m;
    $latestByMuseum = [];
    foreach ($pdo->query("SELECT ein, MAX(tax_period) AS latest FROM museum_officers GROUP BY ein") as $r) {
        $latestByMuseum[$r['ein']] = $r['latest'];
    }

    // Build all co-board relationships once. For our scale (~3k trustees,
    // ~20 cross-board), this is cheap.
    $allTrustees = $pdo->query("SELECT canonical_key, display_name, museum_eins, board_count FROM trustees")->fetchAll(PDO::FETCH_ASSOC);
    $byMuseum = []; // ein => [canonical_key, ...]
    $byKey    = []; // canonical_key => row
    foreach ($allTrustees as $t) {
        $byKey[$t['canonical_key']] = $t;
        foreach (explode(',', $t['museum_eins']) as $e) {
            if ($e !== '') $byMuseum[$e][] = $t['canonical_key'];
        }
    }

    // BFS from center to depth N
    $visited = [$centerKey => 0]; // key => degree
    $queue   = [$centerKey];
    $links   = []; // "keyA|keyB" => ['museums' => [ein, ein, ...]]
    for ($d = 0; $d < $depth; $d++) {
        $next = [];
        foreach ($queue as $u) {
            $uRow = $byKey[$u] ?? null;
            if (!$uRow) continue;
            foreach (explode(',', $uRow['museum_eins']) as $ein) {
                if ($ein === '') continue;
                foreach (($byMuseum[$ein] ?? []) as $v) {
                    if ($v === $u) continue;
                    // record edge
                    $a = $u < $v ? $u : $v;
                    $b = $u < $v ? $v : $u;
                    $lk = "$a||$b";
                    if (!isset($links[$lk])) $links[$lk] = ['source' => $a, 'target' => $b, 'museums' => []];
                    if (!in_array($ein, $links[$lk]['museums'], true)) $links[$lk]['museums'][] = $ein;
                    // schedule visit
                    if (!isset($visited[$v])) {
                        $visited[$v] = $d + 1;
                        if ($d + 1 < $depth) $next[] = $v;
                    }
                }
            }
        }
        $queue = $next;
    }

    // Load per-trustee tenures keyed for fast lookup.
    $visitedKeys = array_keys($visited);
    $tenureByKeyEin = []; // key => ein => [first, last, count]
    if ($visitedKeys) {
        $place = implode(',', array_fill(0, count($visitedKeys), '?'));
        $tStmt = $pdo->prepare("SELECT canonical_key, ein, first_period, last_period, filings_count
                                FROM trustee_tenures WHERE canonical_key IN ($place)");
        $tStmt->execute($visitedKeys);
        foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $tenureByKeyEin[$t['canonical_key']][$t['ein']] = [
                'first' => $t['first_period'],
                'last'  => $t['last_period'],
                'count' => (int)$t['filings_count'],
            ];
        }
    }

    // Build node list. Museums are returned as [{name, ein, url, ...tenure}, ...]
    // so the frontend can show "(2014–2023, departed)" inline with the board.
    $nodes = [];
    foreach ($visited as $key => $deg) {
        $row = $byKey[$key] ?? null;
        if (!$row) continue;
        $eins = array_filter(explode(',', $row['museum_eins']));
        $museumsObj = [];
        foreach ($eins as $e) {
            if (!isset($museum[$e])) continue;
            $tn = $tenureByKeyEin[$key][$e] ?? null;
            $firstYear = $tn ? (int)substr($tn['first'], 0, 4) : null;
            $lastYear  = $tn ? (int)substr($tn['last'], 0, 4)  : null;
            $latestForMuseum = $latestByMuseum[$e] ?? null;
            $departed = $tn && $latestForMuseum && $tn['last'] < $latestForMuseum;
            $latestYear = $latestForMuseum ? (int)substr($latestForMuseum, 0, 4) : null;
            $museumsObj[] = [
                'ein'         => $e,
                'name'        => $museum[$e]['name'],
                'url'         => 'https://projects.propublica.org/nonprofits/organizations/' . $e,
                'first_year'  => $firstYear,
                'last_year'   => $lastYear,
                'departed'    => $departed,
                'latest_year' => $latestYear,
            ];
        }
        $nodes[] = [
            'id'           => $key,
            'name'         => $row['display_name'],
            'group'        => $deg,
            'board_count'  => (int)$row['board_count'],
            'museums'      => $museumsObj,
        ];
    }

    echo json_encode([
        'center' => ['id' => $center['canonical_key'], 'name' => $center['display_name']],
        'nodes'  => $nodes,
        'links'  => array_values($links),
        'depth'  => $depth,
    ]);

  }
  elseif(isset($_GET['bacon'])) {

    $ulan1 = $_GET['ulan1'];
    $ulan2 = $_GET['ulan2'];

    // return array of degrees of separation per connection
    $bacon = $nw->breadthFirstSearch($ulan1, $ulan2);

    // prepare the bacon array for visualization
    $diagram = $nw->prepareBacon($bacon, $ulan1, $ulan2);

    echo json_encode($diagram);

  }

?>