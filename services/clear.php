<?php

require_once __DIR__.'/../services/database.php';

$database = new Database;

$tables = array('sub_category','food','category','location','restaurant');
foreach($tables as $table){
    $database->query("DELETE FROM $table;");
    $database->query("ALTER TABLE $table AUTO_INCREMENT = 1");
}

$date = date('d-m-Y');
//Clear Logs
$logsPath = __DIR__."/../logs/";
$logs = scandir($logsPath);

foreach($logs as $log){

    preg_match('/^\.+$|\.php$/',$log,$matches);

    if(!$matches){
        $file = "$logsPath/$log";
        file_put_contents($file,'');
        echo $file."\n";
    }
}
?>