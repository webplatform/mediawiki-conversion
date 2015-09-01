<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */
namespace WebPlatform\Importer\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use WebPlatform\Importer\Model\MediaWikiDocument;
use SimpleXMLElement;
use Exception;

/**
 * Pre-fetch MediaWiki API output to speed up mediawiki:run 3rd pass.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class CacheWarmerCommand extends AbstractImporterCommand
{
    protected function configure()
    {
        $description = <<<DESCR
                Walk through MediaWiki dumpBackup XML file, run each
                document and make an API call to an instance we use
                to migrate content out.

                This script is there to speed up `mediawiki:run` at 3rd pass
                so that it doesn’t need to make HTTP requests and work
                only with local files.

DESCR;
        $this
            ->setName('mediawiki:cache-warmer')
            ->setDescription($description)
            ->setDefinition(
                [
                    new InputOption('missed', '', InputOption::VALUE_NONE, 'Give XML node indexes of missed conversion so we can run through only them'),
                    new InputOption('max-pages', '', InputOption::VALUE_OPTIONAL, 'Do not run full import, limit to a maximum of pages', 0),
                    new InputOption('resume-at', '', InputOption::VALUE_OPTIONAL, 'Resume run at a specific XML document index number ', 0),
                ]
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        $xmlSource = $input->getOption('xml-source');
        $listMissed = $input->getOption('missed');

        $maxHops = (int) $input->getOption('max-pages');   // Maximum number of pages we go through

        $resumeAt = (int) $input->getOption('resume-at');

        $this->loadMissed(DATA_DIR.'/missed.yml');

        $ids = [];

        /*
         * Your MediaWiki API URL
         *
         * https://www.mediawiki.org/wiki/API:Data_formats
         * https://www.mediawiki.org/wiki/API:Parsing_wikitext
         **/
        $apiUrl = getenv('MEDIAWIKI_API_ORIGIN').'/w/api.php?action=parse&pst=1&utf8=';
        $apiUrl .= '&prop=indicators|text|templates|categories|links|displaytitle';
        $apiUrl .= '&disabletoc=true&disablepp=true&disableeditsection=true&preview=true&format=json&page=';
        $this->initMediaWikiHelper($apiUrl);

        $output->writeln('Warming cache:');

        $streamer = $this->sourceXmlStreamFactory(DATA_DIR.'/'.$xmlSource);
        $counter = 0;
        while ($node = $streamer->getNode()) {
            $pageNode = new SimpleXMLElement($node);
            if (isset($pageNode->title)) {
                ++$counter;
                if ($maxHops > 0 && $maxHops === $counter - 1) {
                    $output->writeln(sprintf(PHP_EOL.'Reached desired maximum of %d documents', $maxHops).PHP_EOL);
                    break;
                }

                /*
                 * Handle interruption by telling where to resume work.
                 *
                 * This is useful if job stopped and you want to resume work back at a specific point.
                 */
                if ($counter < $resumeAt) {
                    continue;
                }

                /*
                 * This is when we want only to pass through files described in data/missed.yml
                 *
                 * Much useful if you want to make slow API requests and not run the import again.
                 */
                if ($listMissed === true && !in_array($normalized_location, $this->missed)) {
                    continue;
                }

                $wikiDocument = new MediaWikiDocument($pageNode);
                $normalized_location = $wikiDocument->getName();
                $id = $wikiDocument->getId();

                if (in_array($id, array_keys($ids))) {
                    throw new Exception('What is wrong with you MW!?');
                }

                $ids[$id] = $normalized_location;

                $output->writeln(sprintf('  - %d: %s', $id, $normalized_location));
                $this->fetchDocument($wikiDocument);
            }
        }
    }
}
