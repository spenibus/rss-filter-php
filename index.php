<?php
/*******************************************************************************
rss-filter
version: 20170401-2143
*******************************************************************************/


/******************************************************************************/
error_reporting(~E_NOTICE);
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