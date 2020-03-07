<?php

    include_once __DIR__ . '/config/config.php';

    include_once __DIR__ . '/services/directories.php';
    include_once __DIR__ . '/services/logger.php';
    include_once __DIR__ . '/services/archive.php';
    include_once __DIR__ . '/services/email.php';
    include_once __DIR__ . '/services/flowdock.php';
    require_once __DIR__ . '/services/database.php';
    require_once __DIR__ . '/services/database.php';

    include_once __DIR__ . '/domain/justeat.php';
    include_once __DIR__ . '/domain/deliveroo.php';


    global $logger,$config;

    // error_reporting(E_STRICT);

    try {

        $logger->debug('--------------------------SCRIPT START-------------------------------');

        $current_restaurant;
        $database = new Database();

        $shared = new Shared($config,$database);

        $target_site = $config->site;
        
        $logger->debug('Site: '.$target_site);

        $error_list = __DIR__ . "/failed/failed.json";
        if (file_exists($error_list)) {
            $error_restaurant = json_decode(file_get_contents($error_list));

            if (count($error_restaurant) != 0) {
                $logger->debug('Failed Restaurant Found');

                foreach($error_restaurant as $failed_restaurant){

                    $shared->delete_restaurant($failed_restaurant);
                    $logger->debug('Failed Restaurant Deleted: '.$failed_restaurant);

                }

            }

            file_put_contents($error_list, '[]');
        }

        if (strtolower($target_site) == 'justeat') {

            $justeat    = new justEat($config,$database);

            foreach(array_keys((array)$config->locations) as $city){
                $county = ucwords(strtolower($config->locations->$city));
                $city = ucwords(strtolower($city));

                $config->city = $city;
                $config->county = $county;

                $logger->notice("\n--------- $city ($county) Start ---------\n");

                create_directories($config,$city);


                $target_url = $config->justeat->postcode_url . $city;

                $list = array();

                // $postcode_list = __DIR__ . "/list/$city.json";
                $postcode_list = $config->directories->list;

                if($config->fetch_new){

                    $logger->debug('Creating New Restaurants');

                    $search_new = true;

                    if (file_exists($postcode_list)) {
                        $logger->debug("$city List Found");
                        $new_restaurants = json_decode(file_get_contents($postcode_list));

                        if (count($new_restaurants) != 0) {
                            $search_new = false;
                        } else {
                            $logger->debug("$city List Empty");
                        }
                    }

                    if ($search_new) {
                        $logger->debug("Creating New List $city.json");

                        $postcodes = $justeat->postcodes($target_url,$city);
                        // $postcodes = array(
                        //     'WS9' => array(
                        //         'url' => 'https://www.just-eat.co.uk/area/ws9-aldridge',
                        //         'file' => 'D:\Ampps\www\justeat\resources\Birmingham\postcodes\WS9.html'
                        //     )
                        // );

                        $new_restaurants = $justeat->restaurants($postcodes);

                        $logger->debug('Generating New Restaurant List File');

                        $justeat->new_restaurants($new_restaurants);
                    }

                    $new_restaurants_count = count($new_restaurants);

                    if ($new_restaurants_count > 0) {
                        $logger->debug("Found $new_restaurants_count New Restaurants To Scrape");

                        foreach ($new_restaurants as $restaurant) {

                            $current_restaurant = $restaurant;
                            $justeat->restaurant($restaurant);
                            array_shift($new_restaurants);

                            file_put_contents($postcode_list, json_encode($new_restaurants));

                            sleep($config->waiting_time->restaurant);

                        }

                        $logger->debug('Restaurant List Completed');

                    } else {
                        $logger->debug("No New Restaurants Found");
                    }

                }

                if($config->update_old){
                    $logger->debug('Updating Old Restaurants');
                    $justeat->update_restaurants();
                }

                $logger->notice("\n--------- $city ($county) Completed ---------\n");
            }

        }
        elseif(strtolower($target_site) == 'deliveroo') {

            $deliveroo   = new Deliveroo($config,$database,$shared);

            $logger->notice('Deliveroo Scraping Start');

            foreach(array_keys((array)$config->locations) as $city){
                $county = ucwords(strtolower($config->locations->$city));
                $city = ucwords(strtolower($city));

                $config->city = $city;
                $config->county = $county;

                $logger->notice("\n--------- $city ($county) Start ---------\n");

                create_directories($config,$city);

                $list_destination = $config->directories->list;
                
                $search_new = true;
                $list_file = "$list_destination/$city.json";

                if(file_exists($list_file)){

                    $logger->debug("$city List Found");
                    $new_restaurants = json_decode(file_get_contents($list_file));

                    $restaurant_count = count($new_restaurants);
                    if ( $restaurant_count != 0) {
                        $search_new = false;
                        $logger->debug("$restaurant_count Restaurants Left");
                    } else {
                        $logger->debug("$city List Empty");
                    }

                }
                
                if($search_new) {
                    $logger->debug("$city List Not Found");
                    $new_restaurants = $deliveroo->search($city);
                    $deliveroo->generate_list($new_restaurants);
                }
                

                foreach($new_restaurants as $url){
                    // echo "$restaurant_name($restaurant_url)\n";
                    $current_restaurant = $url;

                    preg_match('/\/([^\/]+)$/',$url,$matches);
                    if(!$matches){
                        throw new Exception('Invalid Restaurant URL');
                    }
                    else {
                        $name = ucwords(str_replace('-',' ',$matches[1]));
                    }

                    $logger->notice("------------ $name Restaurant Start ------------");

                    $deliveroo->new_restaurant($url);

                    array_shift($new_restaurants);

                    file_put_contents($list_file, json_encode($new_restaurants));

                    $logger->notice("------------ $name Restaurant Complete ------------");
                }

                // print_r($city_list);

                // foreach(array_keys($city_list) as $city){

                //     foreach($city_list[$city] as $area => $restaurants){

                //         foreach($restaurants as $name => $url){
                //             // echo "$restaurant_name($restaurant_url)\n";
                //             $current_restaurant = $url;

                //             preg_match('/\/([^\/]+)$/',$url,$matches);
                //             if(!$matches){
                //                 throw new Exception('Invalid Restaurant URL');
                //             }
                //             else {
                //                 $name = ucwords(str_replace('-',' ',$matches[1]));
                //             }

                //             $logger->notice("------------ $name Restaurant Start ------------");

                //             $deliveroo->new_restaurant($url);

                //             $logger->notice("------------ $name Restaurant Complete ------------");
                //         }

                //     }

                // }


                $logger->notice('Deliveroo Scraping Complete');

                $logger->notice("\n--------------------- $city ($county) Completed ---------------------\n");

            }

        }

        
        // archive_resources(__DIR__."/resources/$target_site",__DIR__.'/archive');
        // archive_resources(__DIR__."/logs/$target_site",__DIR__.'/archive');

    }
    catch (Exception $e) {
        $message = $e->getMessage();

        if (!isset($current_restaurant)) {
            $current_restaurant = array();
        }

        $logger->critical("Script Failure: $message");

        send_message("Script Error: $message", (array) $current_restaurant);

        if($current_restaurant){
            file_put_contents(__DIR__ . '/failed/failed.json', json_encode(array(
                $current_restaurant
            )));
        }

        // print_r($e->getTrace());

        // $logger->critical("Script Failure: $message");
        // $logger->debug("Script Failure: $message", []);
        file_put_contents('error_stack.json',json_encode( $e->getTrace() ));

        send_email("ERROR: $message");
    }

    $logger->debug('--------------------------SCRIPT END-------------------------------');

?>
