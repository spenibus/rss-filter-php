<?php
/*******************************************************************************
rss-filter
creation: 2014-11-26 08:04 +0000
  update: 2014-11-27 14:55 +0000
*******************************************************************************/




/******************************************************************************/
error_reporting(!E_ALL);
mb_internal_encoding('UTF-8');




/********************************************************************* config */
$CFG_HTTPS       = $_SERVER['HTTPS'];
$CFG_HOST        = $_SERVER['HTTP_HOST'];
$CFG_SELF        = $_SERVER['SCRIPT_NAME'];
$CFG_REQUEST_URI = $_SERVER['REQUEST_URI'];

$CFG_PROTOCOL_HOST = 'http'.($CFG_HTTPS ? 's' : '').'://'.$CFG_HOST;

$CFG_SELF_FULL        = $CFG_PROTOCOL_HOST.$CFG_SELF;
$CFG_REQUEST_URI_FULL = $CFG_PROTOCOL_HOST.$CFG_REQUEST_URI;

$CFG_URL_DOWNLOAD = $CFG_SELF_FULL.'?download=';

$CFG_DIR_CONFIG   = './config/';
$CFG_DIR_DOWNLOAD = './download/';

$CFG_KEYWORDS_INDEX = array(
   0 => 'name',
   1 => 'type',
   2 => 'unique',
);

$CFG_KEYWORDS = array(
   /*
   array('name', 'type', unique)
      0   name     str    *
      1   type     int    1 data
                          2 container
      2   unique   bool   true
                          false
   */
   // root
   array('config', 2, true),
   // config
   array('ruleSet', 2, false),
   // ruleSet
   array('source',               1, false),
   array('titleDuplicateRemove', 1, true),
   array('linkDuplicateRemove',  1, true),
   array('rules',                2, false),
   // rules
   array('titleMatch',    1, false),
   array('titleMatchNot', 1, false),
   array('before',        1, true),
   array('after',         1, true),
);

$CFG_KEYWORDS_CALLBACK['before'] = function($data) {
   return strtotime($data);
};

$CFG_KEYWORDS_CALLBACK['after'] = function($data) {
   return strtotime($data);
};




/******************************************************************************/
function hsc($str) {
   return htmlspecialchars($str, ENT_COMPAT, 'UTF-8');
}




/******************************************************************************/
function itemsSortPubDateDesc(&$array) {
   usort($array, function($a, $b) {
      return $b['pubDate_timestamp'] - $a['pubDate_timestamp'];
   });
}




/******************************************************************************/
function configBuild($fn) {

   global $CFG_DIR_CONFIG;

   libxml_use_internal_errors(true);


   // load keywords
   $keywords = call_user_func(function() {
      global $CFG_KEYWORDS_INDEX, $CFG_KEYWORDS;
      $output = array();
      foreach($CFG_KEYWORDS as $kw) {
         $name = $kw[0];
         foreach($kw as $i=>$v){
            $output[$name][$CFG_KEYWORDS_INDEX[$i]] = $v;
         }
      }
      return $output;
   });


   // requested config file path
   $fp = $CFG_DIR_CONFIG.$fn.'.xml';
   $fc = file_get_contents($fp);

   // build DOM
   $dom = new DOMDocument();
   $dom->loadXML($fc);
   $dom = $dom->documentElement;

   // build config tree
   $treeMaker = function($node, $kw, $self) {

      global $CFG_KEYWORDS_CALLBACK;

      $data = array();

      foreach($node->childNodes as $childNode) {

         // skip non element
         if($childNode->nodeType != 1) {
            continue;
         }

         $name  = $childNode->nodeName;
         $value = $childNode->nodeValue;

         // container element, go deeper
         if($kw[$name]['type'] == 2) {

            $tmp = $self($childNode, $kw, $self);

            // ignore empty branch
            if(count($tmp) > 0) {
               $data[$name][] = $tmp;
            }
         }
         // data element, get value
         elseif($kw[$name]['type'] == 1) {

            // ignore empty parameter
            if(mb_strlen($value) === 0) {
               continue;
            }

            // run callback on data if it exists
            if($CFG_KEYWORDS_CALLBACK[$name]) {
               $value = $CFG_KEYWORDS_CALLBACK[$name]($value);
            }

            // unique: keyword can have only one value
            if($kw[$name]['unique'] == true) {
               $data[$name] = $value;
            }
            // multi value keyword
            else {
               $data[$name][] = $value;
            }
         }
      }
      return $data;
   };
   $tree = $treeMaker($dom, $keywords, $treeMaker);


   return $tree;
}




