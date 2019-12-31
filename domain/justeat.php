<?php
    
    require_once __DIR__ . '/../services/database.php';
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../data/data.php';
    require_once __DIR__ . '/../services/logger.php';
    require_once __DIR__ . '/../services/strings.php';
    require_once __DIR__ . '/../services/places.php';
    require_once __DIR__ . '/../services/save_image.php';
    
    
    use Symfony\Component\DomCrawler\Crawler;
    use Goutte\Client;
    use \Symfony\Component\HttpClient\HttpClient;
    
    class justEat
    {
        
        public $restaurant_id;
        
        public $connection;
        public $config;
        public $development;
        
        function __construct($config)
        {
            $this->config      = $config;
            $this->development = $config->development;
        }
        
        //Get All Restaurant PostCodes For Given City
        public function postcodes($url, $city)
        {
            
            global $logger, $output, $location, $config, $client, $development, $sleeping_time;
            
            // $client = new Client();
            $client = HttpClient::create();
            
            $config = $this->config;
            
            $output = array();
            
            $location = __DIR__ . "/../resources/$city/postcodes";
            
            // if (!file_exists($location)) {
            //     mkdir($location);
            // } else {
                
            //     foreach (scandir($location) as $file) {
                    
            //         $file = $location . "/" . $file;
                    
            //         //Make sure that this is a file and not a directory.
            //         if (is_file($file)) {
            //             //Use the unlink function to delete the file.
            //             unlink($file);
            //         }
                    
            //     }
                
            // }
            
            $logger->debug('Fetching PostCodes From '.$url);

            $response = $client->request('GET', $url);
            $crawler = new Crawler($response->getContent());
            
            $logger->debug('PostCodes Fetched');

            $development   = $this->development;
            $sleeping_time = $config->waiting_time->postcode;
            
            $logger->debug(count($crawler->filter('li.grouped-link-list__link-item a'))." Postcodes Found");

            $crawler->filter('li.grouped-link-list__link-item a')->each(function(Crawler $node, $i)
            {
                global $output, $location, $logger, $sleeping_time, $client, $development,$config;
                
                $postcode_url  = $node->attr('href');
                $postcode_name = shorten(preg_replace('/.+,/', '', $node->html()));
                
                $postcode_saving_location = $location . "/$postcode_name.html";
                
                $postcode_info = array(
                    'name' => $postcode_name,
                    'url' => $postcode_url
                );
                
                $logger->debug('PostCode: '.$postcode_name, $postcode_info);
                //////////////////////////////////////////////////////////////////////////////
                
                if (!$development) {
                    
                    $logger->debug('Downloading '.$postcode_url);

                    $response = $client->request('GET', $postcode_url,['timeout' => 20]);
                    // $crawler = $client->request('GET', $postcode_url,['timeout' => 0.5]);
                    
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

                    // $restaurant = $crawler->filter('section.c-listing-item');
                    
                    // if (sizeof($restaurant) == 0) {
                    //     $logger->debug("No Restaurants For Postcode", $postcode_info);
                    //     return;
                    // }

                    // $restaurant = $crawler->filter('section.c-listing-item')->eq(0);
                    $restaurant_count = count($crawler->filter('section.c-listing-item'));

                    if ($restaurant_count == 0) {
                        $logger->debug("No Restaurants Found", $postcode_info);
                        return;
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
            $database = new Database();
            $output   = array();
            
            // print_r($postcodes);
            foreach ($postcodes as $postcode => $data) {
                
                $logger->debug("Crawling Postcode $postcode");
                
                $file = $data['file'];
                
                // $file = __DIR__."resources/postcodes/$postcode";
                /////////////////////////////////////
                
                
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
                        
                        preg_match('/halal/i', $node->filter('p[itemprop]')->eq(0)->attr('data-cuisine-names'), $matches);
                        $restaurant_name = $node->filter('h3[data-test-id].c-listing-item-title')->eq(0)->text();
                        
                        if (!$matches) {
                            $logger->debug("Restaurant Not Halal Skipping, $restaurant_name");
                            return;
                        }
                        
                        $online_id = $node->attr('data-restaurant-id');
                        $url       = 'https://www.just-eat.co.uk' . $node->filter('a.c-listing-item-link')->eq(0)->attr('href');
                        
                        if (!array_key_exists($url, $output)) {
                            
                            $result = $database->query("select * from restaurant where online_id='$online_id'");
                            
                            if ($result->num_rows) {
                                $logger->debug("Restaurant Already Exists $restaurant_name Skipping, $url");
                                //Update or Skip
                                // echo "Found";
                            } else {
                                $logger->debug("New Halal Restaurant $restaurant_name, $url");
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
            
            // preg_match('/https:\/\/www\.just-eat\.co\.uk\/(.+)\/menu/',$url,$matches);
            // file_put_contents(__DIR__.'/../resources/restaurants/'.$matches[1]."_menu.html",$html);
            
            //////////////////////////////////////////////////////////////////////////////////////////////
            
            // $html = file_get_contents(__DIR__.'/../resources/restaurants/restaurants-caspian-grill-and-pizza-birmingham.html');
            // $crawler = new Crawler($html);
            
            /////////////////////////////////////////////////////////////////////////////////////////////
            
            
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
                        $category_name  = shorten($node->html());
                        $category->name = $category_name;
                        // $logger->debug("Category: $category_name");
                    });
                    
                    if ($node->filter('.categoryDescription')->count() !== 0) {
                        
                        $node->filter('.categoryDescription')->each(function(Crawler $node, $i)
                        {
                            global $category;
                            $category->description = shorten($node->html(), false);
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
                                        $food->name = shorten($node->html());
                                    });
                                    
                                    if ($node->filter('.description')->count() !== 0) {
                                        $node->filter('.description')->each(function(Crawler $node, $i)
                                        {
                                            global $food;
                                            $food->description = shorten($node->html(), false);
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
                                        $subfood->name = shorten($node->html());
                                    });
                                    
                                    $node->filter('.price')->each(function(Crawler $node, $i)
                                    {
                                        global $subfood;
                                        $subfood->price = shorten(str_replace('£', '', $node->html()));
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
                                        $food->name = shorten(($node->html()));
                                    });
                                    
                                    if ($node->filter('.description')->count() !== 0) {
                                        $node->filter('.description')->each(function(Crawler $node, $i)
                                        {
                                            global $food;
                                            $food->description = shorten($node->html());
                                        });
                                    } else {
                                        global $food;
                                        $food->description = '';
                                    }
                                    
                                    
                                });
                                
                                $node->filter('.price')->each(function(Crawler $node, $i)
                                {
                                    global $food;
                                    $food->price = shorten(str_replace('£', '', $node->html()));
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
            
            $crawler = new Crawler(file_get_contents($filename));
            
            $restaurant->file = $filename;
            
            $error = $crawler->filter('.c-search__error-text')->count();
            
            if ($error) {
                $logger->error("Restaurant Has been Deleted");
                return false;
            }
            
            $logger->debug("Fetching Restaurant Information: $filename");
            
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
                $restaurant->name = shorten($information->name);
                
                if($information->rating){
                    // $rating = new data();
                    // $rating->num = $information->rating->nRatings;
                    // $rating->average = $information->rating->average;
                    // $restaurant->rating = $rating;

                    $restaurant->rating = $information->rating->average;
                    $restaurant->num_ratings =  $information->rating->nRatings;
                }


                if ($information->address) {
                    $restaurant->address1        = shorten($information->address->streetAddress);
                    $restaurant->address2        = '';
                    $restaurant->address3        = '';
                    $restaurant->city            = shorten($information->address->addressLocality);
                    $restaurant->address_country = shorten($information->address->addressCountry);
                    $restaurant->postcode        = shorten($information->address->postalCode);
                    
                    $restaurant->country = shorten($config->country);
                    $restaurant->county  = shorten($config->county);
                }
                
                preg_match('/(.+?)\s-\s[A-Z].+/i', $restaurant->name, $matches1);
                preg_match('/(^.+?)\s?\(.+\)/i', $restaurant->name, $matches2);
                preg_match("/^(.+)\\s$restaurant->city/i", $restaurant->name, $matches3);
                
                if ($matches1) {
                    $restaurant->name = $matches1[1];
                } elseif ($matches2) {
                    $restaurant->name = $matches2[1];
                } elseif ($matches3) {
                    $restaurant->name = $matches3[1];
                }
                
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
                            $logger->error('No Hygiene Rating Found For $restaurant->name', array(
                                'url' => $restaurant->url
                            ));
                        }
                    }
                    
                }
                
                
                $restaurant->hygiene_rating = $hygiene_rating;
                
                $restaurant->location   = $information->geo;
                $restaurant->categories = str_replace('|', ', ', htmlentities($information->cuisines,ENT_QUOTES));
                
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
                
                // print_r($information->openingHours);
                for ($i = 0; $i < count($information->openingHours); $i++) {
                    
                    $weekday = str_replace('"', '', $information->openingHours[$i]);
                    preg_match('/^(\w+)\W+(\d+:\d+)\W+(\d+:\d+)|^(\w+)\W+\-/', $weekday, $matches);
                    
                    if (!$matches) {
                        $logger->error('Opening Hours, Weekdays Format Not Recognised', $information->openingHours);
                        return;
                    }
                    
                    $day = $days[$i];
                    // return $matches;
                    
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
                return false;
            }
            
            return $restaurant;
            
        }
        
        //Not In Use Anymore
        public function info($url)
        {
            
            global $restaurant, $logger, $config, $information;
            $config = $this->config;
            
            $restaurant = new data();
            
            if ($config->development) {
                $crawler = new Crawler(file_get_contents($url));
            } else {
                throw new Exception('Live');
                
                $client  = new Client();
                $crawler = $client->request('GET', $url);
                $html    = $crawler->html();
                
            }
            
            $restaurant->url = $url;
            
            $error = $crawler->filter('.c-search__error-text')->count();
            
            if ($error) {
                $logger->error("$url doesn't really exist");
                return false;
            }
            
            $logger->debug("Fetching info,$url");
            
            if (!$config->development) {
                preg_match('/https:\/\/www\.just-eat\.co\.uk\/(.+)/', $url, $matches);
                file_put_contents(__DIR__ . '/../resources/restaurants/' . $matches[1] . "_info.html", $html);
            }
            
            $crawler->filter('script')->each(function(Crawler $node, $i)
            {
                global $information;
                $script = $node->html();
                preg_match('/^\s*dataLayer\.push\((.+)\);/', $script, $matches);
                
                if ($matches) {
                    $decoded = json_decode($matches[1]);
                    
                    if ($decoded->trData) {
                        $information = $decoded->trData;
                    }
                    
                }
                
            });
            
            $crawler->filter('.restaurantOverview ')->each(function(Crawler $node, $i)
            {
                
                global $restaurant;
                
                $node->filter('h1')->each(function(Crawler $node, $i)
                {
                    global $restaurant, $logger;
                    $restaurant->name = shorten($node->html());
                    $logger->notice("New Restaurant: " . $restaurant->name);
                });
                
                $restaurant->online_id = $node->attr('data-restaurant-id');
                
                $node->filter('.cuisines')->each(function(Crawler $node, $i)
                {
                    
                    global $restaurant;
                    $restaurant->categories = shorten($node->html());
                    
                });
                
                $node->filter('.address')->each(function(Crawler $node, $i)
                {
                    global $config;
                    
                    $location = explode(",", shorten($node->html()));
                    $length   = count($location);
                    
                    global $restaurant;
                    
                    switch ($length) {
                        case $length === 3:
                            $restaurant->address1 = shorten($location[0]);
                            $restaurant->address2 = '';
                            $restaurant->address3 = '';
                            $restaurant->postcode = shorten($location[2]);
                            $restaurant->city     = shorten($location[1]);
                            break;
                        
                        case $length === 4:
                            $restaurant->address1 = shorten($location[0]);
                            $restaurant->address2 = shorten($location[1]);
                            $restaurant->address3 = '';
                            $restaurant->postcode = shorten($location[3]);
                            $restaurant->city     = shorten($location[2]);
                            break;
                        
                        case $length === 5:
                            $restaurant->address1 = shorten($location[2]);
                            $restaurant->address2 = shorten($location[0]);
                            $restaurant->address3 = shorten($location[1]);
                            
                            $restaurant->postcode = shorten($location[4]);
                            $restaurant->city     = shorten($location[3]);
                            break;
                    }
                    
                    $restaurant->address = $restaurant->address1 . "," . $restaurant->address2 . "," . $restaurant->address3;
                    $restaurant->country = $config->country;
                    $restaurant->county  = $config->county;
                    
                });
                
            });
            
            $crawler->filter('.restaurantMenuDescription')->each(function(Crawler $node, $i)
            {
                global $restaurant;
                $restaurant->description = shorten($node->html());
            });
            
            $crawler->filter('.restaurantOpeningHours table')->each(function(Crawler $node, $i)
            {
                
                global $restaurant;
                
                $restaurant->hours = '[';
                
                $node->filter('tr')->each(function(Crawler $node, $i)
                {
                    global $restaurant;
                    
                    $day  = $node->filter('td')->eq(0)->text();
                    $time = $node->filter('td')->eq(1)->text();
                    
                    $hours = explode(' - ', $time);
                    
                    $open  = $hours[0];
                    $close = $hours[1];
                    
                    $restaurant->hours .= <<<END
{
"day": "$day",
"open": "$open",
"close": "$close"
},
END;
                    
                });
                
                $restaurant->hours .= ']';
                
                $restaurant->hours = shorten(str_replace(',]', ']', $restaurant->hours));
                
            });
            
            $place                = places($restaurant);
            $restaurant->hours    = $place->opening_hours;
            $restaurant->location = $place->location;
            
            return $restaurant;
            
        }
        
        public function insert_menu($categories)
        {
            
            global $database, $logger;
            $database      = new Database();
            $connection    = $database->connection;
            $restaurant_id = $this->restaurant_id;
            
            foreach ($categories as $category) {
                $catName        = $category->name;
                $catDescription = $category->description;
                
                $database->query("INSERT INTO category (name, description) VALUES ('$catName', '$catDescription')");
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
                    
                    $database->query("INSERT INTO food (name, description,price,num_ratings,overall_rating,restaurant_id,category_id) 
                VALUES ('$foodName','$foodDescription','$foodPrice','0',null,'$restaurant_id','$catId')");
                    
                    $foodId = $connection->insert_id;
                    
                    if ($food->options) {
                        
                        foreach ($food->options as $option) {
                            $optionName  = $option->name;
                            $optionPrice = $option->price;
                            
                            $database->query("INSERT into sub_category(name,price,food_id) values('$optionName','$optionPrice','$foodId')");
                            
                        }
                        
                    }
                    
                }
            }
            
        }
        
        public function insert_restaurant($restaurant)
        {
            
            global $database, $logger, $config;
            $database = new Database();
            
            $user_id        = $config->user_id;
            $name           = $restaurant->name;
            $hours          = $restaurant->hours;
            $categories     = $restaurant->categories;
            $online_id      = $restaurant->online_id;
            $hygiene_rating = $restaurant->hygiene_rating;
            
            $address1 = $restaurant->address1;
            $address2 = $restaurant->address2;
            $address3 = $restaurant->address3;
            $postcode = $restaurant->postcode;
            $county   = $restaurant->county;
            $country  = $restaurant->country;
            $url      = $restaurant->url;
            
            $longitude = $restaurant->location->longitude;
            $latitude  = $restaurant->location->latitude;

            $rating = $restaurant->rating;
            $num_ratings = $restaurant->num_ratings;
            
            $city = $restaurant->city;
            
            $logo = "https://d30v2pzvrfyzpo.cloudfront.net/uk/images/restaurants/$online_id.gif";
            
            $logger->debug('Hygiene Rating: ' . $hygiene_rating);
            
            $database->query("insert into restaurant(name,opening_hours,categories,user_id,online_id,url,hygiene_rating) 
        values('$name','$hours','$categories','$user_id','$online_id','$url',$hygiene_rating)");

        //     $database->query("insert into restaurant(name,opening_hours,categories,user_id,online_id,url,hygiene_rating,rating,num_ratings) 
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
            
            $database->query("insert into location (address_line1,address_line2,address_line3,postcode,city,county,country,restaurant_id,longitude,latitude) 
        values ('$address1','$address2','$address3','$postcode','$city','$county','$country','$restaurant_id',$longitude,$latitude)");
            
            $logger->debug("Successfully Inserted Restaurant Location");
            
        }
        
        public function donwload_page($url){

            global $config,$logger;

            $city = $this->config->city;
            
            $client  = new Client();
            $crawler = $client->request('GET', $url);
            $html    = $crawler->html();
            
            preg_match('/https:\/\/www\.just-eat\.co\.uk\/(.+)\/menu/', $url, $matches);
            
            if (!$matches){
                throw new Exception("Invalid Menu URL Provided: $url");
            }

            $restaurant_name = $matches[1];
            $restaurant_file = __DIR__ . "/../resources/$city/restaurants/$restaurant_name.html";
            //IF captcha page then wait for a few seconds and try again

            if(is_nan(stripos($html,'<script src="/_Incapsula_Resource?'))){
                $logger->error('Captcha Page Found. Trying Again');
            }

            file_put_contents($restaurant_file, $html);

            $logger->debug("Downloading $url -> $restaurant_name.html");

            return $restaurant_file;

        }

        //Restaurant
        public function restaurant($url)
        {
            
            global $logger, $config;
            
            $logger->debug("Restaurant URL: $url");
            $config = $this->config;
            $city   = $config->city;
            
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
                    $logger->warning('Restaurant Info Found');
                    break;
                }
                else {
                    $logger->warning('Retry Restaurant Info, Not Found Yet');
                    sleep($wait);
                    $this->donwload_page($url);
                }
            }
            
            $logger->debug("------ $info->name Restaurant Start -------");
            
            if ($info) {

                if ($info->url != $url) {
                    throw new Exception('URL Has Changed Into: ' . $info->url);
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
                        $this->donwload_page($url);
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
            
            $list = json_encode($new_restaurants);
            file_put_contents("list/$city.json", $list);
            
        }
        
        public function exists($url)
        {
            global $database, $logger;
            $database = new Database();
            
            $logger->debug("Checking If Present Already $url");
            
            $duplicate = false;
            
            $results = $database->query("select * from restaurant where url='$url'");
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
            
            $database = new Database;
            $result   = $database->query("SELECT * from restaurant where url='$url'");
            
            if ($result->num_rows) {
                $logger->notice('Restaurant Found In Database, Deleting It', array(
                    'url' => $url
                ));
                
                $row           = $result->fetch_assoc();
                $restaurant_id = $row['id'];
                
                $database->query("DELETE FROM location where restaurant_id='$restaurant_id'");
                $database->query("ALTER TABLE location AUTO_INCREMENT = 1");
                
                $subresults = $database->query("SELECT * from food where restaurant_id='$restaurant_id' order by id asc limit 1");
                if ($subresults->num_rows) {
                    $subrow      = $subresults->fetch_assoc();
                    $food_id     = $subrow['id'];
                    $category_id = $subrow['category_id'];
                    
                    $database->query("DELETE from sub_category where food_id >= '$food_id'");
                    $database->query("ALTER TABLE sub_category AUTO_INCREMENT = 1");
                    
                    $database->query("DELETE from food where restaurant_id='$restaurant_id'");
                    $database->query("ALTER TABLE food AUTO_INCREMENT = 1");
                    
                    $database->query("DELETE from category where id >= '$category_id'");
                    $database->query("ALTER TABLE category AUTO_INCREMENT = 1");
                    
                } else {
                    //Failed on category,Delete last one
                    $database->query("delete ignore from category order by id desc limit 1");
                    $database->query("ALTER TABLE category AUTO_INCREMENT = 1");
                }
                
                
                //Failed to inserting location, no food or categories present
                $database->query("DELETE FROM restaurant where id='$restaurant_id'");
                $database->query("ALTER TABLE restaurant AUTO_INCREMENT = 1");
                
            }
            
        }
        
        public function update_restaurants(){
            global $database,$logger,$config;

            $database = new Database();

            //Fetch Restaurant That haven't been updated in a week.
            // $results  = $database->query("SELECT * FROM restaurant where url like 'https://www.just-eat.co.uk/%' and updated < ( NOW() - INTERVAL 7 DAY )");
            $results  = $database->query("SELECT * FROM restaurant where url like 'https://www.just-eat.co.uk/%'");
            
            $restaurant_count = $results->num_rows;

            $logger->debug("$restaurant_count Restaurants Require Updates");


            if($restaurant_count){
                for($i =0;$i < $restaurant_count;$i++){
                    $row = $results->fetch_assoc();
                    $restaurant_url = $row['url'];
                    $restaurant_id  = $row['id'];
                    $restaurant_name = $row['name'];
                    $hygiene_rating = $row['hygiene_rating'];
                    $restaurant_rating = $row['rating'];

                    $restaurant_file = $this->donwload_page($restaurant_url);
                    // $restaurant_file = 'D:\Ampps\www\justeat\resources\Birmingham\restaurants\restaurants-cafe-aromatico-walsall.html';

                    $info = (object) $this->restaurant_info($restaurant_file);
                    // print_r($info);

                    $new_hygiene_rating = $info->hygiene_rating ? $info->hygiene_rating : $hygiene_rating;
                    $new_hours          = $info->hours;
                    $new_rating         = $info->rating->average;
                    $new_num_ratings    = $info->rating->num;

                    $logger->debug("Updating $restaurant_name($restaurant_id)\t Hygiene Rating: $hygiene_rating -> $new_hygiene_rating\t Rating: $restaurant_rating -> $new_rating");
                    
                    // print("UPDATE restaurant set hygiene_rating='$new_hygiene_rating',opening_hours='$new_hours',rating='$new_rating',num_ratings='$new_num_ratings' where id='$restaurant_id'");

                    $update = $database->query("UPDATE restaurant set hygiene_rating=$new_hygiene_rating,opening_hours='$new_hours',rating=$new_rating,num_ratings=$new_num_ratings where id='$restaurant_id'");

                    // sleep($config->retry->wait);
                }   
            }

        }
    }
    
?>