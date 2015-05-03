<?php
/*******************************************************************************
rss-filter
version: 20150503-2037
*******************************************************************************/




/******************************************************************************/
error_reporting(0);
mb_internal_encoding('UTF-8');




/********************************************************************* config */
$CFG_HTTPS       = $_SERVER['HTTPS'];
$CFG_HOST        = $_SERVER['HTTP_HOST'];
$CFG_SELF        = $_SERVER['SCRIPT_NAME'];
$CFG_REQUEST_URI = $_SERVER['REQUEST_URI'];

$CFG_PROTOCOL_HOST = 'http'.($CFG_HTTPS ? 's' : '').'://'.$CFG_HOST;

$CFG_SELF_FULL        = $CFG_PROTOCOL_HOST.$CFG_SELF;
$CFG_REQUEST_URI_FULL = $CFG_PROTOCOL_HOST.$CFG_REQUEST_URI;

$CFG_DIR_CONFIG = './config/';

$CFG_FETCH_TIMEOUT   = 20;
$CFG_FETCH_MAX_REDIR = 10;

$CFG_KEYWORDS_INDEX = array(
   0 => 'name',
   1 => 'type',
   2 => 'unique',
);

$CFG_KEYWORDS = array(
   /*
   array(name, type, unique)
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
   array('timeout',              1, true),
   array('titleDuplicateRemove', 1, true),
   array('linkDuplicateRemove',  1, true),
   array('rules',                2, false),
   // rules
   array('titleMatch',     1, false),
   array('titleMatchNot',  1, false),
   array('titleMatchMust', 1, false),
   array('before',         1, true),
   array('after',          1, true),
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


   // build config tree (recursive)
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


   return array('config'=>$tree);
}




/******************************************************************************/
function feedsFetch(&$data) {

   global $CFG_FETCH_MAX_REDIR, $CFG_FETCH_TIMEOUT;

   // queue source urls for curl
   // we use a hashmap because array indexes will not survive merging
   foreach($data['config']['ruleSet'] as $rid=>&$ruleset) {

      // temp hashmap storage
      unset($newSourceArray);
      $newSourceArray = array();


      // get timeout value for current ruleset
      $timeout = (int)$ruleset['timeout'];
      if($timeout == 0) {
         $timeout = $CFG_FETCH_TIMEOUT;
      }


      foreach($ruleset['source'] as $sid=>&$source) {

         // hashmap source
         $hashId = sha1($source);
         $newSourceArray[$hashId] = &$source;

         // update structure, keep references to all sources in one array
         $data['source'][$hashId] = &$source;

         // timeout for source
         $data['timeout'][$hashId] = $timeout;
      }

      // replace source array
      $ruleset['source'] = &$newSourceArray;


   }

   // shorthand: urls list
   $urls = &$data['source'];


   // curl handles storage
   $curlHandle = array();


   // curl init
   $curl = curl_multi_init();
   foreach($urls as $id=>$url) {

      $curlHandle[$id] = curl_init($url);
      curl_setopt($curlHandle[$id], CURLOPT_RETURNTRANSFER, true);
      curl_setopt($curlHandle[$id], CURLOPT_HEADER,         true);
      curl_setopt($curlHandle[$id], CURLOPT_TIMEOUT,        $data['timeout'][$id]);
      curl_setopt($curlHandle[$id], CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($curlHandle[$id], CURLOPT_SSL_VERIFYHOST, false);

      // follow redirections (there is a fallback if this cant be enabled)
      curl_setopt($curlHandle[$id], CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($curlHandle[$id], CURLOPT_MAXREDIRS,      $CFG_FETCH_MAX_REDIR);

      curl_multi_add_handle($curl, $curlHandle[$id]);
   }


   // url data storage
   $urlData = array();


   // fallback: redirection count per url
   $redirCount = array();


   // run curl
   $running = 1;
   while($running > 0) {

      // exec
      curl_multi_exec($curl, $running);


      // wait until progress
      curl_multi_select($curl, 1);


      // build list of active handles
      $activeHandles = array();
      while($info = curl_multi_info_read($curl, $m)) {
         $activeHandles[] = $info['handle'];
      }


      // process active handles
      foreach($curlHandle as $id=>$handle) {

         // skip inactive handles
         if(!in_array($handle, $activeHandles)) {
            continue;
         }


         // info
         $info = curl_getinfo($handle);


         // fallback: handle redirection manually
         // curl returned a new location
         // we also have not reached max redirections
         if($info['redirect_url'] && ++$redirCount[$id] <= $CFG_FETCH_MAX_REDIR) {

            // remove handle, update url, add handle back
            curl_multi_remove_handle($curl, $handle);
            curl_setopt($handle, CURLOPT_URL, $info['redirect_url']);
            curl_multi_add_handle($curl, $handle);

            // update running status to trigger next loop iteration
            ++$running;
         }


         // process data
         else {

            // content
            $content = curl_multi_getcontent($handle);

            // build data structure
            $urlData[$id] = array(
               'info'    => $info,
               'headers' => mb_substr($content, 0, $info['header_size']),
               'body'    => mb_substr($content, $info['header_size']),
            );

            // get source encoding from headers
            $charset = null;
            $headers = explode("\n", $urlData[$id]['headers']);
            foreach($headers as $header) {
               preg_match('/content-type:.*charset=(.*)/usi', $header, $m);
               if($m) {
                  $charset = preg_replace('/(^\s*|\s*$)/u', '', $m[1]); // trim
               }
            }
            $charset = $charset ? $charset : 'auto';

            // update structure, store source content and normalize to utf-8
            $data['sourceContent'][$id] = mb_convert_encoding($urlData[$id]['body'], 'utf-8', $charset);

            // remove handle
            curl_multi_remove_handle($curl, $handle);
         }
      }
   }
}




/******************************************************************************/
function feedsParse(&$data) {

   // tags to extract
   $necessary = array('title','link','pubDate');


   foreach($data['config']['ruleSet'] as $rid=>&$ruleset) {

      foreach($ruleset['source'] as $sid=>&$source) {

         // build DOM
         $dom = new DOMDocument();
         $dom->loadXML($data['sourceContent'][$sid]);


         // collect namespaces
         $xp = new DOMXPath($dom);
         $nodes = $xp->query('/rss/namespace::*');
         foreach($nodes as $node) {
            $data['xmlns'][$node->nodeName] = hsc($node->nodeName).'="'.hsc($node->nodeValue).'"';
         }


         // get items
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
            $item['ruleset'] = $rid;
            $item['source']  = $sid;

            // update structure, store items by source
            $data['items'][] = $item;
         }
      }
   }
}




/******************************************************************************/
function itemsFilter(&$data) {

   // sort by pubDate desc before processing
   // this keeps the most recent duplicate if duplicate removal is enabled
   // we also dont have to sort later since all items are present
   itemsSortPubDateDesc($data['items']);


   // to count occurences and check duplicates
   // [ruleset id] [type] [data] = count
   $itemOccurence = array();


   foreach($data['items'] as &$item) {

      // ruleSet shorthand
      $ruleset = $data['config']['ruleSet'][$item['ruleset']];


      // init match status
      $item['match'] = false;


      // count occurence of data by ruleset+type
      // and create shorthands
      $occTitle = ++$itemOccurence[$item['ruleset']] ['title'] [$item['title']];
      $occLink  = ++$itemOccurence[$item['ruleset']] ['link']  [$item['link']];


      // skip duplicate title when option enabled
      if($ruleset['titleDuplicateRemove'] && $occTitle > 1) {
         continue;
      }


      // skip duplicate link when option enabled
      if($ruleset['linkDuplicateRemove'] && $occLink > 1) {
         continue;
      }


      // check item against rules
      foreach($ruleset['rules'] as $rules) {


         // rule: before
         if($rules['before'] && $item['pubDate_timestamp'] > $rules['before']) {
            continue;
         }


         // rule: after
         if($rules['after'] && $item['pubDate_timestamp'] < $rules['after']) {
            continue;
         }


         // rule: titleMatchMust
         if(is_array($rules['titleMatchMust'])) {
            foreach($rules['titleMatchMust'] as $regex) {
               if(!preg_match($regex, $item['title'])) {

                  // skip ruleset
                  continue 2;
               }
            }
         }


         // rule: titleMatchNot
         if(is_array($rules['titleMatchNot'])) {
            foreach($rules['titleMatchNot'] as $regex) {
               if(preg_match($regex, $item['title'])) {

                  // skip ruleset
                  continue 2;
               }
            }
         }


         // rule: titleMatch
         if(is_array($rules['titleMatch'])) {
            $titleMatch = false;
            foreach($rules['titleMatch'] as $regex) {
               if(preg_match($regex, $item['title'])) {
                  $titleMatch = true;
                  break;
               }
            }
            if(!$titleMatch) {
               continue;
            }
         }


         // if we reach this point, the item is a match
         $item['match'] = true;


         // skip remaining rules blocks and jump to next item
         break;
      }
   }
}




/******************************************************************************/
// we count stuff here
function statsBuild(&$data) {

   // shorthand
   $stat = &$data['stat'];

   $stat['sourceCount'] = count($data['source']);
   $stat['itemCount']   = count($data['items']);

   foreach($data['items'] as $item) {

      ++$stat['itemCountBySource'][$item['source']];

      if($item['match']) {
         ++$stat['itemMatch'];
         ++$stat['itemMatchBySource'][$item['source']];
      }
   }
}




/******************************************************************************/
function rssBuild(&$data) {

   global $CFG_REQUEST_URI_FULL;


   // shorthand
   $stat = $data['stat'];


   // description, put stats in there
   $desc = "
matches   items   url
".sprintf('%7s', (int)$stat['itemMatch'])
."   ".sprintf('%5s', (int)$stat['itemCount'])
."   ".(int)$stat['sourceCount']." (total)";

   foreach($data['source'] as $sid=>$url) {
      $desc .= "\n".sprintf('%7s', (int)$stat['itemMatchBySource'][$sid])
         ."   ".sprintf('%5s', (int)$stat['itemCountBySource'][$sid])
         ."   ".hsc($url);
   }


   // items
   $items = '';
   foreach($data['items'] as $item) {
      if($item['match']) {
         $items .= "\n      ".$item['raw'];
      }
   }


   // add xmlns
   $xmlns = "\n   ".implode("\n   ", $data['xmlns']);


   // finalize
   return '<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"'.$xmlns.'>
   <channel>
      <title>rss-filter</title>
      <pubDate>'.hsc(gmdate(DATE_RSS)).'</pubDate>
      <link>'.hsc($CFG_REQUEST_URI_FULL).'</link>
      <description>'.hsc($desc).'</description>'.
      $items.'
   </channel>
</rss>';
}




/*********************************************************** load config file */
if($_GET['config']) {

   $configName = $_GET['config'];

   // build config
   $data = configBuild($configName);

   // download sources
   feedsFetch($data);

   // parse sources
   feedsParse($data);

   // filter items
   itemsFilter($data);

   // generate statistics
   statsBuild($data);

   // get output as rss
   $rss = rssBuild($data);

   // set xml header and output
   header('Content-Type: application/xml; charset=utf-8');
   exit($rss);
}




/******************************************************************** default */
exit('rss-filter<br/><a href="http://spenibus.net">spenibus.net</a>');
?>