<?php
/**
 * Script to calculate the BENOIT score for all desks and reporters 
 * in the Bangor Daily News newsroom. 
 * 
 * Expected get variables:
 * $period
 *  Month of report in YYYY-MM format.
 * 
 * Optional get variables
 * $cachelevel
 *  defaults to all (4), but can be read as "cache everything but.. "
 *  - desk-data (3) 
 *  - desks (2)
 *  - stories (1)
 *  - none (0)
 */

class bdnIndex {
    
    const ga_profile_id = '10742869';
    const above_high_threshold = '4000';
    const above_low_threshold = '1000';
    const before_publishing_threshold = '16';
    const word_count_low_threshold = '500';
    const word_count_high_threshold = '1000';
    
    /**
     * These variables are used to initalize the data we need to make queries.
     */
    public $reporting_period = array();
    public $flybys = array();
    
    /**
     * These variables will hold the data.
     * They can be cached using Cache Lite.
     */
    public $desks = array();
    public $stories = array();
    public $home_runs = array();
    public $desk_data = array(); //This is lazy. It should go in desks.
    
    
    function __construct($reporting_period) {
        
        
        // There has to be a better way.
        ini_set('memory_limit', '2048M');
        
        //BDN files
        $this->getUsers();
                
        /**
         * Set up dates
         */
        $this->getDates($reporting_period);
        if(isset($_GET['debug_date'])) {
            $this->reporting_period["start"] = new DateTime(date('Y-m-d', strtotime('3 days ago')));
            $this->reporting_period["end"] = new DateTime(date('Y-m-d', strtotime('2 days ago')));
        }
        
        /**
         * Queries all external sources for data about stories.
         */
        $this->getStoryData();

        /**
         * Add stories to $this->desks
         */
        $this->attributeStories();
                
        /**
         * Now that we have all the data from stories, lets start building
         * out the metrics for these reporters.
         */
        $this->buildMetrics();

        /**
         * Evaluates the scores based on the metrics!
         * 
         * @todo if we're going to change scoring parameters, we might 
         * want to pass arguments to this function here.
         */
        $this->evaluateScores();

    } //__construct

    /**
     * These are the functions where we go out and query for all the
     * info to build $this->stories
     */
    function getStoryData() {
        
        $steps = array(
            // Query for all posts on the website in the reporting period
            'WPData',
            // Query the newsroom site
            'Newsroom',
            // Query Facebook stats
            'FacebookStats',
            // Query Google Analytics
            'GoogleAnalytics'
        );

        // Incrementally move through each query step.
        // Look for cached data.
        // If it isn't here, execute the function, then write the data.
        foreach($steps as $step) {
            $file = 'bdnindex/'.$step.'-'.$this->reporting_period['start']->format('Y-m-d').'.json';

            if($data = $this->readJSON($file, $assoc = true)) {
                $this->stories = $data;
            } else {
                $functionName = 'get'.$step;
                //execute function
                $this->$functionName();
                $this->writeJSON($file, $this->stories);
            }        
        }

        // todo fix the logic that we need to add on completion rate.


        /**
         * Evaluate Home Runs!
         */
        $this->checkHomeRuns(7500, 1000);

    } //buildStoryData()
    
    
    function getUsers( ) {

        if( $_GET['debug'] ) 
            require( 'testusers.php' ); //$authornames, $flybs
        elseif ( $_GET['b'] )
            require( 'b-users.php' );
        else
            require( 'users.php' ); //$authornames, $flybs

        $this->desks = $authornames;
        
        /**
         * Set up the data model we will use later.
         */
        foreach($this->desks as $desk => $authors) {
            foreach($authors as $author_id => $author) {
                $this->desks[$desk][$author_id] = array(
                  'attributes' => array('author_name' => $author),
                  'metrics' => array(),
                  'scores' => array(),
                  'stories' => array(),
                );
            }
            
            if(empty($this->desks[$desk])) {
                unset($this->desks[$desk]); //Avoid division by 0.
            } else {
            
                /*
                 * Build desk data.
                 */
                $this->desk_data[$desk] = array(
                    'metrics' => array(),
                    'scores' => array(),
                ); 
            }
        }
        
        $this->flybys = $flybys;
        /**
         * Remove the flybys now because we certainly don't want their score.
         * @todo in the future, maybe we want to include them for historical reports?
         */

        foreach($this->desks as $desk => $authors) {
            foreach($authors as $author_id => $author) {
                if(array_key_exists($author['attributes']['author_name'], $this->flybys)) {
                    unset($this->desks[$desk][$author_id]);
                }
            }

            /**
             * If there is no one in the desk after we unset all the authors, 
             * then unset the desk.
             */
            if(empty($this->desks[$desk])) {
                unset($this->desks[$desk]);
            }
        }
    }
    
    
    function getDates($reporting_period) {
        //Make sure the reporting period validates as a date
        $reporting_period = strtotime($reporting_period) ? date('Y-m', strtotime($reporting_period)) : date('Y-m'); 
        
        //Create appropriate objects
        $this->reporting_period["start"] = new DateTime(date('Y-m-1', strtotime($reporting_period)));
        $this->reporting_period["end"] = new DateTime(date('Y-m-t 23:59:59', strtotime($reporting_period)));
    }
    
