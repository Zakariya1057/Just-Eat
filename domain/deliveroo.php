<?php

require_once __DIR__ . '/../services/matching.php';

use Symfony\Component\DomCrawler\Crawler;
use Goutte\Client;

class Deliveroo {
    
    public $restaurant;

    function __construct($config,$database,$shared)
    {
        $this->config      = $config;
        $this->database    = $database;
        $this->development = $config->development;
        $this->shared      = $shared;
    }

    public function download_restaurant($url,$destination){
        global $logger;

        preg_match('/^https:\/\/deliveroo.co.uk\/menu\/[^\/]+\/[^\/]+\/([^\/]+)$/', $url, $matches);
        
        if (!$matches || sizeof($matches) == 0){
            throw new Exception("Invalid Restaurant URL Provided: $url");
        }

        $restaurant_name = $matches[1];

        $restaurant_file = "$destination/$restaurant_name.html";

        $logger->debug("Downloading Restaurant $restaurant_name");

        return $this->shared->download_page($url,$restaurant_file);
    }

    public function parse_content($data){
        $json = $data->filter('script[data-component-name="MenuIndexApp"]')->eq(0)->text();

        $location = $this->config->directories->debug;
        file_put_contents("$location/real_restaurant.json",$json);
        
        return json_decode($json);
    }

    #Get All Restaurants For City
    public function city_search($city){
        
    }

    public function food_categories($menu){

        $ignore = $this->config->details->ignore;

        $categories_list = array();

        foreach($menu->categories as $category_data){
            if($category_data->top_level){

                preg_match($ignore, $category_data->name, $matches);

                #Skip If categories any of these
                if($matches){
                    continue;
                }
            
                // print_r($category_data);
                
                $category = new data();
                
                $category->id = $category_data->id;
                $category->name = $category_data->name;
                $category->description = $category_data->description;
                $category->foods = array();

                $categories_list[$category->id] = $category;
            }
        }

        foreach($menu->items as $food_data){

            if(array_key_exists($food_data->category_id, $categories_list)){

                $food_category = $categories_list[ $food_data->category_id ];

                $food = new data();
                $food->name = $food_data->name;
                $food->description = $food_data->description;
                $food->price = $food_data->raw_price;
                $food->options = array();
    
                if($food_data->price_unit != '&pound;'){
                    throw new Exception('Unknown Currency: '.$food_data->price_unit);
                }
                
                $food_category->foods[] = $food;

            }

        }

        return array_values($categories_list);
        // print_r($categories_list);

    }

    public function menu($content){
        $menu = $content->menu;
        $this->restaurant->menu = $this->food_categories($menu);
    }

    public function location($restaurant_data){
        
        $location = new data();

        $location->city     = $restaurant_data->city;
        $location->address1 = $restaurant_data->street_address;

        $location->postcode = $this->shared->format_postcode($restaurant_data->post_code);
        $location->area     = $restaurant_data->neighborhood;

        $location->country = $this->config->country;
        $location->county  = $this->config->county;

        //Empty
        $location->longitude = 'NULL';
        $location->latitude  = 'NULL';

        $location->address2 = 'NULL';
        $location->address3 = 'NULL';

        return $location;
    }

    public function info($content){
        global $logger;

        $restaurant_data = $content->restaurant;

        $this->restaurant->online_id = "D".$restaurant_data->id;

        //Set Restaurant Cuisine
        $cuisines = array();
        foreach($restaurant_data->menu->menu_tags as $tag){
            if(strtolower($tag->type) != 'collection' && strtolower($tag->type) != 'offer'){
                $cuisines[] = $tag->name;
            }
        }

        $this->restaurant->cuisines = join(', ',$cuisines);
        
        $this->restaurant->halal = $this->halal_restaurant($this->restaurant->cuisines);
        
        if(!$this->restaurant->halal){
            return;
        }

        $menu_id = $content->menu->id;

        $this->restaurant->logo = "https://f.roocdn.com/images/menus/$menu_id/header-image.jpg?width=100&height=100&auto=webp&format=jpg&fit=crop&v=1559314152";

        $logger->debug($this->restaurant->logo);

        $this->restaurant->name = $restaurant_data->name;
        $this->restaurant->description = $restaurant_data->description;
        $this->restaurant->phone_number = $restaurant_data->phone_numbers;

        //Empty
        $this->restaurant->hours = 'NULL';
        $this->restaurant->hygiene_rating = 'NULL';

        $hygiene_url = $content->hygiene_content->link_href;
        $this->restaurant->hygiene_rating = $this->shared->restaurant_hygiene( $hygiene_url );

        $this->restaurant->overall_rating =$content->rating->value ?? 0;
        $this->restaurant->num_ratings = str_replace('+','',$content->rating->formatted_count ?? 0);



        $this->restaurant->location = $this->location($restaurant_data);

    }

