<?php

/**
 * WebPlatform MediaWiki Conversion.
 **/

use WebPlatform\Importer\Commands\ImportCommand;
use Symfony\Component\Console\Application;

/**
 * Poor man project loader so we dont need
 * config files for such a small project
 **/

if ($console instanceof Application) {

    // Load all commands here directly
    $console->add(new ImportCommand());

} else {
    throw new \Exception('Did you require lib/loader.php AFTER bootstrapping the application?');
}