    /**
     * Build the JSON URL.
     *  bangordailynews.com/DATE/page/PAGENUMBER/?author=ARRAY&feed=json
     * CURL the result.
     * Save the results in $this->stories.
     * Iterate through pages until we come to page not found.
     */
    function getWPData() {       

        $this->stories = array();
        
        /**
         * Establish list of user_ids to query
         */
        $user_ids = array();
        
        foreach($this->desks as $desk => $users) {
            foreach($users as $user_id => $user) {
                $authors[$user_id] = $user;
            }
        }
        
        $author_query = "/?author=" . urlencode(implode(",", array_keys($authors)));
        
        /**
         * Iterate through pages and save results to $this->stories.
         */
        $page = 1;
        
        while($page) {

            /**
             * This is still going to pull a whole month of data.
             */
            $jsonURL = "http://bangordailynews.com/" .
                $this->reporting_period['start']->format('Y') . "/" .
                $this->reporting_period['start']->format('m') .
                "/page/" . $page ;

            $params = array(
                'author' => implode(',', array_keys($authors)),
                'feed' => 'json'
            );

            $response = $this->curlRequest( $jsonURL, $params );

            if( $response['body'] && $response['code'] == 200 ) {
                // Consider writing each response to a file so we can resume where we left off.
                $this->stories = array_merge( json_decode($response['body'], true), $this->stories );
                $page ++;
            } elseif( $response['code'] == 404) {
                $page = false; // move on to the next step
            } else {
                //DIE!
                var_dump($response);
                die('We lost connection with bangordailynews.com. Please try your query again.');
            }

        } //while page

        /**
         * Make the WP_ID the key of each story.
         */
        $stories = array(); //placeholder array
        foreach($this->stories as $story_id => $story) {

            /**
             * Add the author name to the story data.
             */
            foreach($authors as $author_id => $author_data) {
                if($story["author_id"] == $author_id) {
                    $story["author"] = $author_data["attributes"]["author_name"];
                }
            }

            /**
             * We only want stories, refers and slideshows, since there isn't
             * much traffic to videos (they are embedded in posts).
             */
            if( in_array($story['posttype'], array('refer', 'post', 'slideshow')) ) {
                if($story["wp_id"] == "") {
                    $story["wp_id"] = "1." . $story["id"];
                }
                
                /**
                 * We're going to get everything in a month,
                 * Make sure that we only save the stories in the reporting period.
                 */
                if( strtotime($story['date']) > $this->reporting_period['start']->getTimestamp() &&
                    strtotime($story['date']) < $this->reporting_period['end']->getTimestamp() ) {
                        $stories[$story['wp_id']] = $story;
                }
            } 
        }
        $this->stories = $stories;
    } //getWPData
    
    
    function getNewsroom() { 
          
        // The newsroom system is only looking at the wpID as an int.
        // We have no data on blogs.
        $story_ids = array_keys($this->stories);

        $simple_ids = array();
        $newsroom_response = array(); //holder to recieve result
        $importances = array();
        
        foreach($story_ids as $id) {
            $pieces = explode(".", $id); // Expecting 1.123456
            if($pieces[0] == 1 && isset($pieces[1])) {
                $simple_ids[] = $pieces[1]; 
            }
        }

        for($i = 0; $i < ( ceil(count($simple_ids) / 20) ); $i++) {
            $simple_ids_slice = array_slice( $simple_ids, $i * 20, 20 );
            
            $url = "http://newsroom.bangordailynews.com/wp-admin/admin-ajax.php";
            $params = array(
                'action' => 'get_stories_by_wpID',
                'wpID' => implode(",", $simple_ids_slice)
            );
            $response = $this->curlRequest( $url, $params );

            $newsroom_response = array_merge($newsroom_response, json_decode( $response['body'] , true));
        }

        $importances = array();
        $word_counts = array();
        foreach($newsroom_response as $story) {
            //Some stories may have multiple IDs.
            //Not all stories that we start with went through newsroom.
            foreach($story['wp_id'] as $wp_id) {
                $importances["1." . $wp_id] = $story['story_importance'];
                $word_counts["1." . $wp_id] = $story['word_count'];
            }
        }
        
        
        foreach($this->stories as $id => $story) {
            if(array_key_exists($id, $importances)) {
                $this->stories[$id]['story_importance'] = (int)$importances[$id];
                $this->stories[$id]['word_count'] = (int)$word_counts[$id];
            } else {
                $this->stories[$id]['story_importance'] = FALSE; //For counting purposes later.
            }
        }
     } //getNewsroom
    
    
    function getFacebookStats() {

       /**
        * Get the permalinks from the stories array.
        * Store in keyed array with wp_id.
        * Then, following getWPStats, chunk urls in 100s and query FB.
        * Then, transfer results to $this->stories 
        */
        $permalinks = array();
        foreach($this->stories as $story_id => $story) {
            $permalinks[$story_id] = $story['permalink'];
        }
        
        //Copy a reverse the permalinks array so we can match them back up 
        //to stories.
        $permalinks_flip = array_flip($permalinks);
        
        for( $i = 0; $i < (ceil(count($permalinks) / 20)); $i++ ) {
            
            $story_urls = array_slice( $permalinks, $i * 20, 20 );

            // $url = 'http://api.ak.facebook.com/restserver.php?v=1.0&method=links.getStats&format=json&urls=' . implode(",", $story_urls);
            $url = 'https://api.facebook.com/method/links.getStats';
            $params = array(
                'urls' => implode(",", $story_urls),
                'format' => 'json'
            );
            $response = $this->curlRequest($url, $params);

            $contents = json_decode( $response['body'], true);
            
            foreach( $contents as $story ) {
                $this->stories[$permalinks_flip[$story['url']]]['facebook'] = array(
                    'share_count' => $story['share_count'],
                    'like_count' => $story['like_count'],
                    'comment_count' => $story['comment_count'],
                    'total_count' => $story['total_count'],
                );
            }
        }  
    } //getFacebookStats

