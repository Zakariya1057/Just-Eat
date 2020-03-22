<?php

require_once __DIR__ . '/../services/logger.php';

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;
use \Symfony\Component\HttpClient\HttpClient;

class Shared {

    function __construct($config,$database){
        $this->config      = $config;
        $this->database    = $database;
    }

    public function request_page($url){
        $client  = new Client();
        $crawler = $client->request('GET', $url);
        $html    = $crawler->html();
        return $html;
    }

    public function crawl_page($file){
        global $logger;

        $html    = file_get_contents($file);

        try {
            $crawler = new Crawler($html);
        }
        catch (Exception $e) {
            $message = $e->getMessage();
            $crawler = new Crawler($html);
            $logger->error($message);
        }

        return $crawler;
    }

    public function download_page($url,$file){

        global $logger;

        $logger->debug('Download Page: '.$url);
        
        $html = $this->request_page($url);

        $logger->debug('Download Complete');
        // if(is_nan(stripos($html,'<script src="/_Incapsula_Resource?'))){
        //     $logger->error('Captcha Page Found. Trying Again');
        // }
        $logger->debug('Saving File...');

        file_put_contents($file, $html);

        $logger->debug("Saved Page At $file");

        return $file;

    }

    public function duplicate_restaurant($url){

        global $logger;
        
        $database = $this->database;
        $url = sanitize($url);
        $result   = $database->database_query("SELECT * from restaurant where url='$url'");
        // $logger->debug("SELECT * from restaurant where url='$url'");

        $duplicate = null;

        if($result->num_rows){
            $row = $result->fetch_assoc();
            $name = $row['name'];
            $duplicate = true;
            $logger->debug("Restaurant $name Exists In Database $url");
        }
        else {
            $logger->debug("New Restaurant $url");
        }

        return $duplicate;
        
    }

