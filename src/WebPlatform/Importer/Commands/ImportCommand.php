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

use WebPlatform\ContentConverter\Converter\MediaWikiToMarkdown;
use WebPlatform\ContentConverter\Model\MediaWikiDocument;
use WebPlatform\ContentConverter\Entity\MediaWikiRevision;
use WebPlatform\ContentConverter\Persistency\FileGitCommit;
use SimpleXMLElement;
use Prewk\XmlStringStreamer;

/**
 * Notes:
 *   - http://ailoo.net/2013/03/stream-a-file-with-streamedresponse-in-symfony/
 *   - http://www.sitepoint.com/command-line-php-using-symfony-console/.
 **/
class ImportCommand extends Command
{

    protected function convert(MediaWikiRevision $revision)
    {
        return $this->converter->apply($revision);
    }

    protected function configure()
    {
        $this->setName('wpd:import')
             ->setDescription('Import data');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $header_style = new OutputFormatterStyle('white', 'black', array('bold'));
        $output->getFormatter()->setStyle('header', $header_style);

        $file = DUMP_DIR.'/main.xml';

        $output->writeln(sprintf('<header>Importing from %s</header>', $file));

        $streamer = XmlStringStreamer::createStringWalkerParser($file);
        $this->converter = new MediaWikiToMarkdown;

        while ($node = $streamer->getNode()) {
            $pageNode = new SimpleXMLElement($node);
            if (isset($pageNode->title)) {

                $wikiDocument = new MediaWikiDocument($pageNode);
                $wikiRevision = $wikiDocument->getLatest();
                $markdownRevision = $this->convert($wikiRevision);

                $file = new FileGitCommit($markdownRevision);
                $file->setFileName(MediaWikiDocument::toFileName($wikiDocument->getTitle()));

                echo $file->getFileName().PHP_EOL;
            }
        }
    }
}