    function getGoogleAnalytics() {

        // Unset the stories with no key because that will break our queries.
        foreach( $this->stories as $wp_id => $story ) {
           if(empty($wp_id)) {
                unset($this->stories[$wp_id]); //will break GAPI query
           }
        }


        // Make sure that the Google APIs Client Library for PHP is in your include_path
        require_once('Google/autoload.php');
        require_once('Google/credentials.php');

        $client = new Google_Client();
        $client->setApplicationName("BDN Index");
        $analytics = new Google_Service_Analytics($client);

        /************************************************
          If we have an access token, we can carry on.
          Otherwise, we'll get one with the help of an
          assertion credential. In other examples the list
          of scopes was managed by the Client, but here
          we have to list them manually. We also supply
          the service account
         ************************************************/
        if(isset($_SESSION['service_token'])) {
            $client->setAccessToken($_SESSION['service_token']);
        }
        $key = file_get_contents(KEY_FILE_LOCATION);
        $cred = new Google_Auth_AssertionCredentials(
            SERVICE_ACCOUNT_NAME,
            'https://www.googleapis.com/auth/analytics',
            $key
        );
        $client->setAssertionCredentials($cred);
        if ($client->getAuth()->isAccessTokenExpired()) {
          $client->getAuth()->refreshTokenWithAssertion($cred);
        }

        // Working page query
        $metrics = 'ga:pageviews,ga:timeOnPage,ga:exits,ga:avgTimeOnPage';
        $optParams = array(
            'dimensions' => 'ga:dimension3',
            'sort' => '-ga:pageviews',
            'filters' => null,
            'samplingLevel' => 'HIGHER_PRECISION',
            'start-index' => 1,
            'max-results' => 1000
        );

        $response = $this->buildGAQuery($analytics, $metrics, $optParams);
        $this->attributeGAData($response, $analytics, $metrics, $optParams);

        //Get all article starts
        $metrics = 'ga:totalEvents';
        $optParams = array(
            'dimensions' => 'ga:eventLabel',
            'filters' => 'ga:eventCategory==Reading;ga:eventAction==StartReading',
            'sort' => '-ga:totalEvents',
            'samplingLevel' => 'HIGHER_PRECISION',
            'start-index' => 1,
            'max-results' => 1000,

        );
        $response = $this->buildGAQuery($analytics, $metrics, $optParams);
        $this->attributeGAData($response, $analytics, $metrics, $optParams, 'StartReading');

        //test what we get on the events query.
        $metrics = 'ga:totalEvents';
        $optParams = array(
            'dimensions' => 'ga:eventLabel',
            'filters' => 'ga:eventCategory==Reading;ga:eventAction==ContentBottom',
            'sort' => '-ga:totalEvents',
            'samplingLevel' => 'HIGHER_PRECISION',
            'start-index' => 1,
            'max-results' => 1000,

        );
        $response = $this->buildGAQuery($analytics, $metrics, $optParams);
        $this->attributeGAData($response, $analytics, $metrics, $optParams, 'ContentBottom');

        // Calculate completion rate
        foreach($this->stories as $wp_id => $story) {
          if( isset($this->stories[$wp_id]['StartReading']) && isset($this->stories[$wp_id]['ContentBottom'])) {
            $this->stories[$wp_id]['completion_rate'] = ($this->stories[$wp_id]['StartReading']) ? $this->stories[$wp_id]['ContentBottom'] / $this->stories[$wp_id]['StartReading'] : 0; //avoid division by 0
          } else {
            $this->dpr('Missing event data for '.$wp_id);
          }
        }
    }//getGoogleAnalytics

