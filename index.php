<?php

include_once __DIR__.'/domain/justeat.php';
include_once __DIR__.'/services/logger.php';
include_once __DIR__.'/services/email.php';
include_once __DIR__.'/services/flowdock.php';
include_once __DIR__.'/config/config.php';

global $logger;


try {

    $logger->debug('--------------------------SCRIPT START-------------------------------');
    // print_r($config->site);
    if($config->development){
        $logger->debug('Running In Development');
    }
    else {
        $logger->debug('Running In Live');
    }

    $city_directory = __DIR__."/resources/$city";
    if (!file_exists($city_directory)) {
        mkdir($city_directory) or die("Failed To Create Directory: $city_directory");
    }

    if (!file_exists($city_directory."/postcodes")) {
        mkdir($city_directory."/postcodes") or die("Failed To Create Directory: $city_directory/postcodes");
    }

    if (!file_exists($city_directory."/restaurants")) {
        mkdir($city_directory."/restaurants") or die("Failed To Create Directory: $city_directory/restaurants");
    }

    if (!file_exists($city_directory."/logos")) {
        mkdir($city_directory."/logos") or die("Failed To Create Directory: $city_directory/logos");;
    }

    $current_restaurant;

    // $site = new justEat($config);
    // $restaurant = $site->page_info(__DIR__."/dev/restaurants/restaurants-caspian-grill-and-pizza-birmingham.html");
    // print_r($restaurant);

    // $site->restaurant(__DIR__."/dev/restaurants/restaurants-caspian-grill-and-pizza-birmingham.html");

    if($config->site == 'justeat'){

        $site = new justEat($config);
        $city = $config->city;
        $target_url = $config->justeat->postcode_url.$city;

        $logger->debug("Target City: $city");
        // // $city = $justeat->city;
    
        $list = array();
    
        $postcode_list = __DIR__."/list/$city.json";

        $error_list = __DIR__."/failed/failed.json";
        if(file_exists($error_list)){
            $error_restaurant = json_decode( file_get_contents($error_list) );

            if(count($error_list) != 0){
                $logger->debug('Failed Restaurant Found');
                $site->error(  $error_restaurant[0] );
            }

            file_put_contents($error_list,'[]');
        }
        
        $search_new = true;
        if(file_exists($postcode_list)){
            $logger->debug("$city List Found");
            $new_restaurants = json_decode(file_get_contents($postcode_list));

            if(count($new_restaurants) != 0){
                $search_new = false;
            }
            else {
                $logger->debug("$city List Empty");
            }
        }

        if($search_new){
            $logger->debug("Creating New List $city.json");

            $postcodes = $site->postcodes($target_url,$city);
        //    $postcodes = array(
        //        'WS9' => [
        //            'url' => 'https://www.just-eat.co.uk/area/ws9-aldridge',
        //            'file'  => 'D:\Ampps\www\justeat\resources\Birmingham\postcodes\WS9.html'
        //        ]
        //    );

            $new_restaurants = $site->restaurants($postcodes);

            $logger->debug('Generating New Restaurant List File');
            
            $site->new($new_restaurants);
        }
        
        $new_restaurants_count = count($new_restaurants);

        if($new_restaurants_count > 0){
            $logger->debug("Found $new_restaurants_count New Restaurants To Scrape");

            foreach($new_restaurants as $restaurant){

                $current_restaurant = $restaurant;
                $site->restaurant($restaurant);
                array_shift($new_restaurants);
    
                file_put_contents($postcode_list,json_encode($new_restaurants));

                sleep($config->waiting_time->restaurant);

            }

            $logger->debug('Successfully Completed Scraping Of All Restaurants');

        }
        else {
            $logger->debug("No New Restaurants Found");
        }

        
    }

}
catch (Exception $e) {
    $message = $e->getMessage();
    
    if(!isset($current_restaurant)){ 
    	$current_restaurant = array();
    }

    send_message("Script Error: $message", (array)$current_restaurant);
    file_put_contents(__DIR__.'/failed/failed.json',json_encode( [$current_restaurant] ));
    $logger->critical("Script Failure: $message",(array)$current_restaurant);
    send_email("ERROR: $message");
}

$logger->debug('--------------------------SCRIPT END-------------------------------');

?>
