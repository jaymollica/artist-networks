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
    // returns an int [1-6]
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
      error_log("CONNECT");
      error_log(print_r($connecting_artists, true));

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
          error_log("for each 2");
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

          error_log($b);
          error_log($d);

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

  }

?>