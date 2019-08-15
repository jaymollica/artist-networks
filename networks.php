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

    public function blendNetworks($network1, $network2) {

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
    // returns an int [1-6]
    // public function baconator($left_artist, $right_artist) {
    //   $max_depth = 4;

    //   if($left_artist == $right_artist) {
    //     return $left_artist;
    //   }

    //   $left = array();
    //   $right = array();

    //   $left[$left_artist] = array();
    //   $right[$right_artist] = array();

    //   $center_artists = $this->blendNetworks($left_artist, $right_artist, $left_members, $right_members);

    //   if ( empty($center_artists) ) {
    //     return "These artists are over ".$max_depth." degrees apart.";
    //   }

    

    // // for center_artist in center_artists:
    // //     connections = [center_artist]
    // //     #print("center artist", center_artist)
    // //     artist = center_artist
    // //     while left[artist] != None:
    // //         artist = left[artist]
    // //         connections.insert(0, artist)
            
    // //     artist = center_artist
    // //     while right[artist] != None:
    // //         artist = right[artist]
    // //         connections.append(artist)
    // //     print("AHHHH", center_artist, connections, "degrees: ", len(connections) - 1)


    //   return $center_artists;

    // }

    // // def do_things(in_left_artist, in_right_artist, left, right):
    // // left_artists = [(1, in_left_artist)]
    // // right_artists = [(1, in_right_artist)]
    // // while len(left_artists) > 0 or len(right_artists) > 0:
    // //     print("while", left_artists, right_artists)
    // //     return_val = []
    // //     if len(left_artists) > 0:
    // //         depth, left_artist = left_artists.pop(0)
    // //         if depth > MAX_DEPTH:
    // //             print("max depth!!!")
    // //             return None
    // //         left_network = get_network(left_artist)

    // //         print("left network", depth, left_network)
    // //         for artist in left_network:
    // //             if artist not in left:
    // //                 left[artist] = left_artist
    // //                 left_artists.append((depth + 1, artist))
    // //             if artist in right:
    // //                 return_val.append(artist)
    // //     if len(return_val) > 0:
    // //         return return_val
    // //     if len(right_artists) > 0:
    // //         depth, right_artist = right_artists.pop(0)
    // //         if depth > MAX_DEPTH:
    // //             print("max depth!!!")
    // //             return None
    // //         right_network = get_network(right_artist)

    // //         print("right network", depth, right_network)
    // //         for artist in right_network:
    // //             if artist not in right:
    // //                 right[artist] = right_artist
    // //                 right_artists.append((depth + 1, artist))
    // //             if artist in left:
    // //                 return_val.append(artist)
    // //     if len(return_val) > 0:
    // //         return return_val
    // // return None

    // public function blendNetworks($left_artist, $right_artist, $left_members, $right_members, $max_depth = 4) {

    //   $left_artists = array( 
    //     array(1, $left_members),
    //   );

    //   $right_artists = array(
    //     array(1, $right_members),
    //   );

    //   while ( count($left_artists) > 0 || count($right_artists) > 0 ) {
    //     print ("while");
    //     $return_array = array();

    //     if(count($left_artists) > 0) {
    //       $removed = array_shift($left_artists);
    //       $depth = $removed[0];
    //       $la = $removed[1];

    //       if($depth == $max_depth) {
    //         return array();
    //       }

    //       $left_network = $this->getNetwork($la);
    //       foreach ($left_network AS $ln) {
    //         $left_ulan = $ln['artist_ulan'];
    //         if(in_array($left_ulan, $left)) {
    //           $left[$left_ulan] = $left_artist;
    //           array_push( $left_artists, array($depth++, $left_ulan) );
    //           if(in_array($left_ulan, $right)) {
    //             array_push($return_array, $left_ulan);
    //           }
    //         }

    //         if(count($return_array > 0)) {
    //           return $return_array;
    //         }
            
    //       }

    //     }

    //     if(count($right_artists) > 0) {
    //       $removed = array_shift($right_artists);
    //       $depth = $removed[0];
    //       $ra = $removed[1];

    //       if($depth == $max_depth) {
    //         return array();
    //       }

    //       $right_network = $this->getNetwork($ra);
    //       foreach ($left_network AS $ln) {
    //         $left_ulan = $ln['artist_ulan'];
    //         if(in_array($left_ulan, $left)) {
    //           $left[$left_ulan] = $left_artist;
    //           array_push( $left_artists, array($depth++, $left_ulan) );
    //           if(in_array($left_ulan, $right)) {
    //             array_push($return_array, $left_ulan);
    //           }
    //         }

    //         if(count($return_array > 0)) {
    //           return $return_array;
    //         }
            
    //       }

    //     }


    //   }



    //   return array();
    // }

    // is one artist in the other artists network?
    // returns boolean
    public function isInNetwork($ulan1, $ulan2, $maxDegree = 1) {

    }

  }

?>