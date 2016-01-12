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
 * 
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

date_default_timezone_set('America/New_York');
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
        if(isset($_GET["debug"]))
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
    
    <h1>BENOIT report for <?php print $reporting_period; ?></h1>
    <table>
        <?php foreach($data->desks as $desk => $authors) : ?>
        
        <thead>
            <tr>
                <td><?php echo strtoupper( $desk ); ?></td>

                <?php $author = reset($authors); //Only need one ?>
                <?php foreach($author['scores'] as $metric => $value) : ?>

                    <td><?php echo ucwords(str_replace("_", " ", $metric)) ;?></td>
                    <td class="score">Score</td>

                <?php endforeach; ?>

                <td class="score benoit-score">BENOIT Score</td>
            </tr>
        </thead>
        <tbody>
            <?php foreach($authors as $author) : ?>
                <tr>
                    <td><?php echo $author['attributes']['author_name']; ?></td>
                    <?php foreach($author['metrics'] as $metric => $value) : ?>
                    
                        <?php $value = format_value($metric, $value); ?>
                        <td class='<?php echo $metric; ?>-metric'><?php echo $value; ?></td>
                        <td class="score"><?php echo round($author['scores'][$metric], 1); ?></td>
                    
                    <?php endforeach; ?>
                        
                    <td class="score benoit-score"><?php echo round(array_sum($author['scores']), 1); ?></td>
                </tr>
            <?php endforeach; ?>
                
            <tr class="desk-data">
                <td>Totals</td>
                <?php foreach($data->desk_data[$desk]['metrics'] as $metric => $value) : ?>
                    <?php $value = format_value($metric, $value); ?>
                    <td class='<?php echo $metric; ?>-metric'><?php echo $value; ?></td>
                    <td class="score"><?php echo round($data->desk_data[$desk]['scores'][$metric], 1); ?></td>
                <?php endforeach; ?>
                <td class="score benoit-score"><?php echo round(array_sum($data->desk_data[$desk]['scores']), 1); ?></td>
            </tr>
            
            <?php for($i = 0; $i < (count($data->desk_data[$desk]['metrics']) * 2) + 2; $i++) : ?>
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
    
    <?php if($_GET["debug"]) {
        echo "<script>";
        echo "var debug = ".json_encode($data).";";
        echo "console.log(debug);"; 
        echo "</script>";
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

