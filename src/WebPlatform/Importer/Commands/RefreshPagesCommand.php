<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */
namespace WebPlatform\Importer\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Filesystem;
use Prewk\XmlStringStreamer;
use WebPlatform\Importer\Model\MediaWikiDocument;
use WebPlatform\ContentConverter\Helpers\YamlHelper;
use WebPlatform\ContentConverter\Converter\MediaWikiToHtml;
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
class RefreshPagesCommand extends Command
{
    protected $missed = array();

    /** @var WebPlatform\ContentConverter\Converter\MediaWikiToHtml Symfony Filesystem handler */
    protected $converter;

    /** @var Symfony\Component\Filesystem\Filesystem Symfony Filesystem handler */
    protected $filesystem;

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
            ->setDescription($description)
            ->setDefinition(
                [
                    new InputOption('xml-source', '', InputOption::VALUE_OPTIONAL, 'What file to read from. Argument is relative from data/ folder from this directory (e.g. foo.xml in data/foo.xml)', 'dumps/main_full.xml'),
                ]
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $parser = new YamlHelper();

        $xmlSource = $input->getOption('xml-source');

        $missed_file = DATA_DIR.'/missed.yml';
        if (realpath($missed_file) === false) {
            throw new Exception(sprintf('Could not find missed file at %s', $missed_file));
        }

        $missedFileContents = file_get_contents($missed_file);

        try {
            $missed = $parser->unserialize($missedFileContents);
        } catch (Exception $e) {
            throw new Exception(sprintf('Could not get file %s contents to be parsed as YAML. Is it in YAML format?', $missed_file), null, $e);
        }

        if (!isset($missed['missed'])) {
            throw new Exception('Please ensure missed.yml has a list of titles under a "missed:" top level key');
        }

        $apiUrl = getenv('MEDIAWIKI_API_ORIGIN').'/w/index.php?action=purge&title=';

        if (
            isset($_ENV['MEDIAWIKI_USERID']) &&
            isset($_ENV['MEDIAWIKI_USERNAME']) &&
            isset($_ENV['MEDIAWIKI_SESSION']) &&
            isset($_ENV['MEDIAWIKI_WIKINAME'])
        ) {
            $cookies['UserID'] = getenv('MEDIAWIKI_USERID');
            $cookies['UserName'] = getenv('MEDIAWIKI_USERNAME');
            $cookies['_session'] = getenv('MEDIAWIKI_SESSION');
            $cookieString = str_replace(
                ['":"', '","', '{"', '"}'],
                ['=', ';'.getenv('MEDIAWIKI_WIKINAME'), getenv('MEDIAWIKI_WIKINAME'), ';'],
                json_encode($cookies)
            );
        } else {
            $cookieString = null;
        }

        // Let’s use the Converter makeRequest() helper.
        $this->converter = new MediaWikiToHtml();
        $this->converter->setApiUrl($apiUrl);

        $output->writeln(sprintf('Sending purge to %s:', $apiUrl));

        /* -------------------- XML source -------------------- **/
        $file = realpath(DATA_DIR.'/'.$xmlSource);
        if ($file === false) {
            throw new Exception(sprintf('Cannot run script, source XML file ./data/%s could not be found', $xmlSource));
        }
        $streamer = XmlStringStreamer::createStringWalkerParser($file);
        /* -------------------- /XML source -------------------- **/

        while ($node = $streamer->getNode()) {
            $pageNode = new SimpleXMLElement($node);
            if (isset($pageNode->title)) {
                $wikiDocument = new MediaWikiDocument($pageNode);

                $title = $wikiDocument->getTitle();
                $normalized_location = $wikiDocument->getName();

                if (!in_array($normalized_location, $missed['missed'])) {
                    continue;
                }

                try {
                    $purgeCall = $this->converter->makeRequest($title, $cookieString);
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