    function buildGAQuery($analytics, $metrics, $optParams) {
      // is there a cachefile for this call?
      $file = 'bdnindex/GoogleAnalytics/'.$metrics.'-'.$optParams['filters'].'-'.$optParams['start-index'].'-'.$this->reporting_period['start']->format('Y-m-d').'.json';

      if($data = $this->readJSON($file)) {
          return json_decode($data);
      } else {
          try {
            $response = $analytics->data_ga->get(
                "ga:".self::ga_profile_id,
                $this->reporting_period['start']->format('Y-m-d'),
                $this->reporting_period['end']->format('Y-m-d'),
                $metrics,
                $optParams
            );    
        } catch (Google_Service_Exception $e) {
            // Handle API service exceptions.
            die($e->getMessage());
        }

        $response->columnHeaders = $response->getColumnHeaders();
        $this->writeJSON($file, json_encode($response));
          
      }        
      
      return $response; 
    }

    function attributeGAData($response, $analytics, $metrics, $optParams, $metric_name = false) {

      $IDsToCheck = $this->stories;

      $headers = $response->columnHeaders; 

      //Keeps looking for responses while there are IDs and next pages of results
      while($response) {

          // Update our stories array with data from response.
          foreach($response->rows as $item) {
              //item is an array where 0 is dimension, subsequent numbers are the metrics
              //isset is much faster than array_key_exists
              if(isset($this->stories[$item[0]]) ) {
                  for($i = 1; $i < count($headers); $i++) {
                    $metric =  ($metric_name) ? $metric_name : substr($headers[$i]->name, 3); //strip ga: 
                    $this->stories[$item[0]][$metric] = $item[$i];
                    unset($IDsToCheck[$item[0]]);
                    unset($metric);
                  } //for all headers
              } //if this item is in array 
          } //foreach row

          // If there is more results and there is still no data for a story
          // get the next page
          if(!empty($IDsToCheck) && $response->nextLink) {
              $optParams['start-index'] += $optParams['max-results']; //get the next page
              $response = $this->buildGAQuery($analytics, $metrics, $optParams);
          } else {
              unset($response);
          }
      }

    } //attribute ga data
    
    /**
     * These are stories that are "perfect."
     * It adds these attributes to the story array.
     */
    function checkHomeRuns($pageview_threshold = 1000, $facebook_total = 1000) {
        foreach($this->stories as $story_id => $story) {
            if($story['pageviews'] >= $pageview_threshold && $story['facebook']['total_count'] >= $facebook_total) {
                $this->stories[$story_id]['home_run'] = 1;
                $this->home_runs[$story_id] = $story; //Also add to array of home_runs
            } else {
                $this->stories[$story_id]['home_run'] = 0;
            }
        }
    }
    
    function attributeStories() {
        $author_stories = array(); //temp array to organize stories by author
        
        foreach($this->stories as $story_id => $story) {
            $author_stories[$story['author_id']][$story_id] = $story;
        }

        foreach($this->desks as $desk => $authors) {
            foreach($authors as $author_id => $author) {
                if(is_array($author_stories[$author_id])) {

                    // Add stories to the author.
                    $this->desks[$desk][$author_id]['stories'] = $author_stories[$author_id];
                }
            }
        }

        // Remove authors who wrote nothing from array.
        foreach( $this->desks as $desk=> $authors ) {
            foreach( $authors as $author_id => $author ) {
                if( empty($this->desks[$desk][$author_id]['stories']) ) {
                    unset( $this->desks[$desk][$author_id] );
                }
            }
            /**
             * If there is no one in the desk after we unset the authors, 
             * then unset the desk.
             */
            if(empty($this->desks[$desk])) {
                unset($this->desks[$desk]);
            }
        } //foreach desks
    }
    
