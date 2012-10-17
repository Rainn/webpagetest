<?php
require_once('common.inc');
require_once('page_data.inc');

$locations = parse_ini_file('./settings/locations.ini', true);
BuildLocations($locations);

$testQueue = array();

/**
* Get the status of the given test ID (and return the info in an array)
* 
*/
function GetTestStatus($id, $includePosition = true)
{
    global $locations;
    global $testQueue;
    
    $ret = array('statusCode' => 400, 'statusText' => 'Test not found');
    $testPath = './' . GetTestPath($id);
    $test = @parse_ini_file("$testPath/testinfo.ini",true);
    if( isset($test) && isset($test['test']) )
    {
        $ret['testId'] = $id;
        $ret['runs'] = (int)$test['test']['runs'];
        $ret['fvonly'] = (int)$test['test']['fvonly'];
        
        if( isset($test['test']['loc']) )
            $ret['location'] = $test['test']['loc'];

        // see if it is a bulk test
        if( $test['test']['batch'] || $test['test']['batch_locations'] )
            GetBatchStatus($ret);
        else
        {
            // Ignore the cancelled tests.
            if( gz_is_file("$testPath/testinfo.json") )
            {
              $testInfoJson = json_decode(gz_file_get_contents("$testPath/testinfo.json"), true);
              if( isset($testInfoJson['cancelled']) )
              {
                $ret['statusCode'] = 402;
                $ret['statusText'] = 'Test Cancelled';
                return $ret;
              }
            }
            if( isset($test['test']['startTime']) || isset($test['test']['completeTime']) )
            {
                $ret['startTime'] = $test['test']['startTime'];

                if( isset($test['test']['completeTime']) )
                {
                    $ret['statusCode'] = 200;
                    $ret['statusText'] = 'Test Complete';
                    $ret['completeTime'] = $test['test']['completeTime'];
                }
                else
                {
                    $ret['statusCode'] = 100;
                    $now = time();
                    $start = $testInfoJson['started'];
                    $elapsed = 0;
                    if( $now > $start )
                      $elapsed = $now - $start;
                    if( $elapsed == 0 )
                      $ret['statusText'] = "Test just started";
                    elseif( $elapsed == 1 )
                      $ret['statusText'] = "Test Started $elapsed second ago";
                    elseif( $elapsed < 60 )
                      $ret['statusText'] = "Test Started $elapsed seconds ago";
                    else
                    {
                      $elapsed = floor($elapsed / 60);
                      if( $elapsed == 1 )
                        $ret['statusText'] = "Test Started $elapsed minute ago";
                      elseif( $elapsed < 60 )
                        $ret['statusText'] = "Test Started $elapsed minutes ago";
                      else
                        $ret['statusText'] = "Test Started $elapsed minutes ago (probably failed)";
                        
                      // force the test to end if it started but we haven't seen progress in the last 10 minutes
                      if( $elapsed > 10 )
                      {
                          $elapsedUpdate = 100;
                          if( isset($testInfoJson['last_update']) )
                            $elapsedUpdate = ($testInfoJson['last_update'] - $start) / 60;
                          if( $elapsedUpdate > 10 )
                          {
                                // force the test to end
                                $testInfoJson['completed'] = $now;
                                gz_file_put_contents("$testPath/testinfo.json", json_encode($testInfoJson));
                                $test = file_get_contents("$testPath/testinfo.ini");
                                $date = date("m/d/y G:i:s", $now);

                                // update the completion time if it isn't already set
                                if( !strpos($test, 'completeTime') )
                                {
                                    $complete = "[test]\r\ncompleteTime=$date";
                                    $out = str_replace('[test]', $complete, $test);
                                    file_put_contents("$testPath/testinfo.ini", $out);
                                }
                          }
                      }
                    }
                }

                if( $includePosition )
                {
                    $pageData = loadAllPageData($testPath);
                    
                    // count the number of fv and rv tests that have completed
                    $fvRuns = 0;
                    $rvRuns = 0;
                    foreach( $pageData as &$run )
                    {
                        if( isset($run[0]) )
                            $fvRuns++;
                        if( isset($run[1]) )
                            $rvRuns++;
                    }
                    
                    $ret['fvRunsCompleted'] = $fvRuns;
                    $ret['rvRunsCompleted'] = $rvRuns;
                    
                    // TODO: Add actual summary result information
                }
            }
            else
            {
                if( $includePosition )
                {
                    $found = false;
                    $count = 0;
                    
                    // get it's position in line
                    if( isset($test['test']['loc']) )
                    {
                        if( !isset($testQueue[$test['test']['loc']]) )
                        {
                            $workDir = $locations[$test['test']['loc']]['localDir'];
                            
                            // get a list of all of the files in the directory and store them indexed by filetime
                            $files = array();
                            $f = scandir($workDir);
                            foreach( $f as $file )
                            {
                                $fileTime = filemtime("$workDir/$file");
                                if( $fileTime && !isset($files[$fileTime]) )
                                    $files[$fileTime] = $file;
                                else
                                    $files[] = $file;
                            }
                            
                            // sort it by time
                            ksort($files);
                            $testQueue[$test['test']['loc']] = $files;
                        }
                        
                        // loop by priority until I find the test I'm looking for
                        $testId;
                        
                        // loop through all of the possible extension types in priority order
                        $priority = array( "url", "p1", "p2", "p3", "p4", "p5", "p6", "p7", "p8", "p9" );
                        foreach( $priority as $ext )
                        {
                            foreach( $testQueue[$test['test']['loc']] as $file )
                            {
                                $parts = pathinfo($file);
                                if( !strcasecmp( $parts['extension'], $ext) )
                                {
                                    $testId = basename($file, ".$ext");;
                                    if( $testId == $id )
                                    {
                                        $found = true;
                                        break 2;
                                    }
                                    else
                                        $count++;
                                }
                            }
                        }
                    }
                    
                    if( $found )
                    {
                        $ret['statusCode'] = 101;
                        $ret['behindCount'] = $count;
                        if( $count > 1 )
                            $ret['statusText'] = "Waiting behind $count other tests...";
                        elseif( $count == 1 )
                            $ret['statusText'] = "Waiting behind 1 other test...";
                        else
                            $ret['statusText'] = "Waiting at the front of the queue...";
                    }
                    else
                    {
                        $ret['statusCode'] = 401;
                        $ret['statusText'] = 'Test request not found';
                    }
                }
                else
                {
                    $ret['statusCode'] = 101;
                    $ret['statusText'] = 'Test Pending';
                }
            }
        }
    }
    
    return $ret;
}

