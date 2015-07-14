<?php

/**
 * WebPlatform MediaWiki Conversion.
 *
 * Autoloaded by composer
 **/

// set to run indefinitely if needed
set_time_limit(0);

/* Optional. It’s better to do it in the php.ini file */
date_default_timezone_set('America/Montreal');

$_SERVER['REMOTE_ADDR']='127.0.0.1';

define('DUMP_DIR', '/vagrant/mediawiki-conversion/data/dumps');