    function buildMetrics() {
        /**
         * Story importance
         * Total Hits
         * Above #
         * Above low #
         * Before 4
         * FB Shares
         * FB Comments
         * Home Runs
         * Avg. Time on Story
         * Total score
         */
        foreach($this->desks as $desk => $authors) {
            /*
             * First, we must build these metrics for each author
             */
            foreach($authors as $author_id => $author) {
                /*
                 * Calculate each metric and assign it back to this array.
                 */
                $this->buildStoryImportance($desk, $author_id, $author);
                $this->buildTotalStories($desk, $author_id, $author);
                //pageviews, facebook shares and counts, home runs
                //@todo would it be appropriate to make these parameters in the argument?
                $this->buildWordCounts($desk, $author_id, $author);
                $this->buildCountMetrics($desk, $author_id, $author);
                $this->buildAboveThreshold($desk, $author_id, $author, $thresholds = array('above_high_threshold' => bdnIndex::above_high_threshold, 'above_low_threshold' => bdnIndex::above_low_threshold)); //must be after total stories
                $this->buildBeforePublishingThreshold($desk, $author_id, $author, $time = bdnIndex::before_publishing_threshold);
                $this->buildTimeOnStory($desk, $author_id, $author); 
                $this->buildCompletionRate($desk, $author_id, $author);
                
            }

            /*
             * Now, build the metrics for the desk.
             * 
             * Count metrics:
             *  total_stories, total_hits, facebook_shares, facebook_comments, home_runs
             * 
             * Averages metrics:
             *  above_high_threshold, above_low_threshold, before_publishing_threshold, average_time_on_story
             */
            
            /**
             * The order in which we move through these is very important 
             * to how we anticipate and display the data.
             */
            $metrics = array(
                "above_high_threshold" => "average",
                "above_low_threshold" => "average",
                "average_time_on_story" => "average",
                "before_publishing_threshold" => "average",
                "completion_rate" => "average",
                "facebook_comments" => "count",
                "facebook_shares" => "count",
                "facebook_total" => "count",
                "home_runs" => "count",
                "story_importance" => "average",
                "total_hits" => "count",
                "total_stories" => "count",
                "total_time_on_story" => "count",
                "word_count_high_threshold" => "average",
                "word_count_low_threshold" => "average"
            );

            /**
             * Add all the values of all authors for each metric together.
             */
            foreach($this->desks[$desk] as $author) {
                foreach($metrics as $metric => $metric_type) {
                    $this->desk_data[$desk]['metrics'][$metric] += $author['metrics'][$metric];
                }
            }
            
            /**
             * If the metric is supposed to be an average, divide by total authors.
             */
            foreach($metrics as $metric => $metric_type) {
                if($metric_type == "average") {
                    $this->desk_data[$desk]['metrics'][$metric] = $this->desk_data[$desk]['metrics'][$metric] / count($this->desks[$desk]);
                }
            }
            
        }
    }
    
        /**
         * Functions used by buildMetrics();
         */
    
         /*
          * Adds up all the stories with a story importance field and finds 
          * the average.
          * 
          * @param <string> $desk
          *     Name of desk
          * @param <int> $author_id
          *     Author's User ID
          * @param <array> $author
          *     Array of author properties
          */
         function buildStoryImportance($desk, $author_id, $author) {
             $importances = array(); //holder array
             foreach($author['stories'] as $story) {
                 if($story['story_importance']) {
                     $importances[] = $story['story_importance'];
                 }
             }

             // Avoid division by 0
             if(count($importances) > 0)
                $this->desks[$desk][$author_id]['metrics']['story_importance'] = array_sum($importances) / count($importances);
             else 
                $this->desks[$desk][$author_id]['metrics']['story_importance'] = 0;
         }

