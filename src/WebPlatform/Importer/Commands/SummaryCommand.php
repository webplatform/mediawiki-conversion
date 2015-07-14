<?php

/**
 * WebPlatform MediaWiki Conversion.
 **/

namespace WebPlatform\Importer\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

use WebPlatform\ContentConverter\Converter\MediaWikiToMarkdown;
use WebPlatform\ContentConverter\Model\MediaWikiDocument;
use WebPlatform\ContentConverter\Entity\MediaWikiRevision;
use WebPlatform\ContentConverter\Persistency\FileGitCommit;
use SimpleXMLElement;
use Prewk\XmlStringStreamer;

class SummaryCommand extends Command
{

    protected function convert(MediaWikiRevision $revision)
    {
        return $this->converter->apply($revision);
    }

    protected function configure()
    {
        $this
            ->setName('mediawiki:summary')
            ->setDescription(<<<DESCR
                Walk through MediaWiki dumpBackup XML file,
                summarize revisions and a suggested file name
                to store on a filesystem.
DESCR
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $header_style = new OutputFormatterStyle('white', 'black', array('bold'));
        $output->getFormatter()->setStyle('header', $header_style);

        $file = DUMP_DIR.'/main_full.xml';

        $output->writeln(sprintf('<header>Importing from %s</header>', $file));

        $streamer = XmlStringStreamer::createStringWalkerParser($file);
        $this->converter = new MediaWikiToMarkdown;

        while ($node = $streamer->getNode()) {
            $pageNode = new SimpleXMLElement($node);
            if (isset($pageNode->title)) {

                $wikiDocument = new MediaWikiDocument($pageNode);

                $wikiRevision = $wikiDocument->getLatest();
                $file = new FileGitCommit($wikiRevision);
                $file->setFileName(MediaWikiDocument::toFileName($wikiDocument->getTitle()));

                $path  = $file->getFileName();

                $title = $wikiDocument->getTitle();
                $revs  = $wikiDocument->getRevisions()->count();
                $is_translation = $wikiDocument->isTranslation() === true ? 'Yes' : 'No';
                $language_code = $wikiDocument->getLanguageCode();

                $path .= (($wikiDocument->isTranslation()) ? null : '/index' ) . '.md';

                $output->writeln(sprintf('"https://docs.webplatform.org/wiki/%s":', $title));
                $output->writeln(sprintf('  - is_translation: %s', $is_translation));
                $output->writeln(sprintf('  - language_code: %s', $language_code));
                $output->writeln(sprintf('  - revisions: %d', $revs));
                $output->writeln(sprintf('  - file_path: %s', $path));
            }
        }
    }
}
