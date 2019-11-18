<?php

    require_once __DIR__.'/../config/config.php';
    require_once __DIR__.'/logger.php';

    class Database {

        public $connection;
    
        public function __construct(){
            global $logger,$config;

            if($this->connection){
                return $this->connection;
            }

            // $config = new config;
            if($config->database->environment == 'live'){
                $database_config = $config->database->live;
            }
            else {
                $database_config = $config->database->dev;
            }

            $this->connection = 'null';
            $this->connection = new mysqli($database_config->host, $database_config->user, $database_config->pass, $database_config->name);
            if ($this->connection->connect_error){
                $logger->critical('Failed To Connect To Database', (array) $database_config);
                throw new Exception("Failed to connect to database");
            } 
            else {
                return $this->connection;
            }
            
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