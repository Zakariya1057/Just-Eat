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
            
            $client = new Client();
            
            $config = $this->config;
            
            $output = array();
            
            $location = __DIR__ . "/../resources/$city/postcodes";
            
            if (!file_exists($location)) {
                mkdir($location);
            } else {
                
                foreach (scandir($location) as $file) {
                    
                    $file = $location . "/" . $file;
                    
                    //Make sure that this is a file and not a directory.
                    if (is_file($file)) {
                        //Use the unlink function to delete the file.
                        unlink($file);
                    }
                    
                }
                
            }
            
            $crawler = $client->request('GET', $url);
            
            $development   = $this->development;
            $sleeping_time = $config->waiting_time->postcode;
            
            $crawler->filter('li.grouped-link-list__link-item a')->each(function(Crawler $node, $i)
            {
                global $output, $location, $logger, $sleeping_time, $client, $development;
                
                $postcode_url  = $node->attr('href');
                $postcode_name = shorten(preg_replace('/.+,/', '', $node->html()));
                
                $postcode_saving_location = $location . "/$postcode_name.html";
                
                $postcode_info = array(
                    'name' => $postcode_name,
                    'url' => $postcode_url
                );
                
                //////////////////////////////////////////////////////////////////////////////
                
                if (!$development) {
                    
                    $crawler = $client->request('GET', $postcode_url);
                    
                    $logger->debug('Download PostCode', $postcode_info);
                    
                    $restaurant = $crawler->filter('section.c-listing-item');
                    
                    if (sizeof($restaurant) == 0) {
                        $logger->debug("No Restaurants For Postcode", $postcode_info);
                        return;
                    }
                    
                    $logger->debug('Restaurants Found For PostCode');
                    
                    $content = $crawler->html();
                    
                    $logger->debug('Sleeping Before Downloading Next Postcodes');
                    sleep($sleeping_time);
                    
                } else {
                    $content = $postcode_url;
                }
                
                //////////////////////////////////////////////////////////////////////////////
                
                $logger->debug("Saving New Postcode File $postcode_name.html");
                
                file_put_contents($postcode_saving_location, $content);
                
                $output[$postcode_name] = array(
                    'url' => $postcode_url,
                    'file' => $postcode_saving_location
                );
                
            });
            
            // print_r($output);
            // $city = $this->city;
            // $postcode_area = $this->postcode_area;
            
            // for ($i = 1; $i <= 99;$i++){
            
            //     $url = "https://www.just-eat.co.uk/area/$postcode_area$i-$city";
            
            //     $crawler = $client->request('GET', $url );
            
            //     $restaurant = $crawler->filter('section.c-listing-item');
            
            //     if(sizeof($restaurant) !== 0){
            
            //         $logger->debug("PostCode Download $url Found");
            
            //         $path = __DIR__."/../resources/postcodes/$postcode_area$i-$city.html";
            //         $output[] = $path;
            //         file_put_contents($path,$crawler->html());
            
            //     }
            //     else {
            //         $logger->debug("PostCode $url, no restaurants found");
            //     }
            
            //     sleep(10);
            
            // }
            
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
            
            return $restaurant;
        }
        
        public function page_info($url)
        {
            
            global $restaurant, $logger, $config, $information;
            $config = $this->config;
            
            $information = null;
            $restaurant = new data();
            
            $crawler = new Crawler(file_get_contents($url));
            
            $restaurant->file = $url;
            
            $error = $crawler->filter('.c-search__error-text')->count();
            
            if ($error) {
                $logger->error("$url doesn't really exist");
                return false;
            }
            
            $logger->debug("Fetching info,$url");
            
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
            
            $restaurant->url = $information->menuurl;
            if ($information) {
                // print_r($information);
                $restaurant->name = shorten($information->name);
                
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
                $rating                = 'NULL';
                
                if ($online_id) {
                    
                    
                    if ($config->development) {
                        $rating = rand(1, 5);
                    } else {
                        
                        $client = HttpClient::create();
                        
                        $response = $client->request('GET', "https://hygieneratingscdn.je-apis.com/api/uk/restaurants/$online_id");
                        
                        $statusCode = $response->getStatusCode();
                        
                        if ($statusCode == 200) {
                            $content = json_decode($response->getContent());
                            
                            if (is_numeric($content->rating)) {
                                $rating = $content->rating;
                            }
                            
                        } else {
                            $logger->error('No Hygiene Rating Found For $restaurant->name', array(
                                'url' => $restaurant->url
                            ));
                        }
                    }
                    
                }
                
                $restaurant->hygiene_rating = $rating;
                
                $restaurant->location   = $information->geo;
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
                die('No Json Information Found');
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
                die('Live');
                
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
        
        public function food($categories)
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
        
        public function insert($restaurant)
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
            
            $city = $restaurant->city;
            
            $logo = "https://d30v2pzvrfyzpo.cloudfront.net/uk/images/restaurants/$online_id.gif";
            
            $logger->debug('Hygiene Rating: ' . $hygiene_rating);
            
            $database->query("insert into restaurant(name,opening_hours,categories,user_id,online_id,url,hygiene_rating) 
        values('$name','$hours','$categories','$user_id','$online_id','$url',$hygiene_rating)");
            
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
            
            // if(!$config->development){
            
            $client  = new Client();
            $crawler = $client->request('GET', $url);
            $html    = $crawler->html();
            
            preg_match('/https:\/\/www\.just-eat\.co\.uk\/(.+)\/menu/', $url, $matches);
            
            if (!$matches)
                die("Invalid Just Menu URL Provided: $url");
            
            $restaurant_file = __DIR__ . "/../resources/$city/restaurants/" . $matches[1] . ".html";
            file_put_contents($restaurant_file, $html);
            
            // }
            // else {
            //     $restaurant_file = $url;
            // }
            
            $info = $this->page_info($restaurant_file);
            
            if ($info->url != $url) {
                die('URL Has Changed Into: ' . $info->url);
            }
            
            $logger->debug("------ $info->name Restaurant Start -------");
            
            if ($info) {
                $menu = $this->menu($restaurant_file);
                $this->insert($info);
                $this->food($menu->categories);
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
        
    }
    
?>