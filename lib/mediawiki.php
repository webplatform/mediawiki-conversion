<?php

/**
 * WebPlatform MediaWiki Conversion.
 *
 * Autoloaded by composer
 **/

// set to run indefinitely if needed
set_time_limit(0);
ini_set('memory_limit', '4024M');

// Because we never know if the shell environment we
// run the importer will have LC_ set to an UTF-8
// friendly encoding.
mb_internal_encoding("UTF-8");

/* Optional. It’s better to do it in the php.ini file */
date_default_timezone_set('America/Montreal');

$_SERVER['REMOTE_ADDR']='127.0.0.1';

$wd = realpath(__DIR__ . '/..');
define('BASE_DIR', $wd);
define('DATA_DIR', $wd.'/data');
define('GIT_OUTPUT_DIR', $wd.'/out');
