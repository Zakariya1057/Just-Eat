<?php

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../config/config.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;

global $logger;
$logger = new Logger('logger');

$date = date('d-m-Y');

$city = $config->city;
$city_directory = __DIR__."/../logs/$city";
if(!file_exists($city_directory)){
    mkdir($city_directory);
}

$logs_directory = "$city_directory/$date";
if(!file_exists($logs_directory)){
    mkdir($logs_directory);
}

if($config->development){
    $logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
}

$logger->pushHandler(new StreamHandler("$logs_directory/debug.log", Logger::DEBUG));
$logger->pushHandler(new StreamHandler("$logs_directory/info.log", Logger::INFO));
$logger->pushHandler(new StreamHandler("$logs_directory/error.log", Logger::ERROR));
$logger->pushHandler(new StreamHandler("$logs_directory/critical.log", Logger::CRITICAL));
$logger->pushHandler(new StreamHandler("$logs_directory/alert.log", Logger::ALERT));
$logger->pushHandler(new StreamHandler("$logs_directory/warning.log", Logger::WARNING));
$logger->pushHandler(new StreamHandler("$logs_directory/notice.log", Logger::NOTICE));

//allows you to temporarily add a logger with bubbling disabled if you want to override other configured loggers
$logger->pushHandler(new FirePHPHandler());

?>
