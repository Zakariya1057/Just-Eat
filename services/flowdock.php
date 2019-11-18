<?php

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../services/logger.php';
require_once __DIR__.'/../config/config.php';

use \Symfony\Component\HttpClient\HttpClient;

function send_message($content){

    global $config,$logger;

    $client = HttpClient::create();
    $api_key = $config->flowdock->api_key;
    $to = $config->flowdock->to;

    $response = $client->request(
        'POST', 
        "https://$api_key@api.flowdock.com/private/$to/messages",
        [
            'json' => [ 
                'event'   => 'message',
                'content' => $content
            ],
        ]
    );

    $status = $response->getStatusCode();

    if($status == 201){
        $logger->debug("FlowDock Message Successfully Sent");
    }
    else {
        $logger->error("Failed To Send Flowdock Message");
    }

}

?>