<?php
/**
 * Script to calculate the BENOIT score for all desks and reporters 
 * in the Bangor Daily News newsroom. 
 * 
 * Expected get variables:
 * $period
 *  Month of report in YYYY-MM format. Defaults to previous month.
 * 
 */

require("bdnindex.class.php"); 

/**
 * Establish the dates
 */
$reporting_period = strtotime($_GET["period"]) ? date("Y-m", strtotime($_GET['period'])) : date("Y-m", strtotime("-1 month"));
$file = "bdnindex/" . $reporting_period . '.json';

$time_start = microtime(true);

if(is_readable($file)) {
    $data = json_decode(file_get_contents($file), true);
} else {
    exit('Run the BDN Index before you run this report.');
}

$metrics = array(
    "story_importance" ,
    "total_stories" ,
    "before_publishing_threshold" ,
    "above_high_threshold" ,
    "word_count_low_threshold" ,
    "word_count_high_threshold" ,
    "completion_rate" ,
    "average_time_on_story" ,
    "facebook_total" ,
    "total_time_on_story" ,  
);

?>

<!doctype html>
<html lang = "en">
<head>
    <meta charset = "utf-8">
    <title>BDN Index report for <?php print $reporting_period; ?></title>
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
    if (is_dir("individuals/{$reporting_period}")) {
        if($_GET['clean']) {
            /**
             * Blow it out and remake it if we're blowing out the cache.
             */
            rmdir("individuals/{$reporting_period}");
            mkdir("individuals/{$reporting_period}", 0777);
        }
    } else {
        mkdir("individuals/{$reporting_period}", 0777);
    }

   foreach($data['desks'] as $desk => $reporters) {
       
       if(!is_dir("individuals/{$reporting_period}/$desk")) {
           mkdir("individuals/{$reporting_period}/$desk", 0777);
       }
       
       foreach($reporters as $user_ID => $reporter) {
      
           $scores_data = "";
           $stories_data = "";
           
           /**
            * File structure: individuals/{YYYY-MM}/{desk}/{reporter}
            * 
            * Check if the directory exists.
            * If it does, stop unless cachelevel= 0
            * If it doesn't, make it.
            * Initialize files to write.
            */

            if (!is_dir("individuals/{$reporting_period}/$desk/{$reporter['attributes']['author_name']}")) {
               mkdir("individuals/{$reporting_period}/$desk/{$reporter['attributes']['author_name']}", 0777) 
                 or die('couldnt make directory');
            }
            
            $handle = fopen("individuals/{$reporting_period}/$desk/{$reporter['attributes']['author_name']}/{$reporter['attributes']['author_name']}_{$reporting_period}_SCORES.tsv", "w")
                        or die("Cannot open the file for scores");

           /**
           * Scores sheet
           */
          $scores_data = "metric\t individual value\t individual score\t desk score\r\n";
          
          // foreach($reporter['metrics'] as $metric => $value) {
          foreach($metrics as $metric) {
              $value = format_value($metric, $reporter['metrics'][$metric]);
              $scores_data .= ucwords(str_replace("_", " ", $metric)) ."\t \"".$value."\"\t ".round($reporter['scores'][$metric], 1)."\t ".round($data['desk_data'][$desk]['scores'][$metric], 1)."\r\n";
          } //reporter metrics

              
          //Total score
          $scores_data .= "BDN score\t --\t ". round(calculate_total_score( $metrics, $reporter['scores'] ), 1) . "\t ". round(calculate_total_score($metrics, $data['desk_data'][$desk]['scores']), 1);

          fwrite($handle, $scores_data);
          fclose($handle);
            
          
          /**
           * Stories sheet
           */
          $handle = fopen("individuals/{$reporting_period}/$desk/{$reporter['attributes']['author_name']}/{$reporter['attributes']['author_name']}_{$reporting_period}_STORIES.tsv", "w")
                        or die("Cannot open the file for stories");
          
          $stories_data = "title\t wp_id\t time\t day\t date\t "
          // . " story importance\t total pageviews\t "
          // . "facebook shares\t facebook likes\t facebook comments\t home run?\r\n";
          . "story_importance\t "
          . "word_count\t "
          . "facebook shares\t facebook likes\t facebook comments\t "
          . "pageviews\t "
          . "timeOnPage\t "
          . "exits\t "
          . "avgTimeOnPage\t "
          . "StartReading\t "
          . "ContentBottom\t "
          . "completion_rate\t "
          . "home_run\r\n";
          
          foreach($reporter['stories'] as $wp_id => $story) {
              $home_run = $story['home_run'] ? "Yes" : "No";
              
              $stories_data .=  "\"".addslashes($story['title']) . "\" \t "
                  . $wp_id ."\t "
                  . date("G:i", strtotime($story['date'])) ."\t "
                  . date("l", strtotime($story['date'])) ."\t "
                  . date("Y-m-d", strtotime($story['date'])) ."\t "
                  . $story['story_importance'] ."\t "
                  . $story['word_count'] ."\t "
                  . $story['facebook']['share_count'] ."\t "
                  . $story['facebook']['like_count'] ."\t "
                  . $story['facebook']['comment_count'] ."\t "
                  . $story['pageviews']."\t "
                  . $story['timeOnPage']."\t "
                  . $story['exits']."\t "
                  . $story['avgTimeOnPage']."\t "
                  . $story['StartReading']."\t "
                  . $story['ContentBottom']."\t "
                  . $story['completion_rate']."\t "
                  . $home_run ."\r\n";
              
          } //reporter stories
          
            fwrite($handle, $stories_data);
            fclose($handle);
          
          
       } //reporters
   } // desks

    ?>
    
    Process completed.
        
</body>
</html>

<?php
/**
 * Special rules to display different types of metrics.
 * @todo when this is written, use it for desks as well.
 */
function format_value($metric, $value) {

    if($metric == 'total_time_on_story') {
        return number_format(($value / (60*60)), 1) . " hrs";
    } elseif(stristr($metric, 'time')) {
        return date('i:s', $value);
    } elseif (stristr($metric, 'threshold') || stristr($metric, 'rate')) {
        return round(($value * 100), 1) . "%";
    } elseif (is_float($value)) {
        return number_format(round($value, 1), 1);
    } elseif (stristr($metric, 'hits')) {
        return number_format(($value / 1000), 1) . "K";
    } else {
        return number_format($value);
    }

}

function dpr($value) {
    ?>
    <script>
    console.log('<?php echo $value; ?>');
    </script>
    <?php
}

function calculate_total_score( $metrics, $scores_array ) {

  $total_score = 0;

  foreach( $metrics as $metric )  {
    $total_score += $scores_array[$metric];
  }

  return $total_score;
  // $scores_data .= "BDN score\t --\t ". round(array_sum($reporter['scores']), 1) . "\t ". round(array_sum($data['desk_data'][$desk]['scores']), 1);
}