    public function insert_menu($restaurant)
    {
        
        global $database, $logger;
        $database      = $this->database;
        $connection    = $database->connection;

        $categories = $restaurant->menu;
        $restaurant_id = $this->restaurant_id;
        
        foreach ($categories as $category) {
            $catName        = sanitize($category->name);
            $catDescription = sanitize($category->description);
            
            $database->database_query("INSERT INTO category (name, description) VALUES ('$catName', '$catDescription')");
            $catId = $connection->insert_id;
            
            $logger->debug("Inserting Category $catName");
            //Insert into db and category_id and use to insert food
            foreach ($category->foods as $food) {
                $foodName        = sanitize($food->name);
                $foodDescription = sanitize($food->description);
                $foodPrice = $food->price;

                if(!$foodPrice && sizeof($food->options) == 0){
                    throw new Exception('No Price Given For Food: '.$foodName);
                }
                
                $database->database_query("INSERT INTO food (name, description,price,num_ratings,overall_rating,restaurant_id,category_id) 
            VALUES ('$foodName','$foodDescription','$foodPrice','0',null,'$restaurant_id','$catId')");
                
                $foodId = $connection->insert_id;
                
                if (sizeof($food->options) > 0) {
                    
                    foreach ($food->options as $option) {
                        $optionName  = sanitize($option->name);
                        $optionPrice = sanitize($option->price);
                        
                        $database->database_query("INSERT into sub_category(name,price,food_id) values('$optionName','$optionPrice','$foodId')");
                        
                    }
                    
                }
                
            }
        }
        
    }


    public function insert_restaurant($restaurant){

        global $database, $logger, $config;
        $database = $this->database;

        $location = $restaurant->location;

        $user_id        = $config->user_id;
        $name           = sanitize($restaurant->name);
        $hours          = $restaurant->hours;
        $cuisines       = sanitize($restaurant->cuisines);
        $online_id      = sanitize($restaurant->online_id);
        $hygiene_rating = sanitize($restaurant->hygiene_rating);
        $site = sanitize($restaurant->site);
        
        $address1 = sanitize($location->address1);
        $address2 = sanitize($location->address2);
        $address3 = sanitize($location->address3);
        $postcode = sanitize($location->postcode);
        $county   = sanitize($location->county);
        $country  = sanitize($location->country);
        $city     = sanitize($location->city);

        $description = sanitize($restaurant->description);

        $url      = sanitize($restaurant->url);
        
        $longitude = $location->longitude;
        $latitude  = $location->latitude;

        $rating = $restaurant->overall_rating;
        $num_ratings = $restaurant->num_ratings;
        $logo = $restaurant->logo;
        
        // $logo = "https://d30v2pzvrfyzpo.cloudfront.net/uk/images/restaurants/$online_id.gif";
        
        $logger->debug('Hygiene Rating: ' . $hygiene_rating);
        
        $database->database_query("INSERT into restaurant(name,opening_hours,cuisines,user_id,online_id,url,hygiene_rating,description,overall_rating,num_ratings,site) 
        values('$name','$hours','$cuisines','$user_id','$online_id','$url',$hygiene_rating,'$description',$rating,$num_ratings,'$site')");

        
        $restaurant_id = $database->connection->insert_id;
        
        if($logo != 'NULL'){
            save_image(base64_encode(file_get_contents($logo)), 'logo', "$restaurant_id.gif");
            $logger->debug("Uploading logo/$restaurant_id.gif to S3 Bucket");
        }
        else {
            $logger->debug('No Restaurant Logo Found. Deliveroo');
        }


        
        $logger->debug("Successfully Inserted New Restaurant, $name", array(
            'id' => $restaurant_id,
            'url' => $url
        ));
        
        $database->database_query("insert into location (address_line1,address_line2,address_line3,postcode,city,county,country,restaurant_id,longitude,latitude) 
    values ('$address1','$address2','$address3','$postcode','$city','$county','$country','$restaurant_id',$longitude,$latitude)");
        
        $logger->debug("Successfully Inserted Restaurant Location");

        // return $restaurant_id;
        $this->restaurant_id = $restaurant_id;
    }

    //Save current json re
    public function save_current_restaurant($restaurant){
        $json = json_encode($restaurant);
        $location = $this->config->directories->debug;
        file_put_contents("$location/parsed_restaurant.json",$json);
    }

    public function restaurant($restaurant){
        $this->save_current_restaurant($restaurant);
        $this->insert_restaurant($restaurant);
        $this->insert_menu($restaurant);

    }

    public function delete_restaurant($url)
    {
        
        global $logger;
        
        $database = $this->database;
        $result   = $database->database_query("SELECT * from restaurant where url='$url'");
        
        if ($result->num_rows) {
            $logger->notice('Restaurant Found In Database, Deleting It', array(
                'url' => $url
            ));
            
            $row           = $result->fetch_assoc();
            $restaurant_id = $row['id'];
            
            $database->database_query("DELETE FROM location where restaurant_id='$restaurant_id'");
            $database->database_query("ALTER TABLE location AUTO_INCREMENT = 1");
            
            $subresults = $database->database_query("SELECT * from food where restaurant_id='$restaurant_id' order by id asc limit 1");
            if ($subresults->num_rows) {
                $subrow      = $subresults->fetch_assoc();
                $food_id     = $subrow['id'];
                $category_id = $subrow['category_id'];
                
                $database->database_query("DELETE from sub_category where food_id >= '$food_id'");
                $database->database_query("ALTER TABLE sub_category AUTO_INCREMENT = 1");
                
                $database->database_query("DELETE from food where restaurant_id='$restaurant_id'");
                $database->database_query("ALTER TABLE food AUTO_INCREMENT = 1");
                
                $database->database_query("DELETE from category where id >= '$category_id'");
                $database->database_query("ALTER TABLE category AUTO_INCREMENT = 1");
                
            } else {
                //Failed on category,Delete last one
                $database->database_query("DELETE ignore FROM category ORDER by id desc limit 1");
                $database->database_query("ALTER TABLE category AUTO_INCREMENT = 1");
            }
            
            
            //Failed to inserting location, no food or categories present
            $database->database_query("DELETE FROM restaurant where id='$restaurant_id'");
            $database->database_query("DELETE ignore FROM opening_hour where restaurant_id='$restaurant_id'");
            $database->database_query("ALTER TABLE restaurant AUTO_INCREMENT = 1");
            
        }
        
    }

    public function format_postcode($postcode){
        preg_match('/\b((?:(?:gir)|(?:[a-pr-uwyz])(?:(?:[0-9](?:[a-hjkpstuw]|[0-9])?)|(?:[a-hk-y][0-9](?:[0-9]|[abehmnprv-y])?)))) ?([0-9][abd-hjlnp-uw-z]{2})\b/i',$postcode,$matches);

        if($matches && sizeof($matches) > 2){
            // return $matches;
            return $matches[1] . ' ' . $matches[2];
        }
        else {
            throw new Exception('Invalid PostCode Found: '.$postcode);
        }
    }

    public function cross_search($restaurant){
        global $logger;

        if(!property_exists($restaurant,'location')){
            throw new Exception('No Restaurant Location Found.');
        }

        $location = $restaurant->location;

        $name = sanitize($restaurant->name);
        $address1 = sanitize($location->address1);
        $postcode = str_replace(' ','',$location->postcode);
        
        $restaurant_found = false;
        $filter_results = false;

        preg_match('/(\w+)/',$name,$matches);
        if(!$matches){
            throw new Exception('No Restaurant Name Found: '. $name);
        }

        preg_match('/\w*\s*([^,\d]+)\s*,*/',$address1,$address_matches);
        if(!$address_matches){
            throw new Exception('Restaurant Address Invalid: '. $address1);
        }

        $possible_name = $matches[1];
        $short_address = $address_matches[1];

        $results = $this->database->database_query("SELECT * FROM restaurant inner join location on location.restaurant_id = restaurant.id where ( name like '%$name%' or name like '$possible_name%' ) and ( location.address_line1='$address1' or REPLACE(location.postcode,' ','') ='$postcode' )");

        if($results->num_rows){
        
            if($results->num_rows > 1){
                // throw new Exception('Too Many Restaurants Match Description');
                $logger->warning('Too Many Restaurants Match Description');
                $filter_results = true;
            }
            else {
                $restaurant_found = true;
            }

        }
        else {
            $logger->warning('No Restaurant Matched. Trying Postcode Search With Similar Names');
            $results = $this->database->database_query("SELECT * FROM restaurant inner join location on location.restaurant_id = restaurant.id where ( (name like '$possible_name%' and location.address_line1 like '%$short_address%' and REPLACE(location.postcode,' ','') ='$postcode' ) or ( location.address_line1='$address1' or REPLACE(location.postcode,' ','') ='$postcode' ) )");

            $filter_results = true;
        }

        if($filter_results){

            $logger->debug($results->num_rows . ' Possible Matches');

            $logger->debug('Finding '.$name);

            for($i = 0;$i < $results->num_rows;$i++){
                $row = $results->fetch_assoc();
                $result_name = $row['name'];

                similar_text($row['name'], $name, $similarity);
                similar_text($row['address_line1'], $address1, $address_similarity);

                preg_match('/^(\d+)/',$row['address_line1'],$street_number1);
                preg_match('/^(\d+)/',$address1,$street_number2);

                if(!$street_number1 || !$street_number2){
                    // throw new Exception('Street Number Missing: '. $row['address_line1'] . ' | '.$address1);
                    $logger->error('Street Number Missing: '. $row['address_line1'] . ' | '.$address1);
                }

                $logger->debug("--- ".$row['name'] ."  ===  $name ---");

                preg_match("/$name/i",$result_name, $name_match1);

                preg_match("/".$row['address_line1']."/i",$address1, $address_search1);
                preg_match("/$address1/i",$row['address_line1'], $address_search2);

                $logger->debug('Content: '.$row['address_line1']."\t Search $address1");
                $logger->debug('Content: '.$address1."\t Search: ".$row['address_line1']);

                if( ( $similarity > 60 || $name_match1 ) || ( ($street_number1 && $street_number2) && $address_similarity > 60 && ( $street_number1[1] == $street_number2[1]) ) || ( ($address_search1 || $address_search2) && str_replace(' ','',$row['postcode']) == str_replace(' ','',$postcode) )){

                    $logger->debug('Single Restaurant Match Found In Database. '.$row['name'] .' == '. $name);
                    $restaurant_found = true;
                    break;
                }

            }
        }


        if($restaurant_found){
            $logger->debug("$name Exist");
        }
        else {
            $logger->debug("$name New");
        }
        
        return !$restaurant_found;

    }

    public function parse_name($name,$city){

        $name = trim( preg_replace("/\s?$city\s?/i",' ',$name));
    
        preg_match('/(^.+?)\s?\(.+\)/',$name,$bracket_matches); #name like '%(%'
        if($bracket_matches){
            $name = $bracket_matches[1];
        }
    
        preg_match('/(.+?)\s-\s?[A-Z]?/',$name,$dash_matches); #name like '%-%'
        if($dash_matches){
            $name = $dash_matches[1];
        }
    
        return trim($name);
    }

    public function restaurant_hygiene($hygiene_url){
        global $logger;

        $hygiene_rating = 'NULL';

        preg_match('/https:\/\/ratings\.food\.gov\.uk\/business\/en-GB\/(\d+)/',$hygiene_url,$matches);
        
        $logger->debug('Fetching Hygiene Rating');

        if(!$matches){
            // throw new Exception('Invalid Hygiene Site URL: '.$hygiene_url);
            $logger->debug('No Hygiene Rating Found');
            return $hygiene_rating;
        }
        else {
            $hygiene_id = $matches[1];
        }

        $location = $this->config->directories->hygiene."/$hygiene_id.html";

        $file = $this->download_page($hygiene_url,$location);
        $crawler = $this->crawl_page($file);

        $image = null;

        try {
            
            $image = $crawler->filter('.badge.ratingkey img[src][alt]')->eq(0);
            $image_description = $image->attr('alt');

            preg_match('/\'(\d)\':/',$image_description,$matches);

            if(!$matches){
    
                if(strtolower($image_description) == 'awaiting inspection'){
                    $logger->debug('Awaiting Inspection');
                    return $hygiene_rating;
                }
    
                throw new Exception('Invalid Image Description: '.$image_description);
            }
            else {
                $hygiene_rating = $matches[1];
                $logger->debug("Hygiene Rating Scraped: $hygiene_rating");
            }

        }
        catch (Exception $e) {
            $logger->error('Hygiene Rating Scrape Error: '.$e->getMessage());
        }

        if(!$hygiene_rating){
            $logger->debug('No Hygiene Rating');
        }

        return $hygiene_rating;

    }

    public function generate_list($new_restaurants){

        global $logger;
        $city = $this->config->city;
        
        $logger->debug("Generating $city List Found");

        $new_restaurants = array_unique($new_restaurants);

        $count = count($new_restaurants);
        
        $destination = $this->config->directories->list;
        $list = json_encode($new_restaurants);
        file_put_contents("$destination/$city.json", $list);

    }

    public function places($restaurant){

        global $config,$logger;
        
        $client = HttpClient::create();

        $name = $restaurant->name;
        $location = $restaurant->location;

        $address  = $location->address1;
        $postcode = $location->postcode;
        $city     = $location->city;

        $api_key = $config->google->api_key;

        //Get Place Id and use that to get details
        $format_address = str_replace(' ','+',"$address,$postcode,$city,$name");
        
        $search_url = "https://maps.googleapis.com/maps/api/place/findplacefromtext/json?input=$format_address&inputtype=textquery&key=".$api_key;
        $logger->debug('Search URL: '.$search_url);

        $place_id_response = $client->request('GET', $search_url);
        $statusCode = $place_id_response->getStatusCode();

        $content = json_decode($place_id_response->getContent());

        if (strtolower($content->status) == 'ok') {

            $place_id = $content->candidates[0]->place_id;

            $logger->debug("Place Found For $name($address)");

        }
        else {
            $logger->error('Place Not Found For '.$name);
            return false;
        }

        $place_url = "https://maps.googleapis.com/maps/api/place/details/json?place_id=$place_id&fields=name,rating,formatted_phone_number,opening_hours,geometry&key=".$api_key;

        $logger->debug($place_url);

        $response = $client->request('GET', $place_url );

        $content = json_decode($response->getContent());

        if (strtolower($content->status) == 'ok') {

            if($content->result){
                
                $results = $content->result;

                $geometry = $results->geometry;
                if($geometry){

                    $geometry  = $geometry->location;
                    $longitude = $geometry->lng;
                    $latitude  = $geometry->lat;
                    
                    $location->longitude = $longitude;
                    $location->latitude = $latitude;

                }
                else {
                    $logger->error("Geolocation Not Found For Place: ".$name);
                }


                if(property_exists($results,'opening_hours')){
                    
                    $opening_hours = $results->opening_hours;

                    if(count($opening_hours->weekday_text) == 0){
                        $logger->error("Opening Hours, Weekdays Info Empty: ".$name);
                    }
                    else {

                        $hours = array();

                        foreach($opening_hours->weekday_text as $weekday){

                            preg_match('/closed/i',$weekday,$closed_match);
                            preg_match('/Open 24 hours/i',$weekday,$always_open_match);

                            $open_hours = new data();
                            
                            if($always_open_match){
                                preg_match('/^(\w+)\:/',$weekday,$name_match);

                                if(!$name_match){
                                    throw new Exception("$name: No WeekDay Found. Format Not Recognised: $weekday");
                                }

                                $open_hours->day = $name_match[1];
                                $open_hours->open = "00:00";
                                $open_hours->close = "00:01";
                            }
                            elseif($closed_match){
                                preg_match('/^(\w+)\:/',$weekday,$name_match);

                                if(!$name_match){
                                    throw new Exception("$name: No WeekDay Found. Format Not Recognised: $weekday");
                                }

                                $open_hours->day = $name_match[1];
                                $open_hours->open = "";
                                $open_hours->close = "";
                            }
                            else {

                                preg_match('/^(\w+)\W+(\d+:\d+ \w*)\W+(\d+:\d+ \w+)/',$weekday,$matches);
                            
                                if(!$matches){
                                    // $logger->error('Opening Hours, Weekdays Format Not Recognised: '.$name); 
                                    throw new Exception("$name: Opening Hours, Weekdays Format Not Recognised: $weekday");
                                }
    
                                preg_match('/am|pm/i',$matches[2],$format_match1);
                                preg_match('/am|pm/i',$matches[3],$format_match2);
    
                                if(!$format_match1){
                                    $logger->error("$name: Opening Hours, No AM/PM Set: ".$matches[2]);
                                    $matches[2] = trim($matches[2]) . ' PM';
                                }
    
                                if(!$format_match2){
                                    $logger->error("$name: Opening Hours, No AM/PM Set: ".$matches[3]);
                                    $matches[3] = trim($matches[3]) . ' PM';
                                }
    
                                $day = $matches[1];
                                $open = date("H:i", strtotime($matches[2]));
                                $close = date("H:i", strtotime($matches[3]));
    
                                
                                $open_hours->day = $day;
                                $open_hours->open = $open;
                                $open_hours->close = $close;
                                
                            }


                            $hours[] = $open_hours;
                        }
                        
                        $restaurant->hours = json_encode($hours);
                    }


                }
                else {
                    $logger->error("Opening Hours Not Found For Place: ".$name);
                }

                if(property_exists($results,'formatted_phone_number')){
                    $restaurant->phone_number = $results->formatted_phone_number;
                }

            }
            else {
                $logger->error("Results Missing: ".$name);
            }

        }
        else {
            $logger->error("Failed To Find Details Of Place Using Place_ID($place_id): ".$name);
            return false;
        }

        return true;
    }

}

?>