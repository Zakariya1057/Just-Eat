<?php

    require_once __DIR__.'/../config/config.php';
    require_once __DIR__.'/logger.php';

    class Database {

        public $connection;
    
        public function __construct(){

            if($this->connection){
                return $this->connection;
            }
            else {
                $this->database_connect();
            }
            
        }
    
        public function database_connect(){
            global $logger,$config;

            $database_config = $config->database;
            $environment = $database_config->environment;
            $credentials = $database_config->$environment;

            if($config->database->environment == 'live'){
                $logger->debug('Connecting To Live Database('.$credentials->host.')');
            }
            else {
                $logger->debug('Connecting To Dev Database('.$credentials->host.')');
            }

            $database_error = $database_config->error;

            for($i =0;$i < $database_error->count;$i++){

                $logger->debug('Attempting To Connect To Database');

                $connection = new mysqli($credentials->host, $credentials->user, $credentials->pass, $credentials->name);

                if($connection->connect_error){
                    $logger->warning('Failed To Connect To Database: '. $connection->connect_error);
                    $logger->warning('Retrying Shortly...');
                    sleep($database_error->wait);
                }
                else {
                    $logger->debug('Successfully Connected To Database');
                    $this->connection = $connection;
                    return $connection;
                }

            }
        

            throw new Exception('Terminating Script. Failed To Connect To Database');
        }

        public function query($sql){

            global $logger;
            
            $results = $this->connection->query($sql);

            if($results){ 
                return $results;
            }

            $logger->critical('MYSQL ERROR:'.mysqli_error($this->connection), array('sql' => $sql));
            throw new Exception("Error: $sql",404);  

        }


    }

?>