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

    public function prepareNetworkForVisualization($network,$init_ulan) {

      $nodes = array();
      $links = array();

      $all_ulans = array();

      foreach($network as $n) {

        if($init_ulan == $n['artist_ulan']) {
          $degree = 0;
        }
        else {
          $degree = $n['degree'];
        }

        $new_node = array(
          'id' => $n['artist_ulan'],
          'group' => $degree,
          'artist' => $this->getArtistByUlan($n['artist_ulan']),
        );

        $new_rel_node = array(
          'id' => $n['related_ulan'],
          'group' => $n['degree'],
          'artist' => $this->getArtistByUlan($n['related_ulan']),
        );

        $new_link = array(
          'source' => $n['artist_ulan'],
          'target' => $n['related_ulan'],
          'group' => $n['relationship_type'],
        );

        if( (!in_array($n['artist_ulan'], $all_ulans)) ) {
          array_push($nodes, $new_node);
          array_push($all_ulans, $n['artist_ulan']);
        }

        if( (!in_array($n['related_ulan'], $all_ulans)) ) {
          array_push($nodes, $new_rel_node);
          array_push($all_ulans, $n['related_ulan']);
        }

        array_push($links, $new_link);

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
        $rel = $this->getRelationshipName($r['relationship_type']);
        array_push($rels, "<em>".$rel."</em> <a href='#' id=".$r['related_ulan']." class='artist-link'>".$name."</a>");
      }

      return $rels;

    }

    // gets the degrees of separation between two artists given two ulans
    public function baconator($source_artist, $target_artist) {
      $max_depth = 4;

      if($source_artist == $target_artist) {
        return "same artist";
      }

      $source_list = array();
      $target_list = array();

      $source_list[$source_artist] = null;
      $target_list[$target_artist] = null;

      $connecting_artists = $this->findConnections($source_artist, $target_artist, $source_list, $target_list);

      if(empty($connecting_artists)) {
        return null;
      }
      

      foreach($connecting_artists AS $c) {
        $connections = array((int)$c);
        $connecting_artist = $c;

        foreach($source_list AS $key => $val) {
          if($key == $connecting_artist) {
            array_unshift($connections, (int)$val);
          }
        }

        $connecting_artist = $c;
        foreach($target_list AS $key => $val) {
          if($key == $connecting_artist) {
            array_push($connections, (int)$val);
          }
        }
      }

      return $connections;

    }

    public function findConnections($init_source_ulan, $init_target_ulan, &$source_list, &$target_list, $max_depth=3) {

      $source_ulans = array(
        array($init_source_ulan, 0),
      );

      $target_ulans = array(
        array($init_target_ulan, 0),
      );

      while (count($source_ulans) > 0 || count($target_ulans) > 0) {
      
        $return_ulans = array();

        if(count($source_ulans) > 0) {
          $removed = array_shift($source_ulans);
          $new_source_ulan = $removed[0];
          $depth = $removed[1];

          if($depth > $max_depth) {
            return null;
          }

          $source_network = $this->getNetwork($new_source_ulan);
          foreach($source_network AS $sn) {
            $related_ulan = $sn['related_ulan'];
            if(!in_array($related_ulan, $source_list)) {
              $source_list[$related_ulan] = $new_source_ulan;
              $new_depth = $depth + 1;
              $push_value = array(
                $related_ulan, $new_depth
              );
              // keep the while loop going
              array_push($source_ulans, $push_value);
            }
            if(in_array($related_ulan, $target_list)) {
              array_push($return_ulans, $related_ulan);
            }
          }
        }

        if(count($return_ulans) > 0) {
          return $return_ulans;
        }

        if(count($target_ulans) > 0) {
          $removed = array_shift($target_ulans);
          $new_target_ulan = $removed[0];
          $depth = $removed[1];

          if($depth > $max_depth) {
            return null;
          }

          $target_network = $this->getNetwork($new_target_ulan);
          foreach($target_network AS $tn) {
            $related_ulan = $tn['related_ulan'];
            if(!in_array($related_ulan, $target_list)) {
              $target_list[$related_ulan] = $new_target_ulan;
              $new_depth = $depth + 1;
              $push_value = array(
                $related_ulan, $new_depth
              );
              array_push($target_ulans, $push_value);
            }
            if(in_array($related_ulan, $source_list)) {
              array_push($return_ulans, $related_ulan);
            }
          }
        }

        if(count($return_ulans) > 0) {
          return $return_ulans;
        }

      }
      
      return null;

    }

    public function prepareBacon($bacon, $source_ulan) {

      $rels = array();

      foreach($bacon AS $b) {

        $diff = array_diff($bacon, array($b));

        foreach($diff AS $d) {

          $data = array(
            'b' => $b,
            'd' => $d,
          );

          $sql = "SELECT * FROM artist_relationships WHERE artist_ulan=:b AND related_ulan=:d";
          $stmt = $this->pdo->prepare($sql);
          $stmt->execute($data);
          $relationships = $stmt->fetchAll(PDO::FETCH_ASSOC);

          if(!empty($relationships)) {
            array_push($rels, $relationships[0]);
          }

        }

      }

      $vis = $this->prepareNetworkForVisualization($rels, $source_ulan);

      return $vis;

    }

    public function isInNetwork($ulan1, $ulan2, $maxDegree = 1) {

    }

    public function breadthFirstSearch($source_ulan, $target_ulan) {

      if($source_ulan == $target_ulan) {
        return $source_ulan;
      }

      $paths = array();

      # The unvisited dictionaries are a mapping from page ID to a list of that page's parents' IDs.
      # None signifies that the source and target pages have no parent.
      $unvisited_forward = array(
        $source_ulan => array()
      );

      $unvisited_backward = array(
        $target_ulan => array()
      );

      # The visited dictionaries are a mapping from page ID to a list of that page's parents' IDs.
      $visited_forward = array();
      $visited_backward = array();

      # Set the initial forward and backward depths to 0.
      $forward_depth = 0;
      $backward_depth = 0;

      # Continue the breadth first search until a path has been found or either of the unvisited lists
      # are empty.
      while ( (count($paths) == 0) &&  (count($unvisited_forward) != 0) && (count($unvisited_backward) != 0) ) {
        # Run the next iteration of the breadth first search in whichever direction has the smaller number
        # of links at the next level.
        $forward_links_count = 0;
        foreach ($unvisited_forward as $key => $value) {
          error_log("forward foreach");
          $c = count($this->getNetwork($key) );
          $forward_links_count += $c;
        }

        $backward_links_count = 0;
        foreach ($unvisited_backward as $key => $value) {
          $c = count($this->getNetwork($key) );
          $backward_links_count += $c;
        }

        if ($forward_links_count < $backward_links_count) {
          #---  FORWARD BREADTH FIRST SEARCH  ---#
          $forward_depth++;

          # Fetch the pages which can be reached from the currently unvisited forward pages.
          # The replace() bit is some hackery to handle Python printing a trailing ',' when there is
          # only one key.
          $outgoing_links = array();
          foreach ($unvisited_forward as $key => $value) {
            $n = $this->getNetwork($key);
            foreach (array_column($n,'related_ulan') as $k => $v) {
              array_push($outgoing_links, array($key, $v) );
            }
          }

          # Mark all of the unvisited forward pages as visited.
          foreach ($unvisited_forward as $ulan => $val) {
            $visited_forward[$ulan] = $unvisited_forward[$ulan];
          }

          # Clear the unvisited forward dictionary.
          $unvisited_forward = array();

          foreach ($outgoing_links AS $key => $val) {
            $s = $val[0]; // target ulan
            $t = $val[1];
            if( (!in_array($t, $visited_forward)) && (!in_array($t, $unvisited_forward)) ) {
              $unvisited_backward[$t] = [$s];
            }
            elseif (in_array($t, $unvisited_forward)) {
              array_push($unvisited_forward[$t], $s);
            }
          }
        }
        else {
          error_log("backward search");
          #---  BACKWARD BREADTH FIRST SEARCH  ---#

          $backward_depth++;

          # Fetch the pages which can reach the currently unvisited backward pages.
          $incoming_links = array();
          foreach ($unvisited_backward as $key => $value) {
            $n = $this->getNetwork($key);
            foreach (array_column($n,'related_ulan') as $k => $v) {
              array_push($incoming_links, array($key, $v) );
            }
          }

          $incoming_links = array_values($incoming_links);

          # Mark all of the unvisited backward pages as visited.
          foreach ($unvisited_backward as $ulan => $val) {
            $visited_backward[$ulan] = $unvisited_backward[$ulan];
          }

          # Clear the unvisited backward dictionary.
          $unvisited_backward = array();

          foreach ($incoming_links AS $key => $val) {
            $t = $val[0]; // target ulan
            $s = $val[1];
            if( (!in_array($s, $visited_backward)) && (!in_array($s, $unvisited_backward)) ) {
              $unvisited_backward[$s] = [$t];
            }
            elseif (in_array($s, $unvisited_backward)) {
              array_push($unvisited_backward[$s], $t);
            }
          }

        }

        #---  CHECK FOR PATH COMPLETION  ---#
        # The search is complete if any of the pages are in both unvisited backward and unvisited, so
        # find the resulting paths.
        error_log("unvisited_forward");
        error_log(print_r($unvisited_forward,true));
        error_log("unvisited_backward");
        error_log(print_r($unvisited_backward,true));

        foreach ($unvisited_forward as $k => $v) {

          if( array_key_exists($k, $unvisited_backward) ) {
            $paths_from_source = $this->get_paths($unvisited_forward[$k], $visited_forward);
            $paths_from_target = $this->get_paths($unvisited_backward[$k], $visited_backward);



          }
        }


        // for page_id in unvisited_forward:
        //   if page_id in unvisited_backward:
        //     paths_from_source = get_paths(unvisited_forward[page_id], visited_forward)
        //     paths_from_target = get_paths(unvisited_backward[page_id], visited_backward)

        //     for path_from_source in paths_from_source:
        //       for path_from_target in paths_from_target:
        //         current_path = list(path_from_source) + [page_id] + list(reversed(path_from_target))

        //         # TODO: This line shouldn't be required, but there are some unexpected duplicates.
        //         if current_path not in paths:
        //           paths.append(current_path)

        // $i = 0;

        // if($i > 20){
        //   break;
        // }

        // $i++;
      }

    }

    public function getPaths($ulans, $visited) {
      error_log("GET PATHS");
      error_log(print_r($ulans,true));
      error_log(print_r($visited,true));
      $paths = array();

      return $paths;
    }


  }

?>