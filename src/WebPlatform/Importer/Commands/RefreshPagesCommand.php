<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */
namespace WebPlatform\Importer\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
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
                    - Paste the values in `lib/mediawiki.php`
                    - Use like described in `mediawiki:run`, at 3rd pass

DESCR;
        $this
            ->setName('mediawiki:refresh-pages')
            ->setDescription($description);

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->init($input);
        $this->loadMissed(DATA_DIR.'/missed.yml');

        $apiUrl = getenv('MEDIAWIKI_API_ORIGIN').'/w/index.php?action=purge&title=';
        $this->initMediaWikiHelper($apiUrl);

        $xmlSource = $input->getOption('xml-source');

        $output->writeln(sprintf('Sending purge to %s:', $apiUrl));

        $streamer = $this->sourceXmlStreamFactory(DATA_DIR.'/'.$xmlSource);
        while ($node = $streamer->getNode()) {

            $pageNode = new SimpleXMLElement($node);
            if (isset($pageNode->title)) {
                $wikiDocument = new MediaWikiDocument($pageNode);

                $normalized_location = $wikiDocument->getName();

                if (!in_array($normalized_location, $this->missed)) {
                    continue;
                }

                $title = $wikiDocument->getTitle();

                try {
                    $purgeCall = $this->apiRequest($title);
                } catch (Exception $e) {
                    $message = 'Had issue with attempt to refresh page from MediaWiki for %s';
                    throw new Exception(sprintf($message, $title), 0, $e);
                }
                if (empty($purgeCall)) {
                    $message = 'Refresh call did not work, we expected a HTML and got nothing, check at %s%s gives from a web browser';
                    throw new Exception(sprintf($message, $apiUrl, $title));
                }

                $output->writeln(' - '.$title);
            }
        }
    }
}
