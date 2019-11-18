<?php

    require_once __DIR__.'/../config/config.php';
    require_once __DIR__.'/../vendor/autoload.php';

    use Aws\S3\S3Client; 

    function save_image($base64,$dir,$filename){
        global $config;
        
        $image = base64_decode($base64);

        $key = $dir."/".$filename;

        $aws = $config->aws;

        //Create a S3Client
        $s3Client = new S3Client([
            'version' => $aws->version,
            'region'  => $aws->region,
            'credentials' => (array)$aws->credentials
        ]);

        $result = $s3Client->putObject([
            'Bucket' => 'zedbite',
            'Key' => $key,
            'Body' => $image,
        ]);

        return $filename;

    }

    // saveImage( base64_encode(file_get_contents('D:\Ampps\www\justeat\resources\Birmingham\logos\5.gif')),'logo','success.gif' );
?>