         /**
          * @brief Determines the number of stories under 500 words and over 1000 words
          *
          * @param <string> $desk
          *     Name of desk
          * @param <int> $author_id
          *     Author's User ID
          * @param <array> $author
          *     Array of author properties
          */
         function buildWordCounts($desk, $author_id, $author) {
            $word_counts = 0; 
            $under_low_threshold = 0;
            $over_high_threshold = 0;

            foreach( $author['stories'] as $story ) {
                if(isset($story['word_count'])) {
                    $word_counts ++;

                    if($story['word_count'] < self::word_count_low_threshold) 
                        $under_low_threshold ++;
                    elseif($story['word_count'] > self::word_count_high_threshold) 
                        $over_high_threshold ++;
           
                }
            }

            /**
             * @todo should these thresholds be hardcoded? meh.
             */
            $this->desks[$desk][$author_id]['metrics']['word_count_low_threshold'] = ($word_counts > 0 ) ? $under_low_threshold/$word_counts : 0;
            $this->desks[$desk][$author_id]['metrics']['word_count_high_threshold'] = ($word_counts > 0 ) ? $over_high_threshold/$word_counts : 0;


         }
    
         /**
          * Calculates total number of stories for this author
          * 
          * @param <string> $desk
          *     Name of desk
          * @param <int> $author_id
          *     Author's User ID
          * @param <array> $author
          *     Array of author properties
          */
         function buildTotalStories($desk, $author_id, $author) {
             $this->desks[$desk][$author_id]['metrics']['total_stories'] = count($author['stories']);
         }
    
         /**
          * Calculates the total hits for this author.
          * 
          * @param <string> $desk
          *     Name of desk
          * @param <int> $author_id
          *     Author's User ID
          * @param <array> $author
          *     Array of author properties
          */
         function buildCountMetrics($desk, $author_id, $author) {
             $this->desks[$desk][$author_id]['metrics']['total_hits'] = 0;
             $this->desks[$desk][$author_id]['metrics']['facebook_shares'] = 0;
             $this->desks[$desk][$author_id]['metrics']['facebook_comments'] = 0;
             $this->desks[$desk][$author_id]['metrics']['facebook_total'] = 0;
             $this->desks[$desk][$author_id]['metrics']['home_runs'] = 0;
             
             foreach($author['stories'] as $story_id => $story) {
                 $this->desks[$desk][$author_id]['metrics']['total_hits'] += $story['pageviews'];
                 $this->desks[$desk][$author_id]['metrics']['facebook_shares'] += $story['facebook']['share_count'];
                 $this->desks[$desk][$author_id]['metrics']['facebook_comments'] += $story['facebook']['comment_count'];
                 $this->desks[$desk][$author_id]['metrics']['facebook_total'] += $story['facebook']['total_count'];
                 $this->desks[$desk][$author_id]['metrics']['home_runs'] += $story['home_run'];
             }
         }
         
         /**
          * Percent of stories above a certain pageview count.
          * We re-use this for high and low.
          * 
          * @param <string> $desk
          *     Name of desk
          * @param <int> $author_id
          *     Author's User ID
          * @param <array> $author
          *     Array of author properties
          * @param <array> $thresholds
          *     Keyed array of pageview thresholds to check. 
          *     Key is the name of the attribute to build. 
          *     Value is the threshold to meet.
          */
         function buildAboveThreshold($desk, $author_id, $author, $thresholds = array('above_high_threshold' => 3000, 'above_low_threshold' => 1000)) {
             /**
              * Initialize variables
              */
             $count = array();
             foreach($thresholds as $metric => $threshold) {
                 $count[$metric] = 0;
             }

             foreach($author['stories'] as $story_id => $story) { 
                foreach($thresholds as $metric => $threshold) {
                    if($story['pageviews'] >= $threshold) {
                        $count[$metric] ++;
                    }
                 }  
             }
             
             foreach($thresholds as $metric => $threshold) {

                /**
                 * Find percentage by dividing number above high threshold by the total
                 * number of stories. (If statement to avoid division by 0 if no
                 * stories were written).
                 */
                $this->desks[$desk][$author_id]['metrics'][$metric] = 
                        ($this->desks[$desk][$author_id]['metrics']['total_stories'] !== 0) 
                        ? ($count[$metric] / $this->desks[$desk][$author_id]['metrics']['total_stories']) : 0 ;
             }
         }
     
         /**
          * Percent of stories published before a certain time.
          * 
          * @param <string> $desk
          *     Name of desk
          * @param <int> $author_id
          *     Author's User ID
          * @param <array> $author
          *     Array of author properties
          * @param <int> $time
          *     Hour of the day we are benchmarking.
          */
         function buildBeforePublishingThreshold($desk, $author_id, $author, $time = 16) {
//             $this->desks[$desk][$author_id]['metrics']['before_publishing_threshold'] = 0;
             $before_publishing_threshold_count = 0;
             
             foreach($author['stories'] as $story) {
                 if( date('H', strtotime($story['date'])) <= ($time - 1)) {
                     $before_publishing_threshold_count ++;
                 }
             }
             
             /**
             * Find percentage by dividing number above high threshold by the total
             * number of stories. (If statement to avoid division by 0 if no
             * stories were written).
             */
             $this->desks[$desk][$author_id]['metrics']['before_publishing_threshold'] = 
                ($this->desks[$desk][$author_id]['metrics']['total_stories'] !== 0) 
                    ? ($before_publishing_threshold_count / $this->desks[$desk][$author_id]['metrics']['total_stories']) : 0 ;
             
        }
        
