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
date_default_timezone_set('America/New_York');
require("bdnindex.class.php"); 


/**
 * Establish the dates
 */
$reporting_period = strtotime($_GET["period"]) ? date("Y-m", strtotime($_GET['period'])) : date("Y-m", strtotime("-1 month"));
$file = "bdnindex/".$reporting_period . '.json';

if($_GET['b']) {
    $file = "b-data/" . $file;
}

$time_start = microtime(true);

// Is there a done file?
$data = readJSON($file);

/**
 * Look for a started file, since there is no done file.
 * If there is a started file, then read the PID from the started file.
 * Is it still live? Let the user know to wait.
 * Is it not alive? Run the script again (keep saving).
 * Output information to screen about what point we are at in the script.
 */
if(!$data) {
    // Look for a started file.

    $data = new benoitIndex($reporting_period);

    // Create a file to save the data when it's completed.
    $data->cache_date = date("Y-m-d H:i:s");
    file_put_contents($file, json_encode($data));
    dpr('Data written.');
    dpr('File status:' . is_readable($file));
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
    // "above_low_threshold" ,
    // "facebook_comments" ,
    // "facebook_shares" ,
    // "home_runs" ,
    // "total_hits" ,
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
    
    <h1>BDN Index for <?php print $reporting_period; ?></h1>
    <table>
        <?php foreach($data->desks as $desk => $authors) : ?>
        
        <thead>
            <tr>
                <td><?php echo strtoupper( $desk ); ?></td>

                <?php $author = reset($authors); //Only need one ?>
                <?php foreach($metrics as $metric) : ?>

                    <td><?php echo ucwords(str_replace("_", " ", $metric)) ;?></td>
                    <td class="score">Score</td>

                <?php endforeach; ?>

                <td class="score benoit-score">BDN Index Score</td>
            </tr>
        </thead>
        <tbody>
            <?php foreach($authors as $author) : ?>
                <tr>
                    <td><?php echo $author['attributes']['author_name']; ?></td>
                    <?php $author_score = 0; ?>

                    <?php foreach($metrics as $metric) : ?>
                    
                        <?php $value = format_value($metric, $author['metrics'][$metric]); ?>
                        <?php $author_score += $author['scores'][$metric]; ?>
                        <td class='<?php echo $metric; ?>-metric'><?php echo $value; ?></td>
                        <td class="score"><?php echo round($author['scores'][$metric], 1); ?></td>
                    
                    <?php endforeach; ?>
                        
                        <!-- @todo only add metrics we're counting -->
                    <td class="score benoit-score"><?php echo round($author_score, 1); ?></td>
                </tr>
            <?php endforeach; ?>
                
            <tr class="desk-data">
                <td>Totals</td>
                <?php $desk_score = 0; ?>
                <?php foreach($metrics as $metric) : ?>
                    <?php $value = format_value($metric, $data->desk_data[$desk]['metrics'][$metric]); ?>
                    <?php $desk_score += $data->desk_data[$desk]['scores'][$metric]; ?>
                    <td class='<?php echo $metric; ?>-metric'><?php echo $value; ?></td>
                    <td class="score"><?php echo round($data->desk_data[$desk]['scores'][$metric], 1); ?></td>
                <?php endforeach; ?>
                <td class="score benoit-score"><?php echo round($desk_score, 1); ?></td>
            </tr>
            
            <?php for($i = 0; $i < (count($metrics) * 2) + 2; $i++) : ?>
                <td>---</td>
            <?php endfor; ?>
            
        </tbody>
        <?php endforeach; ?>
    </table>
    
    <h2>Home Run List</h2>
    <ul>
        <?php foreach($data->home_runs as $wp_id => $home_run) : ?>
            <li>
                <a href='<?php echo $home_run['permalink']; ?>'>
                    <strong><?php echo $home_run['title']; ?></strong>
                </a>, by <?php echo $home_run['author']; ?> (<?php echo date("D M j", strtotime($home_run['date'])); ?>)
            </li>
        <?php endforeach; ?>
    </ul>

    <script type="text/javascript">
        var debug = <?php echo json_encode($data); ?>;
        console.log(debug);
    </script>

    <?php dpr("Execution time: " . round((microtime(true) - $time_start)/60, 2) ." minutes"); ?>
        
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
   if($_GET['b']) {
       $file = "b-data/" . $file;
   }
   if(is_readable($file) && !($_GET['clean'])) {
       dpr('Data cached');
       return json_decode(file_get_contents($file));
   } else {
        return false;
   }
}

