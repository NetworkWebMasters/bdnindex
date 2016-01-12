<?php
/**
 * Script to calculate the BENOIT score for all desks and reporters 
 * in the Bangor Daily News newsroom. 
 * 
 * Expected get variables:
 * $period
 *  Month of report in YYYY-MM format. Defaults to previous month.
 * 
 * Optional get variables
 * $cachelevel
 *  defaults to all (4), but can be read as "cache everything but.. "
 *  - desk-data (3) 
 *  - desks (2)
 *  - stories (1)
 *  - none (0)
 */

include_once 'Cache/Lite.php';
if (!class_exists('Cache_Lite')) {
    die("You need to install <a href='http://pear.php.net/manual/en/package.caching.cache-lite.intro.php'>CacheLite</a> to run this script.");
} else {
    //Create a new Cache_Lite object
    $cache = new Cache_Lite($options = array(
        'cacheDir' => '/tmp/',
        'lifeTime' => null, 
        'automaticSerialization' => TRUE
    ));
}

require("benoit.class.php"); 

/**
 * Establish the dates
 */
$reporting_period = strtotime($_GET["period"]) ? date("Y-m", strtotime($_GET['period'])) : date("Y-m", strtotime("-1 month"));

/**
 * Apply our own caching here.
 * This probably should be in its own file ...
 */
if(!isset($_GET['cachelevel']) || $_GET['cachelevel'] == 4 || $_GET['cachelevel'] == 'all') {

    if($data = $cache->get('all', $reporting_period)) {
        if($_GET["debug"])
            echo "Report generated from the cache. \r";
        
    } else {
        
        if($_GET["debug"])
            echo "Cache missed";
        
        $data = new benoitIndex();
        $cache->remove('all', $repoting_period);
        $cache->save($data, 'all', $reporting_period);
    }
} else {
    //Build the report manually.
    
    if($_GET["debug"]) 
        echo "Report build manually. \r";
    
    $data = new benoitIndex($reporting_period);
    $cache->remove('all', $reporting_period);
    $cache->save($data, 'all', $reporting_period);
}
?>

<!doctype html>
<html lang = "en">
<head>
    <meta charset = "utf-8">
    <title>BENOIT report for <?php print $reporting_period; ?></title>
    <style type="text/css">
	    td {
            padding : 10px 5px;
            vertical-align : top;
            margin : 0;
	    }
	    
	    h1 {
            margin: 1em 0;
	    }
	</style>
</head>
<body>
    
   <?php
    /**
     * Take all the reporters' data
     * Create a template for each reporter.
     * Check that a doc hasn't been created for this period and reporter yet
     * If it has, update
     * If it hasnt, create
     */
    if (is_dir("benoit-individuals/{$reporting_period}")) {
        if($_GET['cachelevel'] < 1) {
            /**
             * Blow it out and remake it if we're blowing out the cache.
             */
            rmdir("benoit-individuals/{$reporting_period}");
            mkdir("benoit-individuals/{$reporting_period}");
        }
    } else {
        mkdir("benoit-individuals/{$reporting_period}", 0777);
    }

   foreach($data->desks as $desk => $reporters) {
       
       if(!is_dir("benoit-individuals/{$reporting_period}/$desk")) {
           mkdir("benoit-individuals/{$reporting_period}/$desk", 0777);
       }
       
       foreach($reporters as $user_ID => $reporter) {
      
           
           $scores_data = "";
           $stories_data = "";
           
           /**
            * File structure: benoit-individuals/{YYYY-MM}/{desk}/{reporter}
            * 
            * Check if the directory exists.
            * If it does, stop unless cachelevel= 0
            * If it doesn't, make it.
            * Initialize files to write.
            */

            if (!is_dir("benoit-individuals/{$reporting_period}/$desk/{$reporter['attributes']['author_name']}")) {
               mkdir("benoit-individuals/{$reporting_period}/$desk/{$reporter['attributes']['author_name']}", 0777) 
                 or die('couldnt make directory');
            }
            
            $handle = fopen("benoit-individuals/{$reporting_period}/$desk/{$reporter['attributes']['author_name']}/{$reporter['attributes']['author_name']}_{$reporting_period}_SCORES.tsv", "w")
                        or die("Cannot open the file for scores");

           /**
           * Scores sheet
           */
          $scores_data = "metric\t individual value\t individual score\t desk score\r\n";
          
          foreach($reporter['metrics'] as $metric => $value) {
              $value = format_value($metric, $value);
              $scores_data .= ucwords(str_replace("_", " ", $metric)) ."\t \"".$value."\"\t ".round($reporter['scores'][$metric], 1)."\t ".round($data->desk_data[$desk]['scores'][$metric], 1)."\r\n";
          } //reporter metrics
          
          //Total score
          $scores_data .= "BENOIT score\t --\t ". round(array_sum($reporter['scores']), 1) . "\t ". round(array_sum($data->desk_data[$desk]['scores']), 1);
          
          fwrite($handle, $scores_data);
          fclose($handle);
            
          
          /**
           * Stories sheet
           */
          $handle = fopen("benoit-individuals/{$reporting_period}/$desk/{$reporter['attributes']['author_name']}/{$reporter['attributes']['author_name']}_{$reporting_period}_STORIES.tsv", "w")
                        or die("Cannot open the file for stories");
          
          $stories_data = "title\t wp_id\t time\t day\t date\t story importance\t total pageviews\t "
          . "facebook shares\t facebook likes\t facebook comments\t home run?\r\n";
          
          foreach($reporter['stories'] as $wp_id => $story) {
              $home_run = $story['home_run'] ? "Yes" : "No";
              
              $stories_data .=  "\"".addslashes($story['title']) . "\" \t "
                  . $wp_id ."\t "
                  . date("G:i", strtotime($story['date'])) ."\t "
                  . date("l", strtotime($story['date'])) ."\t "
                  . date("Y-m-d", strtotime($story['date'])) ."\t "
                  . $story['story_importance'] ."\t "
                  . $story['pageviews'] ."\t "
                  . $story['facebook']['share_count'] ."\t "
                  . $story['facebook']['like_count'] ."\t "
                  . $story['facebook']['comment_count'] ."\t "
                  . $home_run ."\r\n";
              
          } //reporter stories
          
            fwrite($handle, $stories_data);
            fclose($handle);
          
          
       } //reporters
   } // desks

    ?>
    
    Process completed.
    
    <?php if($_GET["debug"]) {
        echo "<pre>";
        var_dump($data); 
        echo "</pre>";
    }?>
        
</body>
</html>

<?php

     /**
 * Special rules to display different types of metrics.
 * @todo when this is written, use it for desks as well.
 */
function format_value($metric, $value) {
    if (stristr($metric, 'time')) {
        $value = date('i:s', $value);
    } elseif (stristr($metric, 'threshold')) {
        $value = round(($value * 100), 1) . "%";
    } elseif (is_float($value)) {
        $value = number_format(round($value, 1), 1);
    } elseif (stristr($metric, 'hits')) {
        $value = number_format(($value / 1000), 1) . "K";
    } else {
        $value = number_format($value);
    }
    return $value;
}

