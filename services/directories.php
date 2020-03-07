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

        $logs_directory = __DIR__ . "/../logs/$site";
        new_directory($logs_directory);

        $list_directory = __DIR__ . "/../list/$site";
        new_directory($list_directory);

        $site_directory = __DIR__ . "/../resources/$site";
        new_directory($site_directory);

        $city_directory = "$site_directory/$city";
        new_directory($city_directory);

        $postcode_directory = $city_directory . "/postcodes";
        new_directory($postcode_directory);

        $restaurant_directory = $city_directory . "/restaurants";
        new_directory($restaurant_directory);

        $hygiene_directory = $city_directory . "/hygiene";
        new_directory($hygiene_directory);

        $sitemap_directory = __DIR__ . "/../resources/deliveroo/sitemap";
        new_directory($sitemap_directory);

        $debug_directory =  $city_directory . "/debug";
        new_directory($debug_directory);

        $logo_directory = $city_directory . "/logos";
        new_directory($logo_directory);

        $directories = new data();
        
        // $directories->logs = $logs_directory;
        $directories->restaurants = $restaurant_directory;
        $directories->logos = $logo_directory;
        $directories->postcodes =  $postcode_directory;
        $directories->hygiene = $hygiene_directory;
        $directories->sitemap = $sitemap_directory;
        $directories->debug = $debug_directory;
        $directories->list = $list_directory;
        
        $config->directories = $directories;
        $config->list_file =  __DIR__ . "/../list/$city.json";

    }
?>

