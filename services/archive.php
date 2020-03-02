<?php

require_once __DIR__.'/logger.php';

function create_directory($directory){
    if (!file_exists($directory)) {
        mkdir($directory, 0777, true);
    }
}

function directory_files($directory){
    return preg_grep('/^\w*(?<!\.)\w/',scandir($directory));
}

function archive_resources($target,$destination){
    global $logger;

    $site_directories = directory_files($target);
    
    preg_match('/(\w+)$/',$target,$matches);
    $old_dirname = $matches[1];

    $logger->debug('Archieve '.ucwords($old_dirname));

    foreach($site_directories as $site){

        $new_name = date('d-m-Y');
        $site_path = "$target/$site";
        // $new_site_path = "$destination/$new_name/$site/$old_dirname/";
        $new_site_path = "$destination/$site/";
        
        // $logger->debug('New Resource Path: '.$old_dirname);
        
        create_directory($new_site_path);

        $resource_directories = directory_files($site_path);
        
        // print_r($site_directories);

        foreach($resource_directories as $resource){

            $resource_path = "$site_path/$resource";

            $new_resource_path = "$new_site_path/$resource";


            if( is_dir($resource_path) ){

                create_directory($new_resource_path);

                $resources = directory_files($resource_path);
                
                foreach($resources as $resource){

                    $asset_path = "$resource_path/$resource";
                    $new_asset_path = "$new_resource_path/$resource";

                    create_directory($new_asset_path);

                    $assets = directory_files($asset_path);

                    foreach($assets as $asset){
                        $file_path = "$asset_path/$asset";
                        $new_file_path = "$new_asset_path/$asset";

                        rename($file_path,$new_file_path);
                    }

                }

            }
            else {

                create_directory($new_site_path);
                
                $logger->debug("$resource_path -> $new_resource_path");

                rename($resource_path, $new_resource_path);

                $logger->debug("Moving $resource_path -> $new_resource_path");

            }


        }


    }

}

?>