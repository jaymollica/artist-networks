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

    private function _breadthFristSearch($source_ulan, $target_ulan, &$left, &$right) {
      $max_depth = 20;
      $left_artists = [
        "info" => [
          "depth" => 1,
          "ulan" => $source_ulan,
        ],
      ];

      $right_artists = [
        "info" => [
          "depth" => 1,
          "ulan" => $target_ulan,
        ],
      ];

      while ( count($left_artists) > 0 && count($right_artists) > 0 ) {
        $return_val = array();
        if ( count($left_artists) > 0 ) {
          
          $artist = array_pop($left_artists);
          $ulan = $artist["ulan"];
          $depth = $artist["depth"];
          if ($depth > $max_depth) {
            error_log("too much deptth");
            return [];
          }

          $network = $this->getNetwork($ulan);
          foreach ($network as $n_artist) {

            if ( !array_key_exists($n_artist["related_ulan"], $left) ) {
              $left[$n_artist["related_ulan"]] = $ulan;
              array_push($left_artists, [
                "depth" => $depth + 1,
                "ulan" => $n_artist["related_ulan"],
              ]);

            }
            else {
              //do nothing
            }
            
            if ( array_key_exists($n_artist["related_ulan"], $right) ) {
              array_push($return_val, $n_artist["related_ulan"]);
            }

          }
        }

        if ( count($return_val) > 0 ) {
          return $return_val;
        }

        if ( count($right_artists) > 0 ) {
          
          $artist = array_pop($right_artists);
          $ulan = $artist["ulan"];
          $depth = $artist["depth"];
          if ($depth > $max_depth) {
            return [];
          }
          $network = $this->getNetwork($ulan);
          foreach ($network as $n_artist) {

            if ( !array_key_exists($n_artist["related_ulan"], $right) ) {
              $right[$n_artist["related_ulan"]] = $ulan;
              array_push($right_artists, [
                "depth" => $depth + 1,
                "ulan" => $n_artist["related_ulan"],
              ]);

            }
            else {
              // do nothing
            }
            
            if ( array_key_exists($n_artist["related_ulan"], $left) ) {
              array_push($return_val, $n_artist["related_ulan"]);
            }
          }
        }

        if ( count($return_val) > 0 ) {
          return $return_val;
        }
      }
      return [];
    }

    public function breadthFirstSearch($source_ulan, $target_ulan) {

      if($source_ulan == $target_ulan) {
        return [];
      }

      $left = [
        $source_ulan => "",
      ];

      $right = [
        $target_ulan => "",
      ];

      $centers = $this->_breadthFristSearch($source_ulan, $target_ulan, $left, $right);

      $return_val = [];
      
      foreach ($centers as $center_artist) {
        $connections = [$center_artist];
        $artist = $center_artist;
        while ( array_key_exists($artist, $left) && $left[$artist] != "" ) {
          $artist = $left[$artist];
          array_splice($connections, 0, 0, $artist);
        }
        $artist = $center_artist;
        while( array_key_exists($artist, $right) && $right[$artist] != "" ) {
          $artist = $right[$artist];
          array_push($connections, $artist);
        }
        array_push($return_val, [
          "connections" => $connections,
          "degrees" => count( $connections ),
        ]);
      }

      return $return_val;
    }

    public function prepareBacon($bacon) {

      $nodes = array();
      $links = array();

      $all_ulans = array();

      foreach($bacon as $key => $val) {

        $vc = count($val);
        $i = 0;
        $last = false;

        foreach($val['connections'] as $k => $v) {

          $new_node = array(
            'id' => $v,
            'group' => $i,
            'artist' => $this->getArtistByUlan($v),
          );

          if($last) {

            $new_link = array(
              'source' => $v,
              'target' => $last,
              'group' => 1,
            );

            array_push($links, $new_link);

          }

          if( (!in_array($v, $all_ulans)) ) {
            array_push($nodes, $new_node);
            array_push($all_ulans, $v);
          }

          $last = $v;

          $i++;

        }

      }

      $nodes_links = array(
        'nodes' => $nodes,
        'links' => $links,
      );

      return $nodes_links;

    }

  }

?>