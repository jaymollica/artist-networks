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
  elseif(isset($_GET['bacon'])) {

    $b = array(
      500115393, //duchamp
      //500004441, // demuth (connects duchamp and stieg)
      500024301, //stieg
      //500018666, //okeefe
    );

    // 500015030 // man ray

    $ulan1 = $b[0];
    $ulan2 = $b[1];

    $bacon = $nw->baconator($ulan1, $ulan2);

    array_unshift($bacon, (int)$ulan1);

    $bacon = array_filter($bacon);

    $b = $nw->prepareBacon($bacon, $ulan1);

    echo json_encode($bacon);

  }

?>