<?php

/**
 * WebPlatform MediaWiki Conversion.
 **/

namespace WebPlatform\Importer\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Notes:
 *   - http://ailoo.net/2013/03/stream-a-file-with-streamedresponse-in-symfony/
 *   - http://www.sitepoint.com/command-line-php-using-symfony-console/.
 **/
class ImportCommand extends Command
{
    protected function configure()
    {
        $this->setName('wpd:import')
             ->setDescription('Import data');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $header_style = new OutputFormatterStyle('white', 'black', array('bold'));
        $output->getFormatter()->setStyle('header', $header_style);

        $file_name = DUMP_DIR.'/main.xml';
        $output->writeln(sprintf('<header>Importing from %s</header>', $file_name));

        /*
        $file   = '/tmp/a-large-file.jpg';
        $format = pathinfo($file, PATHINFO_EXTENSION);

        return new StreamedResponse(
            function () use ($file) {
                readfile($file);
            }, 200, array('Content-Type' => 'text/xml')
        );
        */
    }
}
