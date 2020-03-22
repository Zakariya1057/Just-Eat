<?php

require_once __DIR__ . '/services/database.php';

$database = new Database();

$results = $database->database_query("SELECT id,name from restaurant where name like '%-%'");

$i = 0;

if($results->num_rows){
    while($i < $results->num_rows){
        $row  = $results->fetch_assoc();
        $id   = $row['id'];
        $name = $row['name'];
        
        preg_match('/(.+?)\s-\s?[A-Z]?/',$name,$matches);

        if($matches){

            $newname = $matches[1];
            $database->database_query("update restaurant set name='$newname' where id='$id'");
            echo "$name -> $newname \n";

        }

        $i++;
    }
}

?>