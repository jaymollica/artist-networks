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

      $display_alias = $artist_aliases[$display]['alias'];

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

    public function prepareNetworkForVisualization($network) {

      $nodes = array();
      $links = array();

      $all_ulans = array();

      foreach($network as $n) {
        $new_node = array(
          'id' => $n['artist_ulan'],
          'group' => 0,
          'artist' => $this->getArtistByUlan($n['artist_ulan']),
        );

        $new_rel_node = array(
          'id' => $n['related_ulan'],
          'group' => 1,
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
    public function getDegreesOfSeparation($ulan1, $ulan2) {

    }

    // is one artist in the other artists network?
    // returns boolean
    public function isInNetwork($ulan1, $ulan2, $maxDegree = 1) {

    }

  }

?>