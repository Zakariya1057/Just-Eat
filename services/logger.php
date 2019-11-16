<?php

require_once __DIR__.'/../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;

global $logger;
$logger = new Logger('logger');

//2019-08-26
$date = date('d-m-Y');

if(!file_exists(__DIR__."/../logs/$date")){
    mkdir(__DIR__."/../logs/$date");
}

$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

$logger->pushHandler(new StreamHandler(__DIR__."/../logs/$date/debug.log", Logger::DEBUG));

$logger->pushHandler(new StreamHandler(__DIR__."/../logs/$date/info.log", Logger::INFO));

$logger->pushHandler(new StreamHandler(__DIR__."/../logs/$date/error.log", Logger::ERROR));

$logger->pushHandler(new StreamHandler(__DIR__."/../logs/$date/critical.log", Logger::CRITICAL));

$logger->pushHandler(new StreamHandler(__DIR__."/../logs/$date/alert.log", Logger::ALERT));

$logger->pushHandler(new StreamHandler(__DIR__."/../logs/$date/warning.log", Logger::WARNING));

$logger->pushHandler(new StreamHandler(__DIR__."/../logs/$date/notice.log", Logger::NOTICE));

//allows you to temporarily add a logger with bubbling disabled if you want to override other configured loggers
$logger->pushHandler(new FirePHPHandler());

?>