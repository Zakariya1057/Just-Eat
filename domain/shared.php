<?php

require_once __DIR__ . '/../services/logger.php';

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

class Shared {

    public function download_page($url,$file){

        global $logger;
        
        $client  = new Client();

        $logger->debug('Download Page: '.$url);

        $crawler = $client->request('GET', $url);
        $html    = $crawler->html();

        // if(is_nan(stripos($html,'<script src="/_Incapsula_Resource?'))){
        //     $logger->error('Captcha Page Found. Trying Again');
        // }

        file_put_contents($file, $html);

        $logger->debug("Saved Page At $file");

        return $file;

    }


    public function crawl_page($file){
        $html    = file_get_contents($file);
        $crawler = new Crawler($html);

        return $crawler;
    }

}

?>