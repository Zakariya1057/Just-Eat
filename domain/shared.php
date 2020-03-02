<?php

require_once __DIR__ . '/../services/logger.php';

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

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
        $html    = file_get_contents($file);
        $crawler = new Crawler($html);

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
        $hours          = sanitize($restaurant->hours);
        $cuisines       = sanitize($restaurant->cuisines);
        $online_id      = sanitize($restaurant->online_id);
        $hygiene_rating = sanitize($restaurant->hygiene_rating);
        
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
        
        $database->database_query("INSERT into restaurant(name,opening_hours,cuisines,user_id,online_id,url,hygiene_rating,description,overall_rating,num_ratings) 
        values('$name','$hours','$cuisines','$user_id','$online_id','$url',$hygiene_rating,'$description',$rating,$num_ratings)");

        
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
                $database->database_query("delete ignore from category order by id desc limit 1");
                $database->database_query("ALTER TABLE category AUTO_INCREMENT = 1");
            }
            
            
            //Failed to inserting location, no food or categories present
            $database->database_query("DELETE FROM restaurant where id='$restaurant_id'");
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

        $image = $crawler->filter('.badge.ratingkey img[src][alt]')->eq(0);
        if(!$image){
            $logger->debug('No Hygiene Rating');
            return $hygiene_rating;
        }

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

        return $hygiene_rating;

    }
}

?>