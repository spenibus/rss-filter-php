<?php
/*******************************************************************************
rss-filter
version: 20210809-1857
*******************************************************************************/


/******************************************************************************/
error_reporting(0);
mb_internal_encoding('UTF-8');


/*********************************************************** load config file */
if(isset($_GET['config'])) {

    require_once('./class/RssFilter.php');
    (new RssFilter($_GET['config']))->displayFeed();
    exit();
}


/******************************************************************** default */
header('content-type: text/plain');
exit(file_get_contents('./README.md'));
?>