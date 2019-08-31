<?php

include_once 'domain/justeat.php';
include_once 'logs/logger.php';

global $logger;

try {

    $justeat = new justEat();
    $city = $justeat->city;

    $list = array();

    $list_location = "list/$city.json";
    if(file_exists($list_location)){
        $logger->debug("$city List Found");
        $list = json_decode(file_get_contents("list/$city.json"));
    }
    else {
        $logger->debug("$city.json");
        die("Not Found");
    }

    $new_restaurants = array();
    $num_left = count($list);

    if($num_left > 0){
        // $new_restaurants[] = $list;
        $new_restaurants = $list;
        $logger->notice("$city List Empty");
    }
    else {

        $logger->notice("$city List Not Empty,$num_left Left");

        $postcodes = $justeat->postcodes();
        $logger->debug('Postcodes Generated');

        /////////////////////////////////////////////////////////////////
        // $search = 'resources/postcodes';
        // $postcodes = scandir($search);
        /////////////////////////////////////////////////////////////////

        $new_restaurants =  $justeat->restaurants($postcodes);

        $logger->debug('New Restaurants Fetched');
        $justeat->new($new_restaurants);
        $logger->debug('New List Generated');

    }

    $logger->debug('Going Through New Restaurants');

    foreach($new_restaurants as $restaurant){

        $justeat->restaurant($restaurant);
        array_shift($new_restaurants);

        print_r($new_restaurants);

        $logger->notice('Complete',array('restaurant' => $restaurant));
        file_put_contents($list_location,json_encode($new_restaurants));
        
    }


}
catch (Exception $e) {
    $message = $e->getMessage();
    $logger->error('Script Error: $message');

}


?>