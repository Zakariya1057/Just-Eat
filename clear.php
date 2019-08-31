<?php

require_once  __DIR__.'/database/database.php';

$database = new Database;

$tables = array('sub_category','food','category','location','restaurant');
foreach($tables as $table){
    $database->query("DELETE FROM $table;");
    $database->query("ALTER TABLE $table AUTO_INCREMENT = 1");
}

$date = date('Y-m-d');
//Clear Logs
$logsPath = __DIR__."/logs/$date";
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