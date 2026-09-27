<?php

  class artistNetworks {

    protected $pdo;

    public function __construct(PDO $db) {

      $this->pdo = $db;

    }

    // get the network for a specified ulan
    // returns array
    public function getNetwork($ulan) {

      $data = array(
        'ulan' => $ulan,
      );

      $sql = "SELECT * FROM artist_relationships WHERE artist_ulan=:ulan";
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($data); 
      $network = $stmt->fetchAll(PDO::FETCH_ASSOC);

      return $network;

    }

    // get name of relationship
    // returns str
    public function getRelationshipName($relationship_id) {
      $data = array(
        'id' => $relationship_id,
      );

      $sql = "SELECT * FROM relationship_types WHERE getty_id=:id";
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($data); 
      $relationship_type = $stmt->fetchAll(PDO::FETCH_ASSOC);

      return $relationship_type[0]['relationship'];

    }

    public function getArtistNote($ulan) {
      $data = array(
        'ulan' => $ulan,
      );

      $sql = "SELECT note FROM artist_notes WHERE ulan=:ulan";
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($data);
      $note = $stmt->fetchAll(PDO::FETCH_ASSOC);

      return $note[0]['note'];

    }

    public function getArtistBio($ulan) {
      $data = array(
        'ulan' => $ulan,
      );

      $sql = "SELECT biography FROM biographies WHERE ulan=:ulan AND preferred=1";
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($data);
      $bio = $stmt->fetchAll(PDO::FETCH_ASSOC);

      return $bio[0]['biography'];

    }

    // get the display name of an artist from an ulan
    // returns a str
    public function getArtistByUlan($ulan) {
      $data = array(
        'ulan' => $ulan,
      );

      $sql = "SELECT * FROM artist_aliases WHERE ulan=:ulan";
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($data);
      $artist_aliases = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // display alias is only marked if multiple aliases are present
      if(count($artist_aliases) > 1) {
        $display = array_search(1, array_column($artist_aliases, 'display'));
      }
      else {
        $display = 0;
      }

      if(isset($artist_aliases[$display]['alias'])) {
        $display_alias = $artist_aliases[$display]['alias'];
      }
      else {
        $display_alias = array();
      }

      return $display_alias;

    }

    public function getArtistByName($name) {
      $data = array(
        'name' => "%$name%",
      );

      $sql = "SELECT aa.*
              FROM artist_aliases aa
              WHERE aa.alias LIKE :name AND
              aa.id = (
                SELECT aa2.id
                FROM artist_aliases aa2
                WHERE aa2.alias LIKE :name AND
                aa2.ulan = aa.ulan 
                ORDER BY aa2.display DESC, aa2.preferred DESC
                LIMIT 1
              )";

      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($data);
      $artist_aliases = $stmt->fetchAll(PDO::FETCH_ASSOC);

      return $artist_aliases;

    }

    // prepares a result of getNetwork() for a grpah visualization
    // returns an array of two arrays (nodes) and (links)

      // $nodes = array(
      //   array(
      //     'id' => $ulan, // int
      //     'group' => $degree, // int, degree of separation
      //     'artist' => $artist, // name of artist
      //   ),
      //   array()...
      // );

      // $links = array(
      //   array(
      //     'source' => $ulan1,
      //     'target' => $ulan2,
      //     'group' => $rel_type,
      //   ),
      //   array()...
      // );

    // Bucket a relationship_type name into a coarse layout category.
    private function _categorize($relName) {
      $n = strtolower((string)$relName);
      if (preg_match('/teacher|student|pupil|apprentice|master|taught|mentor/', $n)) return 'mentorship';
      if (preg_match('/parent|child|sibling|spouse|uncle|aunt|nephew|niece|grandparent|grandchild|cousin|married|father|mother|\bson\b|daughter|brother|sister|in.law|step|family|familial|partner of/', $n)) return 'family';
      if (preg_match('/member|partner|firm|founded|founding/', $n)) return 'affiliation';
      if (preg_match('/collaborat|colleague|worked|patron|associate|friend|related/', $n)) return 'collaboration';
      return 'other';
    }

    // Bulk-load birth years for a set of ULANs (preferred biographies only).
    private function _birthYearsFor(array $ulans) {
      $out = array();
      if (!$ulans) return $out;
      $place = implode(',', array_fill(0, count($ulans), '?'));
      $sql = "SELECT ulan, MAX(birth_year) AS yr FROM biographies
              WHERE ulan IN ($place) AND preferred = 1 AND birth_year > 0
              GROUP BY ulan";
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute(array_values($ulans));
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['ulan']] = (int)$r['yr'];
      }
      return $out;
    }

    // Bulk-load death years for a set of ULANs (preferred biographies only).
    private function _deathYearsFor(array $ulans) {
      $out = array();
      if (!$ulans) return $out;
      $place = implode(',', array_fill(0, count($ulans), '?'));
      $sql = "SELECT ulan, MAX(death_year) AS yr FROM biographies
              WHERE ulan IN ($place) AND preferred = 1 AND death_year > 0
              GROUP BY ulan";
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute(array_values($ulans));
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['ulan']] = (int)$r['yr'];
      }
      return $out;
    }

    // Bulk-load display names for a set of ULANs, mirroring getArtistByUlan's
    // logic (display=1 wins, else the highest-preferred alias).
    private function _displayNamesFor(array $ulans) {
      $out = array();
      if (!$ulans) return $out;
      $place = implode(',', array_fill(0, count($ulans), '?'));
      $sql = "SELECT a.ulan, a.alias
              FROM artist_aliases a
              WHERE a.ulan IN ($place)
                AND a.id = (
                  SELECT a2.id FROM artist_aliases a2
                  WHERE a2.ulan = a.ulan
                  ORDER BY a2.display DESC, a2.preferred DESC, a2.id ASC
                  LIMIT 1
                )";
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute(array_values($ulans));
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['ulan']] = $r['alias'];
      }
      return $out;
    }

    // Bulk-load relationship-type names keyed by getty_id.
    private function _relNamesFor(array $codes) {
      $out = array();
      if (!$codes) return $out;
      $place = implode(',', array_fill(0, count($codes), '?'));
      $sql = "SELECT getty_id, relationship FROM relationship_types WHERE getty_id IN ($place)";
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute(array_values($codes));
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['getty_id']] = $r['relationship'];
      }
      return $out;
    }

    public function prepareNetworkForVisualization($network,$init_ulan) {

      $nodes = array();
      $links = array();

      $all_ulans = array();
      $first_degree_category = array(); // ulan => category (relationship to center)

      foreach($network as $n) {
        if (!in_array($n['artist_ulan'], $all_ulans)) $all_ulans[] = (int)$n['artist_ulan'];
        if (!in_array($n['related_ulan'], $all_ulans)) $all_ulans[] = (int)$n['related_ulan'];
      }

      // bulk fetch birth years, death years, display names, and relationship-type names
      $birthYears   = $this->_birthYearsFor($all_ulans);
      $deathYears   = $this->_deathYearsFor($all_ulans);
      $displayNames = $this->_displayNamesFor($all_ulans);
      $relCodes     = array_unique(array_map('intval', array_column($network, 'relationship_type')));
      $relNames     = $this->_relNamesFor($relCodes);

      // compute first-degree categories (only for edges from the center artist)
      foreach ($network as $n) {
        if ((int)$n['artist_ulan'] === (int)$init_ulan) {
          $first_degree_category[(int)$n['related_ulan']] = $this->_categorize($relNames[(int)$n['relationship_type']] ?? '');
        }
      }

      $seen = array();

      foreach($network as $n) {

        $artistUlan  = (int)$n['artist_ulan'];
        $relatedUlan = (int)$n['related_ulan'];
        $relCode     = (int)$n['relationship_type'];

        if($init_ulan == $artistUlan) {
          $degree = 0;
        }
        else {
          $degree = $n['degree'];
        }

        if (!isset($seen[$artistUlan])) {
          $seen[$artistUlan] = true;
          $nodes[] = array(
            'id'         => $artistUlan,
            'group'      => $degree,
            'artist'     => $displayNames[$artistUlan] ?? '',
            'birth_year' => $birthYears[$artistUlan] ?? null,
            'death_year' => $deathYears[$artistUlan] ?? null,
            'category'   => ($artistUlan === (int)$init_ulan)
                              ? 'self'
                              : ($first_degree_category[$artistUlan] ?? null),
          );
        }

        if (!isset($seen[$relatedUlan])) {
          $seen[$relatedUlan] = true;
          $nodes[] = array(
            'id'         => $relatedUlan,
            'group'      => $n['degree'],
            'artist'     => $displayNames[$relatedUlan] ?? '',
            'birth_year' => $birthYears[$relatedUlan] ?? null,
            'death_year' => $deathYears[$relatedUlan] ?? null,
            'category'   => $first_degree_category[$relatedUlan] ?? null,
          );
        }

        $links[] = array(
          'source'       => $artistUlan,
          'target'       => $relatedUlan,
          'group'        => $relCode,
          'category'     => $this->_categorize($relNames[$relCode] ?? ''),
          'relationship' => $relNames[$relCode] ?? '',
          'start'        => ((int)$n['start'] > 0) ? (int)$n['start'] : null,
          'end'          => ((int)$n['end'] > 0) ? (int)$n['end'] : null,
        );
      }

      // If Getty has no relationships on file for the searched artist, the
      // loop above never adds them as a node. Render them as a sole node
      // so the page still shows their name + bio rather than going blank.
      if (empty($nodes)) {
        $center = (int)$init_ulan;
        $singleton = $this->_displayNamesFor([$center]);
        $byear     = $this->_birthYearsFor([$center]);
        $dyear     = $this->_deathYearsFor([$center]);
        $nodes[] = array(
          'id'         => $center,
          'group'      => 0,
          'artist'     => $singleton[$center] ?? '',
          'birth_year' => $byear[$center] ?? null,
          'death_year' => $dyear[$center] ?? null,
          'category'   => 'self',
          'solo'       => true, // signal to the front-end to render a "no Getty relationships" notice
        );
      }

      $nodes_links = array(
        'nodes' => $nodes,
        'links' => $links,
      );

      return $nodes_links;

    }


    // gets array of relationships in semantic terms:

    // array(
    //   'cousin of someartist',
    //   'taught by someotherartist',
    //   'worked with anotherartist',
    //   ...
    // );
    public function getSemanticGraph($ulan) {

      $data = array(
        'ulan' => $ulan,
      );

      $sql = "SELECT * FROM artist_relationships WHERE artist_ulan=:ulan";
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($data);
      $relationships = $stmt->fetchAll(PDO::FETCH_ASSOC);

      $rels = array();

      foreach($relationships AS $r) {
        $name = $this->getArtistByUlan($r['related_ulan']);
        $parts = array_map('trim', explode(',', $name));
        if (count($parts) >= 2) { $f = array_shift($parts); $name = implode(' ', $parts) . ' ' . $f; }
        $rel = $this->getRelationshipName($r['relationship_type']);
        array_push($rels, "<em>".htmlspecialchars($rel)."</em> <a href='#' id=".(int)$r['related_ulan']." class='artist-link'>".htmlspecialchars($name)."</a>");
      }

      return $rels;

    }


    // In-memory adjacency list: ulan => [{ulan, type, start, end}, ...]
    // Loaded once per request; cheap to build (one SELECT) and makes BFS instant.
    private $adj = null;
    private function _loadAdjacency() {
      if ($this->adj !== null) return;
      $this->adj = [];
      $sql = "SELECT artist_ulan, related_ulan, relationship_type, start, end FROM artist_relationships";
      $stmt = $this->pdo->query($sql);
      while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $a = (int)$r['artist_ulan'];
        $b = (int)$r['related_ulan'];
        $this->adj[$a][] = [
          'ulan'  => $b,
          'type'  => (int)$r['relationship_type'],
          'start' => (int)$r['start'] > 0 ? (int)$r['start'] : null,
          'end'   => (int)$r['end']   > 0 ? (int)$r['end']   : null,
        ];
      }
    }

    // Returns up to $maxPaths shortest paths between source and target.
    // Each is ['connections' => [ulan, ulan, ...], 'degrees' => N]. Empty if no path.
    public function breadthFirstSearch($source, $target, $maxPaths = 5) {
      if ((int)$source === (int)$target) return [];
      $this->_loadAdjacency();
      $source = (int)$source;
      $target = (int)$target;

      // Single-direction BFS tracking all predecessors at the shortest depth per node.
      $dist  = [$source => 0];
      $preds = [$source => []];
      $queue = [$source];
      $targetDepth = null;

      while ($queue) {
        $depthHere = $dist[$queue[0]] + 1;
        if ($targetDepth !== null && $depthHere > $targetDepth) break;
        $next = [];
        foreach ($queue as $u) {
          foreach ($this->adj[$u] ?? [] as $edge) {
            $v = $edge['ulan'];
            if (!isset($dist[$v])) {
              $dist[$v]  = $depthHere;
              $preds[$v] = [$u];
              $next[]    = $v;
              if ($v === $target) $targetDepth = $depthHere;
            } elseif ($dist[$v] === $depthHere && !in_array($u, $preds[$v], true)) {
              // Same predecessor can appear twice if A→B has multiple
              // relationship rows (e.g. teacher of + employee of). Dedupe so
              // we don't enumerate identical paths.
              $preds[$v][] = $u;
            }
          }
        }
        $queue = $next;
      }

      if (!isset($dist[$target])) return [];

      // DFS-backtrack to enumerate shortest paths.
      $paths = [];
      $stack = [[$target]];
      while ($stack && count($paths) < $maxPaths) {
        $path = array_pop($stack);
        $head = $path[0];
        if ($head === $source) {
          $paths[] = ['connections' => $path, 'degrees' => count($path)];
          continue;
        }
        foreach ($preds[$head] ?? [] as $p) {
          if (in_array($p, $path, true)) continue; // no cycles
          $stack[] = array_merge([$p], $path);
        }
      }

      return $paths;
    }

    // Find an edge between u and v (in either direction) using the adjacency.
    private function _edgeBetween($u, $v) {
      foreach ($this->adj[$u] ?? [] as $e) {
        if ($e['ulan'] === $v) return ['type' => $e['type'], 'start' => $e['start'], 'end' => $e['end'], 'forward' => true];
      }
      foreach ($this->adj[$v] ?? [] as $e) {
        if ($e['ulan'] === $u) return ['type' => $e['type'], 'start' => $e['start'], 'end' => $e['end'], 'forward' => false];
      }
      return null;
    }

    public function prepareBacon($bacon, $ulan1, $ulan2) {
      $result = ['paths' => []];
      if (!$bacon) return $result;

      $this->_loadAdjacency();

      $allUlans = [];
      foreach ($bacon as $path) {
        foreach ($path['connections'] as $u) $allUlans[(int)$u] = true;
      }
      $allUlans = array_keys($allUlans);
      $names      = $this->_displayNamesFor($allUlans);
      $birthYears = $this->_birthYearsFor($allUlans);
      $deathYears = $this->_deathYearsFor($allUlans);

      $allRelCodes = [];
      foreach ($bacon as $path) {
        $cs = $path['connections'];
        for ($i = 0; $i < count($cs) - 1; $i++) {
          $e = $this->_edgeBetween((int)$cs[$i], (int)$cs[$i+1]);
          if ($e) $allRelCodes[$e['type']] = true;
        }
      }
      $relNames = $this->_relNamesFor(array_keys($allRelCodes));

      foreach ($bacon as $path) {
        $cs    = $path['connections'];
        $nodes = [];
        $links = [];
        foreach ($cs as $i => $u) {
          $u = (int)$u;
          $nodes[] = [
            'id'         => $u,
            'group'      => $i,
            'target'     => $u === (int)$ulan2,
            'source'     => $u === (int)$ulan1,
            'artist'     => $names[$u] ?? ('ULAN ' . $u),
            'birth_year' => $birthYears[$u] ?? null,
            'death_year' => $deathYears[$u] ?? null,
          ];
          if ($i > 0) {
            $a    = (int)$cs[$i-1];
            $b    = $u;
            $edge = $this->_edgeBetween($a, $b);
            $relName = $edge ? ($relNames[$edge['type']] ?? '') : '';
            $links[] = [
              'source'       => $a,
              'target'       => $b,
              'group'        => $edge ? $edge['type'] : 0,
              'relationship' => $relName,
              'category'     => $this->_categorize($relName),
              'start'        => $edge['start'] ?? null,
              'end'          => $edge['end']   ?? null,
              'reverse'      => $edge ? !$edge['forward'] : false,
            ];
          }
        }
        $result['paths'][] = ['nodes' => $nodes, 'links' => $links];
      }

      return $result;
    }

  }

?>