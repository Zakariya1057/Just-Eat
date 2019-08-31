<?php

require_once 'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;

global $logger;
$logger = new Logger('logger');

$logger->pushHandler(new StreamHandler('logs/debug.log', Logger::DEBUG));

$logger->pushHandler(new StreamHandler('logs/info.log', Logger::INFO));

$logger->pushHandler(new StreamHandler('logs/error.log', Logger::ERROR));

$logger->pushHandler(new StreamHandler('logs/critical.log', Logger::CRITICAL));

$logger->pushHandler(new StreamHandler('logs/alert.log', Logger::ALERT));

$logger->pushHandler(new StreamHandler('logs/warning.log', Logger::WARNING));

$logger->pushHandler(new StreamHandler('logs/notice.log', Logger::NOTICE));

//allows you to temporarily add a logger with bubbling disabled if you want to override other configured loggers
$logger->pushHandler(new FirePHPHandler());

?>