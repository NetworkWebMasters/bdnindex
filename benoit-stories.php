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
        
        $data = new benoitIndex($reporting_period);
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
    <title>BENOIT story report for <?php print $reporting_period; ?></title>
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
            <td>Title</td>
            <td>Permalink</td>
            <td>Story Importance</td>
            <td>Pageviews</td>
            <td>Facebook interactions</td>
            <td>Time published</td>
            <td>Home Run?</td>
        </thead>
        <tbody>
        <?php 
            foreach($data->stories as $story) {
                    ?>
                        <tr>
                            <td><?php echo $story['title']; ?></td>
                            <td><?php echo $story['permalink']; ?></td>
                            <td><?php echo $story['story_importance']; ?></td>
                            <td><?php echo $story['pageviews']; ?></td>
                            <td><?php echo $story['facebook']['total_count']; ?></td>
                            <td><?php echo date('H:i', strtotime($story['date'])); ?></td>
                            <td><?php echo $story['home_run']; ?></td>
                        </tr>
                    <?php
                } //foreach stories as story


                 //  ["id"]=>
                 //       int(1755312)
                 //       ["permalink"]=>
                 //       string(124) "http://bangordailynews.com/2014/08/01/business/owls-head-solar-energy-company-supported-by-state-funds-files-for-bankruptcy/"
                 //       ["title"]=>
                 //       string(78) "Owls Head solar energy company, supported by state funds, files for bankruptcy"
                 //       ["date"]=>
                 //       string(20) "August 1, 2014 11:19"
                 //       ["largethumb"]=>
                 //       string(0) ""
                 //       ["smallthumb"]=>
                 //       string(0) ""
                 //       ["posttype"]=>
                 //       string(4) "post"
                 //       ["zoneposition"]=>
                 //       string(0) ""
                 //       ["excerpt"]=>
                 //       string(349) "PORTLAND, Maine — The Owls Head-based solar technology company Ascendant Energy has filed for Chapter 7 bankruptcy, seeking to eliminate more than $780,000 in debt including grants and loans from the Maine Technology Institute and investment from the Wiscasset-based Coastal Enterprises Inc. The company had received nearly $1 million in …
                 // "
                 //       ["author"]=>
                 //       string(0) ""
                 //       ["author_id"]=>
                 //       string(5) "14893"
                 //       ["wp_id"]=>
                 //       string(9) "1.1755312"
                 //       ["overline"]=>
                 //       string(0) ""
                 //       ["story_importance"]=>
                 //       int(8)
                 //       ["pageviews"]=>
                 //       string(4) "2588"
                 //       ["facebook"]=>
                 //       array(4) {
                 //         ["share_count"]=>
                 //         int(8)
                 //         ["like_count"]=>
                 //         int(5)
                 //         ["comment_count"]=>
                 //         int(3)
                 //         ["total_count"]=>
                 //         int(16)
                 //       }
                 //       ["home_run"]=>
                 //       int(0)
                 //     }
        ?>
            </tbody>
        </table>
    
    
    <?php if($_GET["debug"]) {
        echo "<pre>";
        var_dump($data->stories); 
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



/*
<h1>BENOIT story report for <?php print $reporting_period; ?></h1>
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
                </a>, published <?php echo date("D, M j", strtotime($home_run['date'])); ?>
            </li>
        <?php endforeach; ?>
    </ul>
 */