/**
 * Get the status text for the given test.
 */
function GetTestStatusText($id)
{
    $status = GetTestStatus($id);
    return $status['statusText'];
}

/**
* Check the status of a batch test
* 
* @param mixed $status
*/
function GetBatchStatus(&$status)
{
    $dirty = false;
    $id = $status['testId'];
    $testPath = './' . GetTestPath($id);
    if( gz_is_file("$testPath/bulk.json") )
        $tests = json_decode(gz_file_get_contents("$testPath/bulk.json"), true);
    elseif( gz_is_file("$testPath/tests.json") )
    {
        $legacyData = json_decode(gz_file_get_contents("$testPath/tests.json"), true);
        $tests = array();
        $tests['variations'] = array();
        $tests['urls'] = array();
        foreach( $legacyData as &$legacyTest )
            $tests['urls'][] = array('u' => $legacyTest['url'], 'id' => $legacyTest['id']);
    }

    if( count($tests['urls']) )
    {
        $started = false;
        $allComplete = true;
        $cancelled = false;

        foreach( $tests['urls'] as &$test )
        {
            if( $test['c'] )
                $started = true;
            else
            {
                $complete = true;
                $id = $test['id'];
                $testPath = './' . GetTestPath($id);
                $testInfo = json_decode(gz_file_get_contents("$testPath/testinfo.json"), true);
                if( $testInfo['started'] )
                    $started = true;
                if( $testInfo['cancelled'] )
                    $cancelled = true;
                elseif( !$testInfo['completed'] )
                    $complete = false;

                // go through all of the variations as well
                foreach( $test['v'] as $variationId )
                {
                    $testPath = './' . GetTestPath($variationId);
                    $testInfo = json_decode(gz_file_get_contents("$testPath/testinfo.json"), true);
                    if( $testInfo['started'] )
                        $started = true;
                    if( $testInfo['cancelled'] )
                        $cancelled = true;
                    elseif( !$testInfo['completed'] )
                    {
                        $complete = false;
                        break;
                    }
                }
                
                if( $complete )
                {
                    $test['c'] = 1;
                    $dirty = true;
                }
                else
                    $allComplete = false;
            }
        }
        
        if( $allComplete )
        {
            $status['statusCode'] = 200;
            $status['statusText'] = 'Test Complete';
        }
        elseif( $cancelled )
        {
            $status['statusCode'] = 402;
            $status['statusText'] = 'Test Cancelled';
        }
        elseif( $started )
        {
            $status['statusCode'] = 100;
            $status['statusText'] = 'Test Started';
        }
        else
        {
            $status['statusCode'] = 101;
            $status['statusText'] = 'Test Pending';
        }
        
        // rewrite the bulk file if it changed
        if( $dirty )
            gz_file_put_contents("$testPath/bulk.json", json_encode($tests));
    }
}

?>