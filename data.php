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

    foreach ($network as $n) {
      $second = $nw->getNetwork($n['related_ulan']);
      foreach ($second AS $k => $n) {
        $second[$k]['degree'] = 2;
      }
      $network = array_merge($network, $second);
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

?>