         /**
          * Calculates the average time on story and total
          * 
          * @param <string> $desk
          *     Name of desk
          * @param <int> $author_id
          *     Author's User ID
          * @param <array> $author
          *     Array of author properties
          * @param <int> $time
          *     Hour of the day we are benchmarking.
          */
         function buildTimeOnStory($desk, $author_id, $author) {

            //Holder variables
            $totals = array(
                'time' => 0,
                'pageviews' => 0,
                'exits' => 0
            );

            // Add together pageviews, exits and time
            foreach($author['stories'] as $wpID => $story) {
                $totals['time'] += $story['timeOnPage'];
                $totals['pageviews'] += $story['pageviews'];
                $totals['exits'] += $story['exits'];
            }

            // Average time on story only counts pageview minus exits
            $this->desks[$desk][$author_id]['metrics']['average_time_on_story'] = ($c = ($totals['pageviews'] - $totals['exits'])) ? $totals['time']/$c : 0;
            $this->desks[$desk][$author_id]['metrics']['total_time_on_story'] = $totals['time'];

         }

         /**
          * Calculates the completion rate of a story
          * 
          * @param <string> $desk
          *     Name of desk
          * @param <int> $author_id
          *     Author's User ID
          * @param <array> $author
          *     Array of author properties
          * @param <int> $time
          *     Hour of the day we are benchmarking.
          */
         function buildCompletionRate($desk, $author_id, $author) {
            $total_completion_rate = 0;

            foreach($author['stories'] as $wpID => $story) {
                $total_completion_rate += $story['completion_rate'];
            }

            $this->desks[$desk][$author_id]['metrics']['completion_rate'] = ($c = count($author['stories'])) ? $total_completion_rate / $c : 0;
            
         }