/******************************************************************************/
function feedsFetch(&$data) {

   global $CFG_DIR_DOWNLOAD, $CFG_URL_DOWNLOAD;

   foreach($data['ruleSet'] as $rid=>&$ruleset) {
      foreach($ruleset['source'] as &$source) {

         // create dynamic hash to identify source
         $hash = sha1(microtime(true).$source);

         // proxy url
         $url = $CFG_URL_DOWNLOAD.$hash;

         // put url in file
         file_put_contents($CFG_DIR_DOWNLOAD.$hash, $source);

         // update structure
         $source = array(
            'baseUrl' => $source,
            'hash'    => $hash,
            'url'     => $url,
         );

         // curl url queue, keep reference to config
         $urls[$hash] = &$source;
      }
   }


   // curl
   $curl = curl_multi_init();
   foreach($urls as $hash=>$urlData) {
      $curlHandle[$hash] = curl_init($urlData['url']);
      curl_setopt($curlHandle[$hash], CURLOPT_RETURNTRANSFER, true);
      curl_setopt($curlHandle[$hash], CURLOPT_HEADER, true);
      curl_setopt($curlHandle[$hash], CURLOPT_TIMEOUT, 20);
      curl_setopt($curlHandle[$hash], CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($curlHandle[$hash], CURLOPT_SSL_VERIFYHOST, false);
      curl_multi_add_handle($curl, $curlHandle[$hash]);
   }


   $running = 1;
   while($running > 0) {
       curl_multi_exec($curl, $running);
       usleep(100000); // spare the cpu
   }


   foreach($curlHandle as $hash=>$handle) {

      $content = curl_multi_getcontent($handle);

      # separate headers and body
      preg_match('/^(.*?)\r\n\r\n(.*)$/usi', $content, $m);
      $headers = $m[1];
      $content = $m[2];

      # headers: get charset
      $m = null;

      $headers = explode("\n", $headers);
      foreach($headers as $header) {
         if(preg_match('/^content-type:.*?charset=(.*)/usi', $header, $m)) {
            $charset = preg_replace('/(^\s*|\s*$)/u', '', $m[1]); // trim
         }
      }
      $charset = $charset ? $charset : 'auto';

      $urls[$hash]['raw'] = mb_convert_encoding($content, 'utf-8', $charset);

      curl_multi_remove_handle($curl, $handle);
   }
}




/******************************************************************************/
function feedsParse(&$data) {

   $necessary = array('title','link','pubDate');

   foreach($data['ruleSet'] as $rid=>&$ruleset) {
      foreach($ruleset['source'] as &$source) {

         // build DOM
         $dom = new DOMDocument();
         $dom->loadXML($source['raw']);

         $nodes = $dom->getElementsByTagName('item');
         foreach($nodes as $node) {

            // init
            $item = array(
               'raw' => $dom->saveXML($node),
            );

            foreach($node->childNodes as $childNode) {

               $name  = $childNode->nodeName;
               $value = $childNode->nodeValue;

               // collect only necessary nodes
               if(in_array($name, $necessary)) {
                  $item[$name] = $value;
               }
            }

            // pubDate timestamp
            $item['pubDate_timestamp'] = strtotime($item['pubDate']);

            // update structure, merge items within ruleset
            $ruleset['items'][] = $item;
         }
      }
   }
}




/******************************************************************************/
function itemsFilter(&$data) {

   $output = array();

   foreach($data['ruleSet'] as $ruleset) {

      // title duplicate reference list for current ruleset
      $titleDupe = array();

      // link duplicate reference list for current ruleset
      $linkDupe = array();

      // sort by pubDate desc before processing to keep most recent duplicate if flags enabled
      itemsSortPubDateDesc($ruleset['items']);

      foreach($ruleset['items'] as $item) {

         // skip duplicate title when flag enabled
         if($ruleset['titleDuplicateRemove'] && $titleDupe[$item['title']]) {
            continue;
         }

         // set title duplicate flag
         $titleDupe[$item['title']] = true;

         // skip duplicate link when flag enabled
         if($ruleset['linkDuplicateRemove'] && $linkDupe[$item['link']]) {
            continue;
         }

         // set link duplicate flag
         $linkDupe[$item['link']] = true;

         // item has passed the rules
         $itemRulesPass = 0;

         // check item against rules
         foreach($ruleset['rules'] as $rules) {


            // before
            if($rules['before'] && $item['pubDate_timestamp'] > $rules['before']) {
               continue;
            }


            // after
            if($rules['after'] && $item['pubDate_timestamp'] < $rules['after']) {
               continue;
            }


            // titleMatch
            $titleMatch = false;
            foreach((array)$rules['titleMatch'] as $regex) {
               if(preg_match($regex, $item['title'])) {
                  $titleMatch = true;
                  break;
               }
            }
            if($titleMatch == false) {
               continue;
            }


            // titleMatchNot
            foreach((array)$rules['titleMatchNot'] as $regex) {
               if(preg_match($regex, $item['title'])) {
                  continue 2;
               }
            }


            // if we reach this point, the item has passed the rules
            ++$itemRulesPass;
         }


         // item has passed, add to output
         if($itemRulesPass > 0) {
            $output[] = $item;
         }
      }
   }


   // sort output by pubDate desc
   itemsSortPubDateDesc($output);


   // update structure
   $data['output'] = $output;
}




/******************************************************************************/
function rssBuild(&$data) {

   global $CFG_REQUEST_URI_FULL;

   $output = '';

   foreach($data['output'] as $item) {
      $output .= $item['raw'];
   }


   return '<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
   <channel>
      <title>rss-filter</title>
      <pubDate>'.hsc(gmdate(DATE_RSS)).'</pubDate>
      <link>'.hsc($CFG_REQUEST_URI_FULL).'</link>'.
      $output.'
   </channel>
</rss>';
}




/******************************************************************* download */
// this is a proxy to bypass the curl location follow issue with open_basedir
// file_get_contents() follows redirections, so we call ourselves via curl
if($_GET['download']) {

   $hash = $_GET['download'];

   // check id format
   if(!preg_match('/^[0-9a-z]{40}$/i', $hash)) {
      exit();
   }

   $fp = $CFG_DIR_DOWNLOAD.$hash;

   $url = file_get_contents($fp);

   unlink($fp);

   $data = file_get_contents($url);

   exit($data);
}




/*********************************************************** load config file */
elseif($_GET['config']) {

   $configName = $_GET['config'];

   // build config
   $data = configBuild($configName);

   // download sources
   feedsFetch($data);

   // parse sources
   feedsParse($data);

   // filter items
   itemsFilter($data);

   // get output as rss
   $rss = rssBuild($data);


   // set xml header and output
   header('Content-Type: application/xml; charset=utf-8');
   exit($rss);
}




/******************************************************************** default */
exit('rss-filter<br/><a href="http://spenibus.net">spenibus.net</a>');
?>