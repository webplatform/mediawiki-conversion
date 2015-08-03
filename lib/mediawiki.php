<?php

/**
 * WebPlatform MediaWiki Conversion.
 *
 * Autoloaded by composer
 **/

// set to run indefinitely if needed
set_time_limit(0);
ini_set('memory_limit', '4024M');

/* Optional. It’s better to do it in the php.ini file */
date_default_timezone_set('America/Montreal');

$_SERVER['REMOTE_ADDR']='127.0.0.1';

// Set to your own, or use https://github.com/wikimedia/mediawiki-vagrant
define('MEDIAWIKI_API_ORIGIN', 'http://127.0.0.1:8080');
define('COMMITER_ANONYMOUS_DOMAIN', 'example.org');

$wd = realpath(__DIR__ . '/..');
define('DATA_DIR', $wd.'/data');
define('GIT_OUTPUT_DIR', $wd.'/out');
