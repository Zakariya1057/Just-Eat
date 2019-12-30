<?php

    include_once __DIR__ . '/services/directories.php';
    include_once __DIR__ . '/domain/justeat.php';
    include_once __DIR__ . '/services/logger.php';
    include_once __DIR__ . '/services/email.php';
    include_once __DIR__ . '/services/flowdock.php';
    include_once __DIR__ . '/config/config.php';
    
    global $logger;
    
    
    try {
        
        $logger->debug('--------------------------SCRIPT START-------------------------------');
        
        $current_restaurant;
        
        // $justeat = new justEat($config);
        // $restaurant = $justeat->page_info(__DIR__."/dev/restaurants/restaurants-caspian-grill-and-pizza-birmingham.html");
        // print_r($restaurant);
        
        // $justeat->restaurant(__DIR__."/dev/restaurants/restaurants-caspian-grill-and-pizza-birmingham.html");
        
        $logger->debug('Site: '.$config->site);

        if ($config->site == 'justeat') {
            
            $justeat    = new justEat($config);
            $city       = $config->city;
            $target_url = $config->justeat->postcode_url . $city;
            
            $logger->debug("City: $city");
            // // $city = $justeat->city;
            
            $list = array();
            
            $postcode_list = __DIR__ . "/list/$city.json";
            
            $error_list = __DIR__ . "/failed/failed.json";
            if (file_exists($error_list)) {
                $error_restaurant = json_decode(file_get_contents($error_list));
                
                if (count($error_restaurant) != 0) {
                    $logger->debug('Failed Restaurant Found');
                    $justeat->error($error_restaurant[0]);
                }
                
                file_put_contents($error_list, '[]');
            }
            
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
                    
                    $logger->debug('Successfully Completed Scraping Of All Restaurants');
                    
                } else {
                    $logger->debug("No New Restaurants Found");
                }

            }
            
            if($config->update_old){
                $logger->debug('Updating Old Restaurants');
                $justeat->update_restaurants();
            }
        }
        
    }
    catch (Exception $e) {
        $message = $e->getMessage();
        
        // if (!isset($current_restaurant)) {
        //     $current_restaurant = array();
        // }

        send_message("Script Error: $message", (array) $current_restaurant);

        if($current_restaurant){
            file_put_contents(__DIR__ . '/failed/failed.json', json_encode(array(
                $current_restaurant
            )));
        }
        
        $logger->critical("Script Failure: $message", (array) $current_restaurant);
        
        send_email("ERROR: $message");
    }
    
    $logger->debug('--------------------------SCRIPT END-------------------------------');
    
?>