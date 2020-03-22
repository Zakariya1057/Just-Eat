<?php
    
    require_once __DIR__ . '/../services/database.php';
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../data/data.php';
    require_once __DIR__ . '/../services/logger.php';
    require_once __DIR__ . '/../services/strings.php';
    require_once __DIR__ . '/../services/places.php';
    require_once __DIR__ . '/../services/save_image.php';
    include_once __DIR__ . '/shared.php';
    
    use Symfony\Component\DomCrawler\Crawler;
    use Goutte\Client;
    use \Symfony\Component\HttpClient\HttpClient;
    
    class justEat extends Shared
    {
        
        public $restaurant_id;
        
        public $connection;
        public $config;
        public $development;
        public $database;
        
        function __construct($config,$database)
        {
            $this->config      = $config;
            $this->database    = $database;
            $this->development = $config->development;
        }
        
        //Get All PostCodes With Restaurants
        public function postcodes($url, $city)
        {
            
            global $logger, $output, $location, $config, $client, $development, $sleeping_time, $retry_waiting_time;
            
            $client = HttpClient::create();
            
            $config = $this->config;
            
            $output = array();
            
            // $location = __DIR__ . "/../resources/$city/postcodes";
            $location = $config->directories->postcodes;
            
            $logger->info('Fetching PostCode List From '.$url);

            $response = $client->request('GET', $url);
            $crawler = new Crawler($response->getContent());
            
            $logger->debug('PostCodes List Page Fetched');

            $development   = $this->development;
            $sleeping_time = $config->waiting_time->postcode;
            $retry_waiting_time = $config->retry->wait;
            
            $postcode_links = $crawler->filter('li.link-item a[href]');

            $logger->info(count($postcode_links)." Postcodes Found");

            $postcode_links->each(function(Crawler $node, $i)
            {
                global $output, $location, $logger, $sleeping_time, $client, $development,$config,$retry_waiting_time;
                
                $postcode_url  = $node->attr('href');
                $postcode_name = sanitize(preg_replace('/.+,/', '', $node->html()));
                
                $postcode_saving_location = $location . "/$postcode_name.html";
                
                $postcode_info = array(
                    'name' => $postcode_name,
                    'url' => $postcode_url
                );
                
                $logger->debug('PostCode: '.$postcode_name, $postcode_info);
                
                if (!$development) {
                    
                    $logger->debug('Downloading '.$postcode_url);

                    $response = $client->request('GET', $postcode_url,['timeout' => 20]);
                    
                    if($response->getStatusCode() != 200){
                        $logger->error('Failed To Fetch Page. Trying Again');

                        for($i =0;$i < $config->retry->count;$i++){
                            $response = $client->request('GET', $postcode_url,['timeout' => 20]);

                            if($response->getStatusCode() != 200){
                                $logger->error('Failed To Fetch Page. Trying Again ...');
                            }
                            else {
                                $logger->error('Successfully Loaded Page');
                            }
                        }
                    }

                    $crawler = new Crawler($response->getContent());

                    $logger->debug('Download Complete');

                    $restaurant_count = count($crawler->filter('section.c-listing-item'));

                    if ($restaurant_count == 0) {
                        $logger->debug("No Restaurants Found. Trying Again Shortly.", $postcode_info);

                        #Wait a little longer and try again
                        sleep($retry_waiting_time);

                        $response = $client->request('GET', $postcode_url,['timeout' => 20]);
                    
                        if($response->getStatusCode() != 200){
                            $logger->error('Failed To Fetch Page. Trying Again');
    
                            for($i =0;$i < $config->retry->count;$i++){
                                $response = $client->request('GET', $postcode_url,['timeout' => 20]);
    
                                if($response->getStatusCode() != 200){
                                    $logger->error('Failed To Fetch Page. Trying Again ...');
                                }
                                else {
                                    $logger->error('Successfully Loaded Page');
                                }
                            }
                        }
    
                        $crawler = new Crawler($response->getContent());

                        $restaurant_count = count($crawler->filter('section.c-listing-item'));

                        if ($restaurant_count == 0) {
                            $logger->debug("Retried. No Postcode Found", $postcode_info);
                        }
                    }
                    
                    $logger->debug("$restaurant_count Restaurants Found");
                    
                    $content = $crawler->html();
                
                    $logger->debug("Saving File At $postcode_name.html");
                    file_put_contents($postcode_saving_location, $content);
                    
                    $output[$postcode_name] = array(
                        'url' => $postcode_url,
                        'file' => $postcode_saving_location
                    );

                    $logger->debug('Sleeping...');
                    sleep($sleeping_time);
                    
                } else {
                    $content = $postcode_url;
                }
                
                
            });
            
            if(count($output) == 0){
                // die('No PostCodes Found');
                throw new Exception("No PostCodes Found At $url");
            }
            
            return $output;
            
        }
        
        public function restaurants($postcodes)
        {
            
            global $database, $output, $logger;
            $database = $this->database;
            $output   = array();
            
            foreach ($postcodes as $postcode => $data) {
                
                $file = $data['file'];

                $logger->info("Crawling Postcode $postcode",$data);
                
                if (file_exists($file)) {
                    
                    if (!is_file($file)) {
                        $logger->debug('Not A Postcode File', $data);
                        return;
                    }
                    
                    $html = file_get_contents($file);
                    
                    $crawler = new Crawler($html);
                    
                    $crawler->filter('section[data-restaurant-id]')->each(function(Crawler $node, $i)
                    {
                        global $database, $output, $logger;
                        
                        $restaurant_name = $node->filter('h3[data-test-id].c-listing-item-title')->eq(0)->text();
                        $online_id = $node->attr('data-restaurant-id');
                        $url       = 'https://www.just-eat.co.uk' . $node->filter('a.c-listing-item-link')->eq(0)->attr('href');

                        $logger->debug("Restaurant Name: $restaurant_name ($url)");

                        preg_match('/halal/i', $node->filter('p[data-cuisine-names]')->eq(0)->attr('data-cuisine-names'), $matches);

                        if (!$matches) {
                            $logger->debug("Restaurant Not Halal Skipping");
                            return;
                        }
                        
                        if (!array_key_exists($url, $output)) {
                            
                            $result = $database->database_query("select * from restaurant where online_id='$online_id'");
                            
                            if ($result->num_rows) {
                                $logger->debug("Exists In Database");
                                //Update or Skip
                            } else {
                                $logger->info("New Halal Restaurant Found");
                                $output[$url] = 1;
                            }
                            
                        }
                        
                        
                    });
                    
                } else {
                    $logger->error("Postcode File Not Found: $file", $data);
                }
                
            }
            
            return array_keys($output);
            
        }
        
        public function menu($file)
        {
            
            global $restaurant, $categories, $logger;
            $restaurant = new data();
            
            $html    = file_get_contents($file);
            $crawler = new Crawler($html);
            
            $categories = array();
            
            $category_count = count($crawler->filter('ul.menuCategoriesLinks li'));

            if(!$category_count){
                $logger->debug("No Food Categories Found");
                $restaurant->empty = 1;
            }
            else {

                $logger->debug("$category_count Food Categories Found");

                $crawler->filter('.category')->each(function(Crawler $node, $i)
                {
                    
                    global $category, $categories, $logger;
                    $category        = new data();
                    $category->foods = array();
                    
                    $node->filter('h3')->each(function(Crawler $node, $i)
                    {
                        global $category, $logger;
                        $category_name  = sanitize($node->html());
                        $category->name = $category_name;
                        // $logger->debug("Category: $category_name");
                    });
                    
                    if ($node->filter('.categoryDescription')->count() !== 0) {
                        
                        $node->filter('.categoryDescription')->each(function(Crawler $node, $i)
                        {
                            global $category;
                            $category->description = sanitize($node->html(), false);
                        });
                        
                    } else {
                        global $category;
                        $category->description = '';
                    }
                    
                    preg_match('/Popular|Recommended|offer| New/i', $category->name, $matches);
                    
                    
                    if (!$matches) {
                        
                        $node->filter('.products')->each(function(Crawler $node, $i)
                        {
                            
                            //With Sub Category
                            $node->filter('.product.withSynonyms')->each(function(Crawler $node, $i)
                            {
                                
                                global $food, $category;
                                $food          = new data();
                                $food->options = array();
                                $food->price   = 0;
                                
                                $node->filter('.information')->each(function(Crawler $node, $i)
                                {
                                    
                                    $node->filter('.name')->each(function(Crawler $node, $i)
                                    {
                                        global $food;
                                        $food->name = sanitize($node->html());
                                    });
                                    
                                    if ($node->filter('.description')->count() !== 0) {
                                        $node->filter('.description')->each(function(Crawler $node, $i)
                                        {
                                            global $food;
                                            $food->description = sanitize($node->html(), false);
                                        });
                                    } else {
                                        global $food;
                                        $food->description = '';
                                    }
                                    
                                });
                                
                                
                                $node->filter('.details')->each(function(Crawler $node, $i)
                                {
                                    
                                    global $food, $subfood;
                                    $subfood = new data();
                                    
                                    $node->filter('.synonymName')->each(function(Crawler $node, $i)
                                    {
                                        global $subfood;
                                        $subfood->name = sanitize($node->html());
                                    });
                                    
                                    $node->filter('.price')->each(function(Crawler $node, $i)
                                    {
                                        global $subfood;
                                        $subfood->price = sanitize(str_replace('£', '', $node->html()));
                                    });
                                    
                                    $food->options[] = $subfood;
                                    
                                });
                                
                                $category->foods[] = $food;
                            });
                            
                            //Without Sub Category
                            $node->filter('.product:not(.withSynonyms)')->each(function(Crawler $node, $i)
                            {
                                
                                global $food, $category;
                                $food          = new data();
                                $food->options = null;
                                
                                $node->filter('.information')->each(function(Crawler $node, $i)
                                {
                                    
                                    $node->filter('.name')->each(function(Crawler $node, $i)
                                    {
                                        global $food;
                                        $food->name = sanitize(($node->html()));
                                    });
                                    
                                    if ($node->filter('.description')->count() !== 0) {
                                        $node->filter('.description')->each(function(Crawler $node, $i)
                                        {
                                            global $food;
                                            $food->description = sanitize($node->html());
                                        });
                                    } else {
                                        global $food;
                                        $food->description = '';
                                    }
                                    
                                    
                                });
                                
                                $node->filter('.price')->each(function(Crawler $node, $i)
                                {
                                    global $food;
                                    $food->price = sanitize(str_replace('£', '', $node->html()));
                                });
                                
                                $category->foods[] = $food;
                                
                            });
                            
                        });
                        
                        $categories[] = $category;
                        
                    }
                    
                });

                $restaurant->categories = $categories;
                
                //What if the single category is one of the ones not alllowed like specials
                if(count($categories) == 0){
                    $logger->debug('No Acceptable Food Categories Found');
                    $restaurant->empty = 1;
                    // return false;
                }
                else {
                    $restaurant->empty = 0;
                }

            }
            
            return $restaurant;
        }
        
        public function restaurant_info($filename)
        {
            
            global $restaurant, $logger, $config, $information;
            $config = $this->config;
            
            $information = null;

            $restaurant = new data();
            $location = new data();

            $crawler = new Crawler(file_get_contents($filename));
            
            $restaurant->file = $filename;
            
            $error = $crawler->filter('.c-search__error-text')->count();
            
            if ($error) {
                $restaurant->error = "Restaurant Has been Deleted";
                return $restaurant;
            }
            
            $logger->debug("Fetching Restaurant Information");
            
            $crawler->filter('script')->each(function(Crawler $node, $i)
            {
                global $information;
                $script = $node->html();
                preg_match('/^\s*dataLayer\.push\((.+)\);/', $script, $matches);
                
                if ($matches) {
                    $decoded = (object) json_decode($matches[1]);
                    
                    if (isset($decoded->trData)) {
                        $information = $decoded->trData;
                    }
                    
                }
                
            });
            

            if ($information) {
                
                $restaurant->url = $information->menuurl;

                // print_r($information);
                $restaurant->name = sanitize($information->name);
                
                if($information->rating){
                    // $rating = new data();
                    // $rating->num = $information->rating->nRatings;
                    // $rating->average = $information->rating->average;
                    // $restaurant->rating = $rating;

                    $restaurant->rating = $information->rating->average;
                    $restaurant->num_ratings =  $information->rating->nRatings;
                }


                if ($information->address) {
                    $location->address1        = sanitize($information->address->streetAddress);
                    $location->address2        = '';
                    $location->address3        = '';
                    $location->city            = sanitize($information->address->addressLocality);
                    $location->address_country = sanitize($information->address->addressCountry);
                    $location->postcode        = sanitize($information->address->postalCode);
                    
                    $location->country = sanitize($config->country);
                    $location->county  = sanitize($config->county);
                }

                $restaurant->name = $this->parse_name($restaurant->name,$location->city);
                
                $online_id             = $information->trId;
                $restaurant->online_id = $online_id;
                $hygiene_rating        = 'NULL';
                
                if ($online_id) {
                    
                    
                    if ($config->development) {
                        $hygiene_rating = rand(1, 5);
                    } else {
                        
                        $client = HttpClient::create();
                        
                        $response = $client->request('GET', "https://hygieneratingscdn.je-apis.com/api/uk/restaurants/$online_id");
                        
                        $statusCode = $response->getStatusCode();
                        
                        if ($statusCode == 200) {
                            $content = json_decode($response->getContent());
                            
                            if (is_numeric($content->rating)) {
                                $hygiene_rating = $content->rating;
                            }
                            
                        } else {
                            $logger->error("No Hygiene Rating Found For $restaurant->name", array(
                                'url' => $restaurant->url
                            ));
                        }
                    }
                    
                }
                
                
                $restaurant->hygiene_rating = $hygiene_rating;
                
                $location->longitude   = $information->geo->longitude;
                $location->latitude   = $information->geo->latitude;

                $restaurant->categories = str_replace('|', ', ', $information->cuisines);
                
                $opening_hours = array();
                
                $days = array(
                    'Monday',
                    'Tueday',
                    'Wednesday',
                    'Thurday',
                    'Friday',
                    'Saturday',
                    'Sunday'
                );
                
                for ($i = 0; $i < count($information->openingHours); $i++) {
                    
                    $weekday = str_replace('"', '', $information->openingHours[$i]);
                    preg_match('/^(\w+)\W+(\d+:\d+)\W+(\d+:\d+)|^(\w+)\W+\-/', $weekday, $matches);
                    
                    if (!$matches) {
                        $logger->error('Opening Hours, Weekdays Format Not Recognised', $information->openingHours);
                        return;
                    }
                    
                    $day = $days[$i];
                    
                    if (count($matches) > 2) {
                        $open  = $matches[2];
                        $close = $matches[3];
                    } else {
                        $open  = '';
                        $close = '';
                    }
                    
                    $open_hours        = new data();
                    $open_hours->day   = $day;
                    $open_hours->open  = $open;
                    $open_hours->close = $close;
                    
                    $opening_hours[] = $open_hours;
                }
                
                $restaurant->hours = json_encode($opening_hours);
                
            } else {
                $logger->error('No Restaurant Information Found');
                return false;
            }
            
            $restaurant->location = $location;
            return $restaurant;
            
        }
        
        public function insert_menu($categories)
        {
            
            global $database, $logger;
            $database      = $this->database;
            $connection    = $database->connection;
            $restaurant_id = $this->restaurant_id;
            
            foreach ($categories as $category) {
                $catName        = $category->name;
                $catDescription = $category->description;
                
                $database->database_query("INSERT INTO category (name, description) VALUES ('$catName', '$catDescription')");
                $catId = $connection->insert_id;
                
                $logger->debug("Inserting Category $catName");
                //Insert into db and category_id and use to insert food
                foreach ($category->foods as $food) {
                    $foodName        = $food->name;
                    $foodDescription = $food->description;
                    $foodPrice       = 0;
                    if ($food->price) {
                        $foodPrice = $food->price;
                    }
                    
                    $database->database_query("INSERT INTO food (name, description,price,num_ratings,overall_rating,restaurant_id,category_id) 
                VALUES ('$foodName','$foodDescription','$foodPrice','0',null,'$restaurant_id','$catId')");
                    
                    $foodId = $connection->insert_id;
                    
                    if ($food->options) {
                        
                        foreach ($food->options as $option) {
                            $optionName  = $option->name;
                            $optionPrice = $option->price;
                            
                            $database->database_query("INSERT into sub_category(name,price,food_id) values('$optionName','$optionPrice','$foodId')");
                            
                        }
                        
                    }
                    
                }
            }
            
        }
        
        public function insert_restaurant($restaurant)
        {
            
            global $database, $logger, $config;
            $database = $this->database;
            
            $user_id        = $config->user_id;
            $name           = $restaurant->name;
            $hours          = $restaurant->hours;
            $categories     = $restaurant->categories;
            $online_id      = $restaurant->online_id;
            $hygiene_rating = $restaurant->hygiene_rating;

            $location = $restaurant->location;
            
            $address1 = $location->address1;
            $address2 = $location->address2;
            $address3 = $location->address3;
            $postcode = $location->postcode;
            $county   = $location->county;
            $country  = $location->country;
            $url      = $restaurant->url;
            
            $longitude = $location->longitude;
            $latitude  = $location->latitude;

            $rating = $restaurant->rating;
            $num_ratings = $restaurant->num_ratings;
            
            $city = $location->city;
            
            $logo = "https://d30v2pzvrfyzpo.cloudfront.net/uk/images/restaurants/$online_id.gif";
            
            $logger->debug('Hygiene Rating: ' . $hygiene_rating);
            
            $database->database_query("insert into restaurant(name,opening_hours,cuisines,user_id,online_id,url,hygiene_rating,overall_rating,num_ratings) 
        values('$name','$hours','$categories','$user_id','$online_id','$url',$hygiene_rating,$rating,$num_ratings)");

        //     $database->database_query("insert into restaurant(name,opening_hours,categories,user_id,online_id,url,hygiene_rating,rating,num_ratings) 
        // values('$name','$hours','$categories','$user_id','$online_id','$url',$hygiene_rating,$rating,$num_ratings)");
            
            $restaurant_id = $database->connection->insert_id;
            
            // $saving_logo = __DIR__."/../resources/$config->city/logos/$restaurant_id.gif";
            // file_put_contents($saving_logo, fopen($logo, 'r'));
            // save_image( base64_encode(fopen($logo, 'r')), 'logo', "$restaurant_id.gif");
            save_image(base64_encode(file_get_contents($logo)), 'logo', "$restaurant_id.gif");
            
            $logger->debug("Uploading logo/$restaurant_id.gif to S3 Bucket");
            
            $logger->debug("Successfully Inserted New Restaurant, $name", array(
                'id' => $restaurant_id,
                'url' => $url
            ));
            
            $this->restaurant_id = $restaurant_id;
            
            $database->database_query("insert into location (address_line1,address_line2,address_line3,postcode,city,county,country,restaurant_id,longitude,latitude) 
        values ('$address1','$address2','$address3','$postcode','$city','$county','$country','$restaurant_id',$longitude,$latitude)");
            
            $logger->debug("Successfully Inserted Restaurant Location");
            
        }
        
        public function donwload_page($url){
            #Add some error handling, for handling issues with request and being blocked and retrying
            global $logger;

            $city = $this->config->city;
            
            $client  = new Client();

            $retry = $this->config->retry->count;
            $wait = $this->config->retry->wait;

            $download_success = 0;

            preg_match('/https:\/\/www\.just-eat\.co\.uk\/(.+)\/menu/', $url, $matches);
                    
            if (!$matches){
                throw new Exception("Invalid Menu URL Provided: $url");
            }

            for($i =0;$i < $retry;$i++){

                try {

                    $crawler = $client->request('GET', $url);
                    $html    = $crawler->html();
                    
                    $restaurant_name = $matches[1];
                    $restaurant_file = $this->config->directories->restaurants . "/$restaurant_name.html";
        
                    //IF captcha page then wait for a few seconds and try again
                    if(is_nan(stripos($html,'<script src="/_Incapsula_Resource?'))){
                        // $logger->error('Captcha Page Found. Trying Again');
                        throw new Exception('Captcha Page Found. Trying Again');
                    }
        
                    file_put_contents($restaurant_file, $html);
        
                    $logger->debug("Downloading $url -> $restaurant_name.html");

                    $download_success = 1;

                    break;

                }
                catch (Exception $e){
                    $message = $e->getMessage();
                    $logger->error("Restaurant Download Error: $message", [$e->getTrace()] );
                    $logger->debug('Trying Restaurant Download Again Shortly');
                    sleep($wait);
                }

            }

            if(!$download_success){
                throw new Exception('Failed To Download Restaurant File');
            }
            else {
                $logger->debug('Restaurant Page Downloaded Successfully');
            }

            return $restaurant_file;

        }

        //Restaurant
        public function restaurant($url)
        {
            
            global $logger, $config;
            
            $logger->debug("Restaurant URL: $url");
            $config = $this->config;
            
            if ($this->exists($url)) {
                return $logger->warning('Skipping Restaurant, Already Exists In Database', array(
                    'url' => $url
                ));
            }
            
            $restaurant_file = $this->donwload_page($url);
            
            //If they give us a different page, try again upto 4 times before erroring
            $retry = $config->retry->count;
            $wait  = $config->retry->wait;

            // print_r($config->retry);

            for($i =0;$i < $retry;$i++){
                $info = $this->restaurant_info($restaurant_file);
                if($info){
                    $logger->info('Restaurant Info Found');
                    break;
                }
                else {
                    $logger->warning('Retry Restaurant Info, Not Found Yet');
                    sleep($wait);
                    $restaurant_file = $this->donwload_page($url);
                }
            }
            
            $logger->debug("------ $info->name Restaurant Start -------");
            
            if ($info) {

                if ($info->url != $url) {
                    throw new Exception('URL Has Changed Into: ' . $info->url);
                }

                $restaurant_exists_cross = $this->cross_search($info);

                if($restaurant_exists_cross){
                    $logger->debug($info->url.' Not Found In Deliveroo');
                }
                else {
                    $logger->debug($info->url.' Found In Deliveroo. Skipping');
                    return;
                }

                for($i =0;$i < $retry;$i++){

                    $menu = $this->menu($restaurant_file);

                    if($menu){

                        if($menu->empty){
                            $logger->warning('No Foods Found');
                        }
                        else {
                            $logger->debug('Restaurant Menu Found');
                        }
                        
                        break;
                    }
                    else {
                        $logger->warning('Retry Restaurant Menu Not Found Yet');
                        sleep($wait);
                        $restaurant_file = $this->donwload_page($url);
                    }

                }

                if($menu){

                    if($menu->empty){
                        $logger->debug('Skipping Empty Restaurant');
                    }
                    else {
                        $this->insert_restaurant($info);
                        $this->insert_menu($menu->categories);
                    }

                }
                else {
                    throw new Exception('Failed To Find Restaurant Menu');
                }

            }
            else {
                throw new Exception('Restaurant Info Not Found');
            }
            
            $logger->debug("------ $info->name Restaurant Complete -------");
            
        }
        
        public function new_restaurants($new_restaurants)
        {
            
            global $logger;
            $city = $this->config->city;
            
            $new_restaurants = array_unique($new_restaurants);

            $count = count($new_restaurants);
            $logger->notice("All $count New Halal Restaurants Added");
            
            $destination = $this->config->directories->list;
            $list = json_encode($new_restaurants);
            file_put_contents("$destination/$city.json", $list);
            
        }
        
        public function exists($url)
        {
            global $database, $logger;
            $database = $this->database;
            
            $logger->debug("Checking If Present Already $url");
            
            $duplicate = false;
            
            $results = $database->database_query("select * from restaurant where url='$url'");
            if ($results->num_rows != 0) {
                $logger->error("Duplicate Restaurant");
                $duplicate = true;
            } else {
                $logger->debug("New Restaurant");
            }
            
            return $duplicate;
            
        }
        
        //If previous fails due to error, delete all relating to it and reset incrementer
        public function error($url)
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
        
        public function update_restaurants(){
            global $database,$logger,$config;

            $database = $this->database;

            //Fetch Restaurant That haven't been updated in a week.
            // $results  = $database->database_query("SELECT * FROM restaurant where url like 'https://www.just-eat.co.uk/%' and updated < ( NOW() - INTERVAL 7 DAY )");
            $results  = $database->database_query("SELECT * FROM restaurant where updated is null and site='justeat';");
            
            $restaurant_count = $results->num_rows;

            $logger->debug("$restaurant_count Restaurants Require Updates");

            if($restaurant_count){
                for($i =0;$i < $restaurant_count;$i++){
                    $row = $results->fetch_assoc();

                    $restaurant_url = $row['url'];
                    $restaurant_id  = $row['id'];
                    $restaurant_name = $row['name'];
                    $hygiene_rating = $row['hygiene_rating'];
                    $restaurant_rating = $row['overall_rating'];
                    $restaurant_num_rating = $row['num_ratings'];
                    $restaurant_opening_hours = $row['opening_hours'];

                    $logger->debug("------ $restaurant_name($restaurant_id) START -------");

                    $restaurant_file = $this->donwload_page($restaurant_url);
                    // $restaurant_file = './resources/Birmingham/restaurants/restaurants-IndigoBengalFusion-ws9.html';
                    // $restaurant_file = '/home/ubuntu/justeat/domain/../resources/Birmingham/restaurants/restaurants-IndigoBengalFusion-ws9.html';

                    $retry = $config->retry->count;
                    $wait = $config->retry->wait;

                    for($i =0;$i < $retry;$i++){
                        $info = $this->restaurant_info($restaurant_file);

                        if($info){

                            if(property_exists($info, 'error')){
                                $logger->error($info->error);
                                break;
                            }
                            else {
                                $logger->info('Restaurant Info Found');
                                break;
                            }

                        }
                        else {
                            $logger->warning('Retry Restaurant Info, Not Found Yet');
                            sleep($config->retry->wait);
                            $restaurant_file = $this->donwload_page($restaurant_url);
                        }
                    }

                    if(!$info){
                        throw new Exception('Failed To Find Restaurant Info');
                    }
                    elseif(property_exists($info, 'error')){
                        $logger->error('Error: Disabling '.$restaurant_name);
                        $update = $database->database_query("UPDATE restaurant set active = 0, updated = NOW() where id='$restaurant_id'");
                        continue;
                    }

                    $new_hygiene_rating = $info->hygiene_rating ? $info->hygiene_rating : 'NULL';
                    $new_hours          = $info->hours ? $info->hours : $restaurant_opening_hours;
                    $new_rating         = $info->rating ? $info->rating : 'NULL';
                    $new_num_ratings    = $info->num_ratings ? $info->num_ratings : 'NULL';

                    $logger->debug("Updating $restaurant_name($restaurant_id)\t");

                    $logger->debug("Hygiene Rating: $hygiene_rating -> $new_hygiene_rating");
                    $logger->debug("Rating: $restaurant_rating -> $new_rating");
                    $logger->debug("Num Rating: $restaurant_num_rating -> $new_num_ratings");
                    
                    // #ALTER TABLE restaurant CHANGE rating overall_rating decimal(5,2);
                    // #ALTER TABLE restaurant CHANGE num_rating num_ratings int;

                    $update_database_query = "UPDATE restaurant set hygiene_rating=$new_hygiene_rating,opening_hours='$new_hours',overall_rating=$new_rating,num_ratings=$new_num_ratings, updated = NOW() where id='$restaurant_id'";
                    // $logger->debug($update_database_query);

                    $update = $database->database_query($update_database_query);
                    if(!$update){
                        throw new Exception('Failed To Update Restaurant');
                    }

                    $logger->debug("------ $restaurant_name($restaurant_id) END -------");

                    sleep($config->waiting_time->updating);

                }   
            }

        }
    }
    
?>