     function evaluateScores() {


        // "above_high_threshold" => "average",
        // "above_low_threshold" => "average",
        // "average_time_on_story" => "average",
        // "before_publishing_threshold" => "average",
        // "completion_rate" => "average",
        // "facebook_comments" => "count",
        // "facebook_shares" => "count",
        // "home_runs" => "count",
        // "story_importance" => "average",
        // "total_hits" => "count",
        // "total_stories" => "count",
        // "total_time_on_story" => "count",
        // "word_count_high_threshold" => "average",
        // "word_count_low_threshold" => "average"

         foreach($this->desks as $desk => $authors) {
             foreach($authors as $author_id => $author) {
                 foreach($author['metrics'] as $metric => $value) {
                     switch($metric) {
                         /*
                          * Same as average story importance 
                          */
                         case 'story_importance' :
                             $this->desks[$desk][$author_id]['scores'][$metric] = $value;
                             break;
                         /*
                          * Every 5 stories is worth one point
                          */
                         case 'total_stories' :
                             $this->desks[$desk][$author_id]['scores'][$metric] =
                                 (($value/5) <= 10) ? ($value/5) : 10;
                             break;
                         /*
                          * Every 10K pageviews is worth one point.
                          */
                         case 'total_hits' :
                             $this->desks[$desk][$author_id]['scores'][$metric] =
                                 (($value/10000) <= 10) ? ($value/10000) : 10;
                             break;
                         /*
                          * Every 200 shares is worth one point.
                          */
                         case 'facebook_shares' :
                             $this->desks[$desk][$author_id]['scores'][$metric] =
                                 (($value/200) <= 10) ? ($value/200) : 10;
                             break;
                         /*
                          * Every 200 comments is worth one point
                          */
                         case 'facebook_comments' : 
                              $this->desks[$desk][$author_id]['scores'][$metric] =
                                 (($value/200) <= 10) ? ($value/200) : 10;
                             break;
                         /*
                          * Every 500 interactions is worth one point
                          */
                         case 'facebook_total' : 
                              $this->desks[$desk][$author_id]['scores'][$metric] =
                                 (($r = $value/2000) <= 10) ? $r : 10;
                             break;

                         /*
                          * Every home run is worth 2 points.
                          */
                         case 'home_runs' : 
                              $this->desks[$desk][$author_id]['scores'][$metric] =
                                 (($value * 2) <= 10) ? ($value * 2) : 10;
                             break;
                         /*
                          * Percent as a whole number
                          */
                         case 'above_high_threshold' :
                              $this->desks[$desk][$author_id]['scores'][$metric] =
                                 $value * 10;
                             break;
                         /*
                          * Percent as a whole number.
                          */
                         case 'above_low_threshold' :
                             $this->desks[$desk][$author_id]['scores'][$metric] =
                                 $value * 10;
                             break;
                         /*
                          * Percent as a whole number
                          */
                         case 'before_publishing_threshold' :
                             $this->desks[$desk][$author_id]['scores'][$metric] =
                                 $value * 10;
                             break;
                         /*
                          * Percent as a whole number
                          */
                         case 'completion_rate' :
                             $this->desks[$desk][$author_id]['scores'][$metric] =
                                 $value * 10;
                             break;
                         /*
                          * Percent as a whole number
                          */
                         case 'word_count_low_threshold' :
                             $this->desks[$desk][$author_id]['scores'][$metric] =
                                 $value * 10;
                             break;
                         /*
                          * Percent as a whole number
                          */
                         case 'word_count_high_threshold' :
                             $this->desks[$desk][$author_id]['scores'][$metric] =
                                 $value * 10;
                             break;
                         /*
                          * Every 3.6M seconds (1k hours) is worth 1 point to a max of 10
                          * Metric is in seconds
                          */
                         case 'total_time_on_story' :
                             $measurement = 60 * 60 * 1000; //1K hours

                             $this->desks[$desk][$author_id]['scores'][$metric] =
                                 (($r = ($value / $measurement)) <= 10) ? $r : 10;
                             break;
                         /*
                          * Every 20 seconds is one point.
                          */
                         case 'average_time_on_story' :
                             $this->desks[$desk][$author_id]['scores'][$metric] =
                                 (($value/20) <= 10) ? ($value/20) : 10;
                             break;
                         default :
                              $this->desks[$desk][$author_id]['scores'][$metric] = $value;
                     }
                     
                     /**
                      * Start adding the scores to the desk data; 
                      * we'll divide this number by the total authors to get 
                      * the final score when we're out of this loop.
                      */
                     $this->desk_data[$desk]['scores'][$metric] += $this->desks[$desk][$author_id]['scores'][$metric]; 
                 }
             }
             /**
              * In the earlier loop, we added all the scores together in this array.
              * Now we need to divide the total by the number in the desk to 
              * get the average.
              */
             foreach($this->desk_data[$desk]['scores'] as $metric => $value) {
                  $this->desk_data[$desk]['scores'][$metric] = $value / count($this->desks[$desk]);
             }
         }
     }

     /**
      * Shortcut for outputting data to debug.
      */
     function dpr($data) {
        echo "<script>";
        echo "console.log(".json_encode($data).");";
        echo "</script>";
     }

     
     /**
      * HTTP request using PHP CURL functions
      * Requires curl library installed and configured for PHP
      * 
      * @param Array $get_variables
      * @param Array $post_variables
      * @param Array $headers
      */
     private function curlRequest($url, $get_variables=null, $post_variables=null, $headers=null) {
        $ch = curl_init();
        
        if(is_array($get_variables)) {
          $get_variables = '?' . str_replace('&amp;','&',urldecode(http_build_query($get_variables)));
        } else {
          $get_variables = null;
        }

        curl_setopt($ch, CURLOPT_URL, $url . $get_variables);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); //CURL doesn't like google's cert
        
        if(is_array($post_variables)) {
          curl_setopt($ch, CURLOPT_POST, true);
          curl_setopt($ch, CURLOPT_POSTFIELDS, $post_variables);
        }
        
        if(is_array($headers)) {
          curl_setopt($ch, CURLOPT_HTTPHEADER,$headers);
        }
        
        $response = curl_exec($ch);
        $code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
        
        curl_close($ch);
        
        return array('body'=>$response,'code'=>$code);
     }

     private function readJSON($file, $assoc = false) {
        if($_GET['b']) {
            $file = "b-data/" . $file;
        }
       if(is_readable($file) && !isset($_GET['clean'])) {
           dpr('Data cached from '.$file);
           return json_decode(file_get_contents($file), $assoc);
       } else {
            return false;
        }
    }

    private function writeJSON($file, $data) {
        if($_GET['b']) {
            $file = "b-data/" . $file;
        }
        file_put_contents($file, json_encode($data));
        dpr('Data written to '.$file);
    }
}