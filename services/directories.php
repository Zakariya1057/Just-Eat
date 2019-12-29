<?php

    require_once __DIR__.'/../config/config.php';
    require_once __DIR__ . '/../data/data.php';

    global $config;

    $city = $config->city;

    $city_directory = __DIR__ . "/../resources/$city";
    if (!file_exists($city_directory)) {
        mkdir($city_directory) or die("Failed To Create Directory: $city_directory");
    }

    if (!file_exists($city_directory . "/postcodes")) {
        mkdir($city_directory . "/postcodes") or die("Failed To Create Directory: $city_directory/postcodes");
    }

    if (!file_exists($city_directory . "/restaurants")) {
        mkdir($city_directory . "/restaurants") or die("Failed To Create Directory: $city_directory/restaurants");
    }

    if (!file_exists($city_directory . "/logos")) {
        mkdir($city_directory . "/logos") or die("Failed To Create Directory: $city_directory/logos");
    }

    $city_log = __DIR__."/../logs/$city";
    if(!file_exists($city_log)){
        mkdir($city_log);
    }

    $date = date('d-m-Y');

    $logs_directory = "$city_log/$date";
    if(!file_exists($logs_directory)){
        mkdir($logs_directory);
    }

    $directories = new data();
    
    $directories->logs = $logs_directory;
    $directories->restaurants = "$city_directory/restaurants";
    $directories->logos = "$city_directory/logos";
    $directories->postcodes = "$city_directory/postcodes";

    $config->directories = $directories;
    $config->list_file =  __DIR__ . "/../list/$city.json";

?>