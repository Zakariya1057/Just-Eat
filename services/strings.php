<?php

function shorten($string){

    $string = trim(str_replace('<br><br>','<br>',$string));
    // $output =  trim(preg_replace("/\s+/", " ", str_replace('<br>','\n',$string)));
    $string = str_replace('<br>','\n',$string);
    $string = preg_replace('/^\s/m','',$string);
    $string = preg_replace('/\s+/m',' ',$string);
    $string = preg_replace('/\s$/m','',$string);
    $string = trim(preg_replace('/^(?:[\t ]*(?:\r?\n|\r))+/m','',$string));
    $string = preg_replace('/\’/m','\'',$string);
    $string = preg_replace('/\n /m',"\n",$string);

    return htmlentities( html_entity_decode($string),ENT_QUOTES);

}

?>