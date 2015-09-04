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
 * Send ?action=purge requests to MediaWiki.
 *
 * Purpose of this script is to mimick an authenticated user
 * to go to a page with ?action=purge to make MediaWiki clear the
 * cached generated HTML from Memcached, or what MediaWiki handles with
 * for $wgMainCacheType internally.
 *
 * I’m aware there is an API way to make requests, but this is roughly a
 * work around so we don’t have to manually open web browser tabs and click
 * "refresh" to reload cache.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class RefreshPagesCommand extends AbstractImporterCommand
{
    /** @var WebPlatform\ContentConverter\Converter\ConverterInterface Converter instance */
    protected $converter;

    protected function configure()
    {
        $description = <<<DESCR

                You went through `mediawiki:run` pass 1,2,3 then realized that
                you needed to edit pages, and now you need to clear MediaWiki cache?

                Problem is that there are too many pages to go through?

                That’s what this does.

                This is nothing fancy, let’s emulate we’re a browser and ask as
                an authenticated user to "refresh" the page from standard MediaWiki
                front controller  (i.e. NOT /w/api.php).

                To use:

                    - Login to your wiki
                    - Go to another page on the wiki while logged in
                    - In developer tools, get a to MediaWiki (e.g. /wiki/Main_Page)
                    - Get the value of cookies that ends with (e.g. wpwikiUserID, provided \$wgDBname is set to "wpwiki"):
                       - UserID
                       - UserName
                       - _session
                    - Paste the values in `.env`
                    - Use like described in `mediawiki:run`, at 3rd pass

DESCR;
        $this
            ->setName('mediawiki:refresh-pages')
            ->setDescription($description)
            ->setDefinition(
                [
                    new InputOption('missed', '', InputOption::VALUE_NONE, 'Give XML node indexes of missed conversion so we can run through only them'),
                    new InputOption('max-pages', '', InputOption::VALUE_OPTIONAL, 'Do not make full run, limit to a maximum of pages', 0),
                    new InputOption('resume-at', '', InputOption::VALUE_OPTIONAL, 'Resume run at a specific XML document index number ', 0),
                ]
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        $this->initMediaWikiHelper('purge');

        $xmlSource = $input->getOption('xml-source');
        $listMissed = $input->getOption('missed');

        $maxHops = (int) $input->getOption('max-pages');   // Maximum number of pages we go through

        $resumeAt = (int) $input->getOption('resume-at');

        $this->loadMissed(DATA_DIR.'/missed.yml');

        $output->writeln(sprintf('Sending purge to %s:', $this->apiHelper->getHelperEndpoint()));

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

                $wikiDocument = new MediaWikiDocument($pageNode);
                $normalized_location = $wikiDocument->getName();
                $title = $wikiDocument->getTitle();
                $id = $wikiDocument->getId();

                /**
                 * Handle interruption by telling where to resume work.
                 *
                 * This is useful if job stopped and you want to resume work back at a specific point.
                 */
                if ($counter < $resumeAt) {
                    continue;
                }

                /**
                 * This is when we want only to pass through files described in data/missed.yml
                 *
                 * Much useful if you want to make slow API requests and not run the import again.
                 */
                if ($listMissed === true && !in_array($normalized_location, $this->missed)) {
                    continue;
                }

                $this->documentPurge($wikiDocument);

                try {
                    $purgeCall = $this->apiRequest($title);
                } catch (Exception $e) {
                    $message = 'Had issue with attempt to refresh page from MediaWiki for %s';
                    throw new Exception(sprintf($message, $title), 0, $e);
                }


                if (empty($purgeCall)) {
                    $message = 'Refresh call did not work, we expected a HTML and got nothing, check at %s%s gives from a web browser';
                    throw new Exception(sprintf($message, $this->apiHelper->getHelperEndpoint(), $title));
                }

                $output->writeln(sprintf(' - %d: %s', $id, $title));
            }
        }
    }
}
