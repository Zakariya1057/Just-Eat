<?php

    require_once __DIR__ . '/../data/data.php';
    require_once  __DIR__ .'/logger.php';

    function new_directory($directory){
        global $logger;

        if (!file_exists($directory)) {
            $logger->debug('Creating New Directory: '.$directory);
            mkdir($directory, 0777, true) or die("Failed To Create Directory: $directory\n");
        }
    }

    function create_directories($config,$city){
        global $logger;

        $site = $config->site;
        
        $logger->debug("Creating Directories For $city");

        $site_directory = __DIR__ . "/../resources/$site";
        new_directory($site_directory);

        $city_directory = "$site_directory/$city";
        new_directory($city_directory);

        $postcode_directory = $city_directory . "/postcodes";
        new_directory($postcode_directory);

        $restaurant_directory = $city_directory . "/restaurants";
        new_directory($restaurant_directory);

        $logo_directory = $city_directory . "/logos";
        new_directory($logo_directory);

        $directories = new data();
        
        // $directories->logs = $logs_directory;
        $directories->restaurants = "$city_directory/restaurants";
        $directories->logos = "$city_directory/logos";
        $directories->postcodes = "$city_directory/postcodes";
        
        $config->directories = $directories;
        $config->list_file =  __DIR__ . "/../list/$city.json";

    }
?>

