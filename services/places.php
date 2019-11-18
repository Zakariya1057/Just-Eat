<?php

    require_once __DIR__.'/../config/config.php';
    require_once __DIR__.'/../vendor/autoload.php';
    require_once  __DIR__.'/../data/data.php';
    require_once __DIR__.'/../services/logger.php';

    use \Symfony\Component\HttpClient\HttpClient;

    function places($data){

        global $config,$logger;

        print_r($data);
        
        $client = HttpClient::create();

        $name = $data->name;
        $address = $data->address;
        $postcode = $data->postcode;
        $city = $data->city;

        $api_key = $config->google->api_key;

        //Get Place Id and use that to get details
        $format_address = str_replace(' ','+',"$name,$address,$postcode,$city");
        
        $place_id_response = $client->request('GET', "https://maps.googleapis.com/maps/api/place/findplacefromtext/json?input=$format_address&inputtype=textquery&key=".$api_key);
        $statusCode = $place_id_response->getStatusCode();

        $content = json_decode($place_id_response->getContent());

        if (strtolower($content->status) == 'ok') {

            $place_id = $content->candidates[0]->place_id;

            $logger->debug("Place Found For $name($address)");

        }
        else {
            //Send Error Email or something here, but do continue with the rest
            $data->response = (array)$content;
            $logger->error('Place Not Found',(array)$data);
            return false;
        }

        $response = $client->request('GET', "https://maps.googleapis.com/maps/api/place/details/json?place_id=$place_id&fields=name,rating,formatted_phone_number,opening_hours,geometry&key=".$api_key);
        $data->response = (array)$content;
        // $response = $client->request('GET', "https://maps.googleapis.com/maps/api/geocode/json?address=$format_address&key=".$api_key);

        // $statusCode = $response->getStatusCode();
        $content = json_decode($response->getContent());

        if (strtolower($content->status) == 'ok') {

            if($content->result){
                
                $results = $content->result;

                $place_details = new data();

                $geometry = $results->geometry;
                if($geometry){

                    $location  = $geometry->location;
                    $longitude = $location->lng;
                    $latitude  = $location->lat;
                    
                    $position = new data;
                    $position->longitude = $longitude;
                    $position->latitude  = $latitude;
                    
                    $place_details->location = $position;
                    // return $position;

                }
                else {
                    $logger->error("Geolocation Not Found For Place",(array)$data);
                }

                $opening_hours = $results->opening_hours;
                if($opening_hours){

                    if(count($opening_hours->weekday_text) == 0){
                        $logger->error("Opening Hours, Weekdays Info Empty",(array)$data);
                    }
                    else {

                        $hours = array();

                        foreach($opening_hours->weekday_text as $weekday){
                            preg_match('/^(\w+)\W+(\d+:\d+ \w+)\W+(\d+:\d+ \w+)/',$weekday,$matches);
                            
                            if(!$matches){
                                $logger->error('Opening Hours, Weekdays Format Not Recognised',(array)$data);
                            }
                            
                            $day = $matches[1];
                            $open = date("H:i", strtotime($matches[2]));
                            $close = date("H:i", strtotime($matches[3]));

                            $open_hours = new data();
                            $open_hours->day = $day;
                            $open_hours->open = $open;
                            $open_hours->close = $close;

                            $hours[] = $open_hours;
                        }
                        
                        $place_details->opening_hours = json_encode($hours);
                    }


                }
                else {
                    $logger->error("Opening Hours Not Found For Place",(array)$data);
                }

                $phone_number = $results->formatted_phone_number;
                if($phone_number){
                    $place_details->phone_number = $phone_number;
                }

                if($place_details->opening_hours || $place_details->location){
                    return $place_details;
                }   
                else {
                    $logger->error('No Details Found For Place',(array)$data);
                    return false;
                }

            }
            else {
                $logger->error("Results Missing",(array)$data);
            }

        }
        else {
            $logger->error("Failed To Find Details Of Place Using Place_ID($place_id)",(array)$data);
            return false;
        }

    }

    // $place = new data();
    // $place->name = 'Caspian';
    // $place->address = '37 Horse Fair';
    // $place->city = 'Birmingham';
    // $place->postcode = 'B1 1DA';

    // $location = location($place);
    // print_r($location);

?>