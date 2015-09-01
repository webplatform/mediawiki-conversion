<?php

/**
 * WebPlatform MediaWiki Conversion.
 **/

use WebPlatform\Importer\Commands\RefreshPagesCommand;
use WebPlatform\Importer\Commands\CacheWarmerCommand;
use WebPlatform\Importer\Commands\SummaryCommand;
use WebPlatform\Importer\Commands\RunCommand;
use Symfony\Component\Console\Application;
use Dotenv\Dotenv;

$dotenv = new Dotenv(BASE_DIR);
$dotenv->load();
$dotenv->required(['MEDIAWIKI_API_ORIGIN', 'COMMITER_ANONYMOUS_DOMAIN']);

/**
 * Poor man project loader so we dont need
 * config files for such a small project
 **/

if ($console instanceof Application) {

    // Load all commands here directly
    $console->add(new RefreshPagesCommand());
    $console->add(new CacheWarmerCommand());
    $console->add(new SummaryCommand());
    $console->add(new RunCommand());

} else {
    throw new \Exception('Did you require lib/loader.php AFTER bootstrapping the application?');
}
