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
$file = "bdnindex/".$reporting_period . '.json';

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
    "total_time_on_story"
);
?>

<!doctype html>
<html lang = "en">
<head>
    <meta charset = "utf-8">
    <title>BDN index report for <?php print $reporting_period; ?></title>
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
    <table>
        <thead>
            <?php
                $headings = array(
                    "title",
                    "wp_id",
                    "time",
                    "day",
                    "date",
                    "story_importance",
                    "word_count",
                    "facebook shares",
                    "facebook likes",
                    "facebook comments",
                    "pageviews",
                    "timeOnPage",
                    "exits",
                    "avgTimeOnPage",
                    "StartReading",
                    "ContentBottom",
                    "completion_rate",
                    "home_run"
                );

                foreach($headings as $heading) 
                    echo "<td>{$heading}</td>";
            ?>
        </thead>
        <tbody>
        <?php 
            foreach($data['stories'] as $story) {

                ?><tr><?php
                    $home_run = $story['home_run'] ? "Yes" : "No";
                    
                    echo 
                        "<td>".addslashes($story['title']) ."</td>"
                        . "<td>".$wp_id ."</td>"
                        . "<td>".date("G:i", strtotime($story['date'])) ."</td>"
                        . "<td>".date("l", strtotime($story['date'])) ."</td>"
                        . "<td>".date("Y-m-d", strtotime($story['date'])) ."</td>"
                        . "<td>".$story['story_importance'] ."</td>"
                        . "<td>".$story['word_count'] ."</td>"
                        . "<td>".$story['facebook']['share_count'] ."</td>"
                        . "<td>".$story['facebook']['like_count'] ."</td>"
                        . "<td>".$story['facebook']['comment_count'] ."</td>"
                        . "<td>".$story['pageviews']."</td>"
                        . "<td>".$story['timeOnPage']."</td>"
                        . "<td>".$story['exits']."</td>"
                        . "<td>".$story['avgTimeOnPage']."</td>"
                        . "<td>".$story['StartReading']."</td>"
                        . "<td>".$story['ContentBottom']."</td>"
                        . "<td>".$story['completion_rate']."</td>"
                        . "<td>".$home_run ."</td>";

                    ?></tr><?php
                } //foreach stories as story
        ?>
            </tbody>
        </table>
        
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

function readJSON($file) {
   if(is_readable($file) && !($_GET['clean'])) {
       dpr('Data cached');
       return json_decode(file_get_contents($file));
   } else {
        return false;
   }
}