<?php
chdir ('..');
include 'common.inc';
?>
<!DOCTYPE html>
<html>
    <head>
        <title>WebPagetest - Install Check</title>
        <meta http-equiv="charset" content="iso-8859-1">
        <meta name="description" content="Installation check for WebPagetest">
        <meta name="author" content="Patrick Meenan">
        <meta name="robots" content="noindex,nofollow" />
        <style type="text/css">
        ul {
            list-style: none;
            padding:0 2em;
            margin:0;
        }
        li.pass { 
            background:url("../images/check.png") no-repeat 0 0;
            padding-left: 20px; 
        }
        li.fail { 
            background:url("../images/error.png") no-repeat 0 0;
            padding-left: 20px; 
        }
        li.warn { 
            background:url("../images/warning.png") no-repeat 0 0;
            padding-left: 20px; 
        }
        span.pass {
            color: #008600;
            font-weight: bold;
        }
        span.fail {
            color: #a30000;
            font-weight: bold;
        }
        span.warn {
            color: #d4aa01;
            font-weight: bold;
        }
        </style>
    </head>
    <body>
        <h1>WebPagetest Installation Check</h1>
        <h2>PHP</h2><ul>
        <?php CheckPHP(); ?>
        </ul><h2>System Utilities</h2><ul>
        <?php CheckUtils(); ?>
        </ul><h2>Filesystem Permissions</h2><ul>
        <?php CheckFilesystem(); ?>
        </ul><h2>Test Locations</h2><ul>
        <?php CheckLocations(); ?>
        </ul>
        <?php
          //phpinfo();
        ?>
    </body>
