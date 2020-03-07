<?php

require_once __DIR__ . '/../services/matching.php';

use Symfony\Component\DomCrawler\Crawler;
use Goutte\Client;

class Deliveroo extends Shared {
    
    public $restaurant;

    function __construct($config,$database)
    {
        $this->config      = $config;
        $this->database    = $database;
        $this->development = $config->development;
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

        return $this->download_page($url,$restaurant_file);
    }

    public function parse_content($data){
        global $logger;
 
        $json = $data->filter('script[data-component-name="MenuIndexApp"]')->eq(0)->text();

        $location = $this->config->directories->debug;
        file_put_contents("$location/real_restaurant.json",$json);
        
        return json_decode($json);
    }

    #Get All Restaurants For City
    public function city_search($city){
        
    }

    public function food_categories($menu){
        global $logger;

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
                
                if($food->price > 0){
                    $food_category->foods[] = $food;
                }
                else {
                    $logger->debug('Ignoring Food Costing £0 '.$food->name);
                }

            }

        }

        //Removing All Categories Without Foods As No Price For Food
        foreach($categories_list as $id => $category){
            if(count($category->foods) == 0){
                $logger->debug('Deleting Empty Category: '.$category->name);
                unset( $categories_list[$id] );
            }
        }

        return array_values($categories_list);

    }

    public function menu($content){
        $menu = $content->menu;
        $this->restaurant->menu = $this->food_categories($menu);
    }

    public function location($restaurant_data){
        
        $location = new data();

        $location->city     = $restaurant_data->city;
        $location->address1 = preg_replace('/,\s?$/','',$restaurant_data->street_address);

        $location->postcode = $this->format_postcode($restaurant_data->post_code);
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

        $this->restaurant->online_id = $restaurant_data->id;

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

        $logger->debug("Logo: ".$this->restaurant->logo);

        $this->restaurant->name = $this->parse_name($restaurant_data->name,$restaurant_data->city);
        $this->restaurant->description = $restaurant_data->description;
        $this->restaurant->phone_number = $restaurant_data->phone_numbers;
        $this->restaurant->site = 'deliveroo';

        //Empty
        $this->restaurant->hours = 'NULL';
        $this->restaurant->hygiene_rating = 'NULL';

        $hygiene_url = $content->hygiene_content->link_href;
        $this->restaurant->hygiene_rating = $this->restaurant_hygiene( $hygiene_url );

        $this->restaurant->overall_rating =$content->rating->value ?? 0;
        $this->restaurant->num_ratings = str_replace('+','',$content->rating->formatted_count ?? 0);

        $this->restaurant->location = $this->location($restaurant_data);

        $this->places($this->restaurant);

        if(!property_exists($this->restaurant, 'name')){
            throw new Exception('No Restaurant Name Found');
        }

    }

    public function new_restaurant($url){
        //Check if exists in database, check if halal, then download and parse
        global $logger;

        $this->restaurant = new data();

        $this->restaurant->url = $url;

        $destination = $this->config->directories->restaurants;

        $duplicate = $this->duplicate_restaurant($url);
        
        if($duplicate){
            return;
        }

        $retry = $this->config->retry->count;
        $wait = $this->config->retry->wait;

        for($i =0; $i <$retry;$i++ ){

            try {

                $file = $this->download_restaurant($url,$destination);
                $data = $this->crawl_page($file);
                $content = $this->parse_content($data);
                break;
            }
            catch (Exception $e) {
                $message = $e->getMessage();
                $logger->error($message);
                $logger->debug('Retrying Restaurant Page Shortly');
                sleep($wait);
            }
        }


        $this->info($content);

        if(!$this->restaurant->halal){
            $logger->debug(sprintf('Not Halal Restaurant %s (%s)',$this->restaurant->url, $this->restaurant->cuisines));
            return;
        }
        else {
            $logger->debug( sprintf('Halal Restaurant %s (%s)',$this->restaurant->name, $this->restaurant->cuisines));
        }
        
        $restaurant_exists_cross = $this->cross_search($this->restaurant);

        if($restaurant_exists_cross){
            $logger->debug('Not Found In JustEat');
        }
        else {
            $logger->debug('Found In JustEat. Skipping');
            return;
        }

        $this->menu($content);

        // $file = '/media/zack/TOSHIBA EXT/justeat/services/../resources/deliveroo/Birmingham/restaurants/hot-pan-pizza.html';



        // print_r($this->restaurant->menu);
        $this->restaurant($this->restaurant);
        // $this->delete_restaurant('https://deliveroo.co.uk/menu/birmingham/acocks-green/hot-pan-pizza');

        sleep($this->config->waiting_time->restaurant);

    }



    public function halal_restaurant($cuisine){
        preg_match('/halal/i',$cuisine,$matches);
        return $matches;
    }

    public function search($city){
        global $logger,$city_restaurants,$area_restaurants;
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

            $this->download_page($sitemap_url,$file_location);

            $logger->debug('Sitemap Download Complete');
        }

        $crawler = $this->crawl_page($file_location);

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
                        global $area_restaurants,$new_area,$logger;

                        $href = $node->attr('href');

                        preg_match('/^\/restaurants/',$href,$matches);

                        if(!$matches){

                            $url =  "https://deliveroo.co.uk$href";

                            $duplicate = $this->duplicate_restaurant($url);
        
                            if(!$duplicate){
                                // $logger->debug('New Possible Restaurant '.$url);
                                $area_restaurants[] = $url;
                            }
                        }

                    });

                    $city_restaurants[$city_name] = $area_restaurants;
                }

            }
            else {
                $logger->notice('No Locations Specified. Targetting All Cities');
            }

        });

        // print_r($area_restaurants);

        return $area_restaurants;

    }

}

?>