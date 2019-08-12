<?php

  include('settings.php');

  $file = fopen("data/TERM.out","r");

  set_time_limit(0);
  error_log("here");

  $i = 1;
  while(! feof($file)) {
    $line = fgets($file);
    $parts = explode('\t', $line);

    //error_log(print_r($parts,true));
    if($parts[2] == 'Y') {
        $display = true;
    }
    else {
        $display = false;
    }

    if($parts[7] == 'P') {
        $preferred = true;
    }
    else {
        $preferred = false;
    }

    $data = [
      'display' => $display,
      'preferred' => $preferred,
      'ulan' => $parts[9],
      'alias' => $parts[10]
    ];

    //error_log(print_r($data,true));

    $sql = "INSERT INTO artist_aliases        (display,
                                              preferred,
                                              ulan,
                                              alias)


                                      VALUES (:display,
                                              :preferred,
                                              :ulan,
                                              :alias)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);

    // $status = $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
    error_log($i);
    
    $i++;
    // if($i > 30){
    //   break;
    // }
    
  }

  fclose($file);

?>