</html>
<?php
/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
function ShowCheck($label, $pass, $required = true, $value = null) {
    if ($pass) {
        $str = 'pass';
    } elseif ($required) {
        $str = 'fail';
    } else {
        $str = 'warn';
    }
    if (!isset($value)) {
        if ($pass) {
            $value = 'yes';
        } else {
            $value = 'NO';
        }
    }
    echo "<li class=\"$str\">$label: <span class=\"$str\">$value</span>";
    if (!$pass and !$required) {
        echo ' (optional)';
    }
    echo "</li>";
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
function CheckPHP() {
    global $settings;
    ShowCheck('PHP version at least 5.3', phpversion() >= 5.3, true, phpversion());
    ShowCheck('GD Module Installed', extension_loaded('gd'));
    ShowCheck('zip Module Installed', extension_loaded('zip'));
    ShowCheck('zlib Module Installed', extension_loaded('zlib'));
    ShowCheck('curl Module Installed', extension_loaded('curl'), false);
    ShowCheck('php.ini allow_url_fopen enabled', ini_get('allow_url_fopen'), true);
    ShowCheck('APC Installed', extension_loaded('apc'), false);
    ShowCheck('php.ini upload_max_filesize > 10MB', return_bytes(ini_get('upload_max_filesize')) > 10000000, false, ini_get('upload_max_filesize'));
    ShowCheck('php.ini post_max_size > 10MB', return_bytes(ini_get('post_max_size')) > 10000000, false, ini_get('post_max_size'));
}

function CheckUtils() {
    ShowCheck('ffmpeg Installed (required for video)', CheckFfmpeg());
    ShowCheck('ffmpeg 1.x Installed with fps, scale and decimate filters(required for mobile video)', CheckFfmpegFilters($ffmpegInfo), false, $ffmpegInfo);
    ShowCheck('imagemagick compare Installed (required for mobile video)', CheckCompare(), false);
    ShowCheck('jpegtran Installed (required for JPEG Analysis)', CheckJpegTran(), false);
    ShowCheck('exiftool Installed (required for JPEG Analysis)', CheckExifTool(), false);
    if (array_key_exists('beanstalkd', $settings))
        ShowCheck("beanstalkd responding on {$settings['beanstalkd']} (configured in settings.ini)", CheckBeanstalkd());
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
function CheckFilesystem() {
    ShowCheck('{docroot}/tmp writable', IsWritable('tmp'));
    ShowCheck('{docroot}/results writable', IsWritable('results'));
    ShowCheck('{docroot}/work/jobs writable', IsWritable('work/jobs'));
    ShowCheck('{docroot}/work/video writable', IsWritable('work/video'));
    ShowCheck('{docroot}/logs writable', IsWritable('logs'));
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
function CheckLocations() {
    $locations = parse_ini_file('./settings/locations.ini', true);
    $out = '';
    $video = false;
    foreach($locations['locations'] as $id => $location) {
        if (is_numeric($id)) {
            $info = GetLocationInfo($locations, $location);
            if ($info['video']) {
                $video = true;
            }
            $out .= "<li class=\"{$info['state']}\">{$info['label']}";
            if (count($info['locations'])) {
                $out .= "<ul>";
                foreach($info['locations'] as $loc_name => $loc) {
                    $out .= "<li class=\"{$loc['state']}\">{$loc['label']}";
                    $out .= '</li>';
                }
                $out .= "</ul>";
            }
            $out .= "</li>";
        }
    }
    if ($video) {
        echo '<li class="pass">Video rendering is supported</li></ul><br><ul>';
    } else {
        echo '<li class="fail"><span class="fail">No test agents are configured to render video</span></li></ul><br><ul>';
    }
    echo $out;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
function GetLocationInfo(&$locations, $location) {
    $info = array('state' => 'pass', 'label' => "$location : ", 'video' => false, 'locations' => array());
    if (array_key_exists($location, $locations)) {
        if (array_key_exists('label', $locations[$location])) {
            $info['label'] .= $locations[$location]['label'];
        } else {
            $info['label'] .= '<span class="fail">Label Missing</span>';
            $info['locations'][$loc_name]['state'] = 'fail';
            $info['state'] = 'fail';
        }
        foreach($locations[$location] as $id => $loc_name) {
            if (is_numeric($id)) {
                $info['locations'][$loc_name] = array('state' => 'pass', 'label' => "$loc_name : ");
                if (array_key_exists($loc_name, $locations)) {
                    if (array_key_exists('label', $locations[$loc_name])) {
                        $info['locations'][$loc_name]['label'] .= $locations[$loc_name]['label'];
                    } else {
                        $info['locations'][$loc_name]['label'] .= '<span class="fail">Label Missing</span>';
                        $info['locations'][$loc_name]['state'] = 'fail';
                        $info['state'] = 'fail';
                    }
                    $info['locations'][$loc_name]['label'] .= ' - ';
                    $file = "./tmp/$loc_name.tm";
                    $testerCount = 0;
                    $elapsedCheck = -1;
                    $lock = LockLocation($loc_name);
                    if ($lock) {
                        if (is_file($file)) {
                            $now = time();
                            $testers = json_decode(file_get_contents($file), true);
                            $testerCount = count($testers);
                            if( $testerCount ) {
                                $latest = 0;
                                foreach($testers as $tester) {
                                    if( array_key_exists('updated', $tester) && $tester['updated'] > $latest) {
                                        $latest = $tester['updated'];
                                    }
                                    if (array_key_exists('video', $tester) && $tester['video']) {
                                        $info['video'] = true;
                                    }
                                }
                                if ($latest > 0) {
                                    $elapsedCheck = 0;
                                    if( $now > $latest )
                                        $elapsedCheck = (int)(($now - $latest) / 60);
                                }
                            }
                        }
                        UnlockLocation($lock);
                    }
                    if ($testerCount && $elapsedCheck >= 0) {
                        if ($elapsedCheck < 60) {
                            $info['locations'][$loc_name]['label'] .= "<span class=\"pass\">$testerCount agents connected</span>";
                        } else {
                            $info['locations'][$loc_name]['label'] .= "<span class=\"fail\">$elapsedCheck minutes since last agent connected</span>";
                            $info['locations'][$loc_name]['state'] = 'fail';
                            $info['state'] = 'fail';
                        }
                    } else {
                        $info['locations'][$loc_name]['label'] .= '<span class="fail">No Agents Connected</span>';
                        $info['locations'][$loc_name]['state'] = 'fail';
                        $info['state'] = 'fail';
                    }
                } else {
                    $info['locations'][$loc_name]['label'] .= '<span class="fail">Definition Missing</span>';
                    $info['locations'][$loc_name]['state'] = 'fail';
                    $info['state'] = 'fail';
                }
            }
        }
    } else {
        $info['label'] .= '<span class="fail">Definition Missing</span>';
        $info['state'] = 'fail';
    }
    return $info;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
function IsWritable($dir) {
    $ok = false;
    $dir = './' . $dir;
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    if (is_dir($dir)) {
        $file = "$dir/install_check.dat";
        if (file_put_contents($file, 'wpt')) {
            if (file_get_contents($file) == 'wpt') {
                $ok = true;
            }
        }
        unlink($file);
    }
    return $ok;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
function return_bytes($val) {
    if (!isset($val)) {
        $val = '0';
    }
    $val = trim($val);
    switch (strtolower(substr($val, -1)))
    {
        case 'm': $val = (int)substr($val, 0, -1) * 1048576; break;
        case 'k': $val = (int)substr($val, 0, -1) * 1024; break;
        case 'g': $val = (int)substr($val, 0, -1) * 1073741824; break;
        case 'b':
            switch (strtolower(substr($val, -2, 1)))
            {
                case 'm': $val = (int)substr($val, 0, -2) * 1048576; break;
                case 'k': $val = (int)substr($val, 0, -2) * 1024; break;
                case 'g': $val = (int)substr($val, 0, -2) * 1073741824; break;
                default : break;
            } break;
        default: break;
    }

    return $val;
}

/**
* See if we can talk to beanstalkd
* 
*/
function CheckBeanstalkd() {
    global $settings;
    $ret = false;
    require_once('./lib/beanstalkd/pheanstalk_init.php');
    $pheanstalk = new Pheanstalk_Pheanstalk($settings['beanstalkd']);
    if ($pheanstalk->getConnection()->isServiceListening()) {
        $id = $pheanstalk->putInTube('wpt.installtest', "test");
        $jobStats = $pheanstalk->statsJob($id);
        $tubeStats = $pheanstalk->statsTube('wpt.installtest');
        $job = $pheanstalk->reserveFromTube('wpt.installtest', 0);
        if ($job !== false && $job->getData() == 'test')
            $ret = true;
        $pheanstalk->delete($job);
    }
    return $ret;
}

/**
* Check to make sure ffmpeg is installed and working
* 
*/
function CheckFfmpeg() {
    $ret = false;
    $command = "ffmpeg -version";
    $retStr = exec($command, $output, $result);
    if (count($output)) {
        foreach($output as $line) {
            if (stripos($line, 'ffmpeg ') !== false) {
                $ret = true;
                break;
            }
        }
    }
    return $ret;
}

/**
* Check to make sure ffmpeg is installed and working
* 
*/
function CheckFfmpegFilters(&$info) {
    $ret = false;
    $command = "ffmpeg -version";
    $retStr = exec($command, $output, $result);
    $ver = 'Not Detected';
    if (count($output)) {
      foreach ($output as $line) {
        if (preg_match('/^ffmpeg version (?P<ver>[^ ]+)/i', $line, $matches) && array_key_exists('ver', $matches)) {
          $ver = $matches['ver'];
        }
      }
    }

    $command = "ffmpeg -filters";
    $retStr = exec($command, $output, $result);
    $fps = false;
    $decimate = false;
    $scale = false;
    if (count($output)) {
      foreach ($output as $line) {
        if (!strncmp($line, 'fps ', 4))
          $fps = true;
        if (!strncmp($line, 'scale ', 6))
          $scale = true;
        if (!strncmp($line, 'decimate ', 9))
          $decimate = true;
      }
    }

    if (intval($ver) == 1 && $fps && $scale && $decimate)
      $ret = true;
    $info = $ver;
    if ($fps)
      $info .= ',fps';
    if ($scale)
      $info .= ',scale';
    if ($decimate)
      $info .= ',decimate';

    return $ret;
}

function CheckJpegTran() {
    $ret = false;
    $command = "jpegtran -h";
    $retStr = exec($command, $output, $result);
    if ($result == 1)
      $ret = true;
    return $ret;
}

function CheckExifTool() {
    $ret = false;
    $command = "exiftool";
    $retStr = exec($command, $output, $result);
    if ($result == 0)
      $ret = true;
    return $ret;
}

function CheckCompare() {
    $ret = false;
    $command = "compare -version";
    $retStr = exec($command, $output, $result);
    if ($result == 0)
      $ret = true;
    return $ret;
}
?>
