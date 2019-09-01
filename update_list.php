<?php

include_once __DIR__.'/domain/justeat.php';
include_once __DIR__.'/logs/logger.php';
include_once __DIR__.'/email.php';

global $logger;


try {
    
    $justeat = new justEat();

    $postcodesFiles = __DIR__."/resources/postcodes/";

    $postcodes = scandir($postcodesFiles);

    $fullPaths = array();

    foreach($postcodes as $postcode){

        preg_match('/^\.+$/', $postcode, $matches);

        if (!$matches) {
            $fullPaths[] = $postcodesFiles.$postcode;
        }
        
    }

    $new_restaurants =  $justeat->restaurants($fullPaths);

    print_r($new_restaurants);

    $justeat->new($new_restaurants);

}
catch (Exception $e) {
    $message = $e->getMessage();
    // $logger->error("Script Error: $message");
    $email = new email;
    $email->send('$message');
}


?>