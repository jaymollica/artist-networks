<?php

  include('settings.php');

  $file = fopen("data/BIOGRAPHY.out","r");

  set_time_limit(0);
  error_log("here");

  $i = 1;
  while(! feof($file)) {
    $line = fgets($file);
    $parts = explode('\t', $line);

    //error_log(print_r($parts,true));
    // if($parts[2] == 'Y') {
    //     $display = true;
    // }
    // else {
    //     $display = false;
    // }

    if($parts[7] == 'P') {
        $preferred = true;
    }
    else {
        $preferred = false;
    }

    $data = [
      'bio' => $parts[1],
      'birth_year' => $parts[2],
      'death_year' => $parts[5],
      'ulan' => $parts[9],
      'preferred' => $preferred
    ];

    //error_log(print_r($data,true));

    $sql = "INSERT INTO biographies        (biography,
                                              birth_year,
                                              death_year,
                                              ulan,
                                              preferred)


                                      VALUES (:bio,
                                              :birth_year,
                                              :death_year,
                                              :ulan,
                                              :preferred)";
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