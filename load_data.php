<?php

  include('settings.php');

  $file = fopen("data/SCOPE_NOTES.out","r");

  set_time_limit(0);
  error_log("here");

  $i = 1;
  while(! feof($file)) {
    $line = fgets($file);
    $parts = explode('\t', $line);

    error_log(print_r($parts,true));
    // if($parts[7] == 'P') {
    //     $preferred = true;
    // }
    // else {
    //     $preferred = false;
    // }

    $data = [
      'ulan' => $parts[1],
      'note' => $parts[3],
    ];

    //error_log(print_r($data, true));

    $sql = "INSERT INTO artist_notes        (ulan,
                                              note) 
                                      VALUES (:ulan,
                                              :note)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);

    // $status = $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
    error_log($i);
    // error_log($rel_id);
    // error_log($rel_name);
    // error_log($recip);
    $i++;
    // if($i > 30){
    //   break;
    // }
    
  }

  fclose($file);

?>