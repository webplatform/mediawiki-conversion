<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */
namespace WebPlatform\Importer\Commands;

use WebPlatform\ContentConverter\Model\MediaWikiContributor;
use WebPlatform\ContentConverter\Helpers\MediaWikiHelper;
use WebPlatform\ContentConverter\Helpers\YamlHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Filesystem\Filesystem;
use Prewk\XmlStringStreamer;
use RuntimeException;

/**
 * Common importer command methods.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class AbstractImporterCommand extends Command
{
    /** @var WebPlatform\ContentConverter\Helpers\ApiRequestHelperInterface Conversion helper instance */
    protected $apiHelper;

    /** @var WebPlatform\ContentConverter\Helpers\YamlHelper Yaml Helper instance */
    protected $yaml;

    /** @var Symfony\Component\Filesystem\Filesystem Symfony Filesystem handler */
    protected $filesystem;

    protected $users = [];

    protected $missed = [];

    protected function configure()
    {
        $helpText  = 'What file to read from. Argument is relative from data/ ';
        $helpText .= 'folder from this directory (e.g. dumps/wpd_full.xml, would read from data/dumps/foo.xml)';

        $this->addOption('xml-source', '', InputOption::VALUE_OPTIONAL, $helpText, 'dumps/main_full.xml');
    }

    /**
     * Source XML file read stream factory
     *
     * @param string $xmlSourcePath path where the XML file should be read from, relative to DATA_DIR
     *
     * @return Prewk\XmlStringStreamer A XML String stream
     */
    protected function sourceXmlStreamFactory($xmlSourcePath)
    {
        $file = realpath($xmlSourcePath);
        if ($file === false) {
            $message = 'Cannot run script, source XML file at "%s" could not be found';
            throw new RuntimeException(sprintf($message, $xmlSourcePath));
        }

        return XmlStringStreamer::createStringWalkerParser($file);
    }

    /**
     * Load Authors
     *
     * Author array of MediaWikiContributor objects with $this->users[$uid],
     * where $uid is MediaWiki user_id.
     *
     * You may have to increase memory_limit value,
     * but we’ll load this only once.
     **/
    protected function loadUsers($usersSourcePath)
    {
        $file = realpath($usersSourcePath);
        if ($file === false) {
            $message = 'Cannot run script, source users file at "%s" could not be found';
            throw new RuntimeException(sprintf($message, $usersSourcePath));
        }

        $users_loop = json_decode(file_get_contents($file), 1);

        foreach ($users_loop as &$u) {
            $uid = (int) $u['user_id'];
            $this->users[$uid] = new MediaWikiContributor($u);
            unset($u); // Dont fill too much memory, if that helps.
        }
        /* -------------------- /Author -------------------- **/
    }

    protected function loadMissed($missedNormalizedTitlesSource)
    {
        if (realpath($missedNormalizedTitlesSource) === false) {
            $message = 'Could not find missed file at %s';
            throw new RuntimeException(sprintf($message, $missedNormalizedTitlesSource));
        }

        $missedFileContents = file_get_contents($missedNormalizedTitlesSource);

        try {
            $missed = $this->yaml->unserialize($missedFileContents);
        } catch (Exception $e) {
            $message = 'Could not get file %s contents to be parsed as YAML. Is it in YAML format?';
            throw new Exception(sprintf($message, $missedNormalizedTitlesSource), null, $e);
        }

        if (!isset($missed['missed'])) {
            throw new Exception('Please ensure missed.yml has a list of titles under a "missed:" top level key');
        }

        $this->missed = $missed['missed'];
    }

    private function initCookieString()
    {
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

        $this->cookieString = $cookieString;
    }

    protected function initMediaWikiHelper($apiUrl)
    {
        // Let’s use the Converter makeRequest() helper.
        $this->apiHelper = new MediaWikiHelper($apiUrl);
    }

    protected function apiRequest($title)
    {
        $cookieString = $this->cookieString;

        return $this->apiHelper->makeRequest($title, $cookieString);
    }

    protected function apiMediaWikiResponseArrayFactory($title)
    {
        $cookieString = $this->cookieString;

        return $this->apiHelper->retrieve($title, $cookieString);
    }

    protected function init(InputInterface $input)
    {
        $this->yaml = new YamlHelper();
        $this->filesystem = new Filesystem();
        $this->initCookieString();
    }
}
