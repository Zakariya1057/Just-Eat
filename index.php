<?php

include_once __DIR__.'/domain/justeat.php';
include_once __DIR__.'/logs/logger.php';
include_once __DIR__.'/email.php';

global $logger;


try {
    
    $justeat = new justEat();
    $city = $justeat->city;

    $list = array();

    $list_location = __DIR__."/list/$city.json";
    if(file_exists($list_location)){
        $logger->debug("$city List Found");
        $list = json_decode(file_get_contents($list_location));
    }
    else {
        $logger->debug("$city.json");
        die("Not Found");
    }

    $new_restaurants = array();

    if(!$list){
        $num_left = 0;
    }
    else {
        $num_left = count($list);
    }


    if($num_left > 0){
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

        // if(!$justeat->exists($restaurant)){
            $logger->debug("----------------------------------------------------");
            $justeat->restaurant($restaurant);
            array_shift($new_restaurants);
    
            $logger->notice('Complete');
            file_put_contents($list_location,json_encode($new_restaurants));

        // }

    }


}
catch (Exception $e) {
    $message = $e->getMessage();
    $logger->critical("Script Failure: $message");
    $email = new email;
    $email->send("$message");
}


?>