    public function new_restaurant($url){
        //Check if exists in database, check if halal, then download and parse
        global $logger;

        $this->restaurant = new data();

        $this->restaurant->url = $url;

        $destination = $this->config->directories->restaurants;

        $duplicate = $this->shared->duplicate_restaurant($url);
        
        if($duplicate){
            return;
        }

        // echo "$url -> $destination\n";

        $file = $this->download_restaurant($url,$destination);
        
        $data = $this->shared->crawl_page($file);
        $content = $this->parse_content($data);

        $this->info($content);

        if(!$this->restaurant->halal){
            $logger->debug(sprintf('Not Halal Restaurant %s (%s)',$this->restaurant->url, $this->restaurant->cuisines));
            return;
        }
        else {
            $logger->debug( sprintf('Halal Restaurant %s (%s)',$this->restaurant->name, $this->restaurant->cuisines));
        }
        

        $this->menu($content);

        // $file = '/media/zack/TOSHIBA EXT/justeat/services/../resources/deliveroo/Birmingham/restaurants/hot-pan-pizza.html';



        // print_r($this->restaurant->menu);
        $this->shared->restaurant($this->restaurant);
        // $this->shared->delete_restaurant('https://deliveroo.co.uk/menu/birmingham/acocks-green/hot-pan-pizza');

        sleep($this->config->waiting_time->restaurant);

    }



    public function halal_restaurant($cuisine){
        preg_match('/halal/i',$cuisine,$matches);
        return $matches;
    }

    public function search($city){
        global $logger,$city_restaurants;
        //Save and filter through their sitemap

        // $this->new_restaurant('https://deliveroo.co.uk/menu/birmingham/birmingham-city-centre/tortilla-birmingham?day=today&geohash=gcqdteq62xv1&time=ASAP');

        $file_location = $this->config->directories->sitemap . '/sitemap.html';
        $sitemap_url = $this->config->deliveroo_sitemap;

        $city_restaurants = array();

        if( file_exists($file_location) ){
            $logger->debug('Sitemap Found At '.$file_location);
        }
        else {
            $logger->debug('Sitemap Not Found.');

            $logger->debug('Sitemap Download Start');

            $this->shared->download_page($sitemap_url,$file_location);

            $logger->debug('Sitemap Download Complete');
        }

        $crawler = $this->shared->crawl_page($file_location);

        $logger->debug('Crawler Created');

        $crawler->filter('h3')->each(function(Crawler $node, $i){
            global $logger, $city_restaurants;

            $city_name = strtolower($node->text());
            $target_locations = $this->config->locations;

            if($target_locations){

                if(key_exists($city_name, $target_locations)){
                    $logger->debug('Location Found: '.ucwords($city_name));

                    $list = $node->nextAll()->eq(0);

                    global $area_restaurants,$new_area;

                    $area_restaurants = array();

                    $list->children('li a[href]')->each(function(Crawler $node, $i){
                        global $area_restaurants,$new_area;

                        $href = $node->attr('href');
                        $name = $node->text();

                        preg_match('/^\/restaurants/',$href,$matches);

                        if($matches){
                            // echo  $name."\n";
                            $new_area =  $name;
                        }
                        else {
                            $area_restaurants[$new_area][$name] = "https://deliveroo.co.uk$href";
                        }
                        
                    });

                    $city_restaurants[$city_name] = $area_restaurants;
                }

            }
            else {
                $logger->notice('No Locations Specified. Targetting All Cities');
            }

        });

        // print_r($city_restaurants);
        // $this->restaurant_list($city_restaurants);
        return $city_restaurants;

    }
}

?>