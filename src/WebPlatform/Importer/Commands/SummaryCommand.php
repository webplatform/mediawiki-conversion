<?php

/**
 * WebPlatform MediaWiki Conversion.
 **/

namespace WebPlatform\Importer\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

use WebPlatform\ContentConverter\Model\MediaWikiDocument;
use WebPlatform\ContentConverter\Model\MediaWikiContributor;
use WebPlatform\ContentConverter\Persistency\GitCommitFileRevision;

use SimpleXMLElement;
use DateTime;
use Exception;

use Prewk\XmlStringStreamer;
use Symfony\Component\Filesystem\Filesystem;
use Bit3\GitPhp\GitRepository;
use Bit3\GitPhp\GitException;

class SummaryCommand extends Command
{
    protected $users = array();

    /** @var Symfony\Component\Filesystem\Filesystem Symfony Filesystem handler */
    protected $filesystem;

    /** @var Bit3\GitPhp\GitRepository Git Repository handler */
    protected $git;

    protected function configure()
    {
        $description = <<<DESCR
                Walk through MediaWiki dumpBackup XML file,
                summarize revisions and a suggested file name
                to store on a filesystem.
DESCR;
        $this
            ->setName('mediawiki:summary')
            ->setDescription($description);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $header_style = new OutputFormatterStyle('white', 'black', array('bold'));
        $output->getFormatter()->setStyle('header', $header_style);

        $this->filesystem = new Filesystem;

        $repoInitialized = (realpath(GIT_OUTPUT_DIR.'/.git') === false)?false:true;
        //die(var_dump(array($repoInitialized, realpath(GIT_OUTPUT_DIR))));
        $this->git = new GitRepository(realpath(GIT_OUTPUT_DIR));
        if ($repoInitialized === false) {
            $this->git->init()->execute();
        }

        $moreThanHundredRevs = [];
        $translations = [];
        $redirects = [];
        $sanity_redirs = [];
        $pages = [];
        $directlyOnRoot = [];
        $rev_count = []; // So we can know what’s the average

        // Pages we have to make sure aren’t duplicate on the CMS prior
        // to the final migration.
        $temporary_acceptable_duplicates = [];
        //$temporary_acceptable_duplicates[] = 'css/selectors/pseudo-classes/:lang'; // DONE

        $problematic_author_entry = [];

        $counter = 1;
        $maxHops = 0;
        $revMaxHops = 0;

        /**
         * Author array of MediaWikiContributor objects with $this->users[$uid],
         * where $uid is MediaWiki user_id.
         *
         * You may have to increase memory_limit value,
         * but we’ll load this only once.
         **/
        $users_file = DATA_DIR.'/users.json';
        $users_loop = json_decode(file_get_contents($users_file), 1);

        $this->users = [];
        foreach ($users_loop as &$u) {
            $uid = (int) $u["user_id"];
            $this->users[$uid] = new MediaWikiContributor($u);
            unset($u); // Dont fill too much memory, if that helps.
        }
        /* /Author array */

        $file = DATA_DIR.'/dumps/main_full.xml';
        $streamer = XmlStringStreamer::createStringWalkerParser($file);

        while ($node = $streamer->getNode()) {
            if ($maxHops > 0 && $maxHops === $counter) {
                $output->writeln(sprintf('Reached desired maximum of %d loops', $maxHops).PHP_EOL.PHP_EOL);
                break;
            }
            $pageNode = new SimpleXMLElement($node);
            if (isset($pageNode->title)) {

                $wikiDocument = new MediaWikiDocument($pageNode);
                $persistable = new GitCommitFileRevision($wikiDocument, 'out/content/', '.md');

                $title = $wikiDocument->getTitle();
                $revs  = $wikiDocument->getRevisions()->count();
                $is_translation = $wikiDocument->isTranslation();

                $file_path  = $persistable->getName();

                $redirect = $wikiDocument->getRedirect();
                $normalized_location = $persistable->getName();

                $output->writeln(sprintf('"%s":', $title));
                $output->writeln(sprintf('  - normalized: %s', $normalized_location));
                $output->writeln(sprintf('  - file: %s', $file_path));
                $output->writeln(sprintf('  - revs: %d', $revs));
                $output->writeln(sprintf('  - revisions:'));

                $revisionsList = $wikiDocument->getRevisions();
                $revCounter = 0;

                /** ----------- REVISION --------------- */
                for ($revisionsList->rewind(); $revisionsList->valid(); $revisionsList->next()) {
                    if ($revMaxHops > 0 && $revMaxHops === $revCounter) {
                        $output->writeln(sprintf('Reached desired maximum of %d revision loops', $revMaxHops).PHP_EOL.PHP_EOL);
                        break;
                    }

                    $wikiRevision = $revisionsList->current();
                    $revision_id = $wikiRevision->getId();

                    // An edge case where MediaWiki may give author as user_id 0, even though we dont have it
                    // so we’ll give the first user instead.
                    $contributor_id = ($wikiRevision->getContributorId() === 0)?1:$wikiRevision->getContributorId();

                    if (isset($this->users[$contributor_id])) {
                        $contributor = $this->users[$contributor_id];
                        if ($wikiRevision->getContributorId() === 0) {
                            $contributor->setRealName($wikiRevision->getContributorName());
                        }
                        $wikiRevision->setContributor($contributor, false);
                    } else {
                        // Hopefully we won’t get any here!
                        $problematic_author_entry[] = sprintf('{revision_id: %d, contributor_id: %d}', $revision_id, $contributor_id);
                    }
                    $author = $wikiRevision->getAuthor();
                    $contributor_name = (is_object($author))?$author->getRealName():'No real name set';

                    $timestamp = $wikiRevision->getTimestamp()->format(DateTime::RFC2822);

                    $persistable->setRevision($wikiRevision);
                    $commit_commands = $persistable->formatPersisterCommand();
                    $commit_args = $persistable->getArgs();

                    $this->filesystem->dumpFile($persistable->getName(), (string) $persistable);

                    $output->writeln(sprintf('    - id: %d', $revision_id));
                    $output->writeln(sprintf('      timestamp: %s', $timestamp));
                    $output->writeln(sprintf('      full_name: %s', $contributor_name));
                    $output->writeln(sprintf('      author: %s', (string) $author));
                    $output->writeln(sprintf('      user_id: %d', $contributor_id));
                    $output->writeln(sprintf('      commit: %s', join(' ; ', $commit_commands)));

                    try {
                        $this->git
                            // Make sure out/ matches what we set at GitCommitFileRevision constructor.
                            ->add()
                            ->execute(preg_replace('/^out\//', '', $persistable->getName()));
                    } catch (GitException $e) {
                        $message = sprintf('Could not add file %s for revision %d', $persistable->getName(), $revision_id);
                        throw new Exception($message, null, $e);
                    }

                    try {
                        $this->git
                            ->commit()
                            ->message('"'.$wikiRevision->getComment().'"')
                            ->author('"'.(string) $author.'"')
                            ->date('"'.$timestamp.'"')
                            ->allowEmpty()
                            ->execute();

                    } catch (GitException $e) {
                        var_dump($this->git);
                        $message = sprintf('Could not commit for revision %d', $revision_id);
                        throw new Exception($message, null, $e);
                    }

                    //die(var_dump($this->git));

                    ++$revCounter;
                }

                /** ----------- REVISION --------------- */

                $rev_count[] = $revs;

                // Which pages are directly on /wiki/foo. Are there some we
                // should move elsewhere such as the glossary items?
                if (count(explode('/', $title)) == 1 && $redirect === false) {
                    $directlyOnRoot[] = $title;
                }

                if ($revs > 99) {
                    $moreThanHundredRevs[] = sprintf('%s (%d)', $title, $revs);
                }

                if ($is_translation === true) {
                    $output->writeln(sprintf('  - language_code: %s', $wikiDocument->getLanguageCode()));
                    $translations[] = $title;
                }

                // The ones with invalid URL characters that shouldn’t be part of
                // a page name because they may confuse with their natural use (:,(,),!,?)
                if ($title !== $normalized_location) {
                    $sanity_redirs[$title] = $normalized_location;
                }

                // We have a number of pages, some of them had been
                // deleted or erased with a redirect left behind.
                //
                // Since we want to write to files all pages that currently
                // has content into a filesystem, we have to generate a file
                // name that can be stored into a filesystem. We therefore have
                // to normalize the names.
                //
                // We don’t want to have two entries with the same name.
                //
                // If a redirect (i.e. an empty file) exist, let’s set keep it
                // separate from the pages that still has content.
                //
                // Sanity check;
                // 1. Get list of redirects
                // 2. Get list of pages
                //
                // If we have a page duplicate, throw an exception!
                if ($redirect !== false) {
                    // Pages we know are redirects within MediaWiki, we won’t
                    // pass them within the $pages aray because they would be
                    // empty content with only a redirect anyway.
                    $output->writeln(sprintf('  - redirect: %s', $redirect));
                    $redirects[$normalized_location] = $redirect;
                } elseif (!in_array($normalized_location, array_keys($pages))) {
                    // Pages we know has content, lets count them!
                    $pages[$normalized_location] = $title;
                } elseif (in_array($title, $temporary_acceptable_duplicates)) {
                    // Lets not throw, we got that covered.
                } else {
                    // Hopefully we should never encounter this.
                    $previous = $pages[$normalized_location];
                    $duplicatePagesExceptionText =  "We have duplicate entry for %s it "
                                                   ."would be stored in %s which would override content of %s";
                    throw new Exception(sprintf($duplicatePagesExceptionText, $title, $file_path, $previous));
                }

                $output->writeln(PHP_EOL.PHP_EOL);
                ++$counter;
            }

        }

        /**
         * Work some numbers on number of edits
         *
         * - Average
         * - Median
         */
        $total_edits = 0;
        sort($rev_count);
        $edit_average = array_sum($rev_count)/$counter;

        // Calculate median
        $value_in_middle = floor(($counter-1)/2);
        if ($counter % 2) {
            // odd number, middle is the median
            $edit_median = $rev_count[$value_in_middle];
        } else {
            // even number, calculate avg of 2 medians
            $low = $rev_count[$value_in_middle];
            $high = $rev_count[$value_in_middle+1];
            $edit_median = (($low+$high)/2);
        }

        $output->writeln('---'.PHP_EOL.PHP_EOL);

        $output->writeln('Pages with more than 100 revisions:');
        foreach ($moreThanHundredRevs as $r) {
            $output->writeln(sprintf('  - %s', $r));
        }

        $output->writeln(PHP_EOL.PHP_EOL.'---'.PHP_EOL.PHP_EOL);

        $output->writeln('Available translations:');
        foreach ($translations as $t) {
            $output->writeln(sprintf('  - %s', $t));
        }

        $output->writeln(PHP_EOL.PHP_EOL.'---'.PHP_EOL.PHP_EOL);

        $output->writeln('Redirects (from => to):');
        foreach ($redirects as $url => $redirect_to) {
            $output->writeln(sprintf(' - "%s": "%s"', $url, $redirect_to));
        }

        $output->writeln(PHP_EOL.PHP_EOL.'---'.PHP_EOL.PHP_EOL);

        $output->writeln('URLs to return new Location (from => to):');
        foreach ($sanity_redirs as $title => $sanitized) {
            $output->writeln(sprintf(' - "%s": "%s"', $title, $sanitized));
        }

        $output->writeln(PHP_EOL.PHP_EOL.'---'.PHP_EOL.PHP_EOL);

        $output->writeln('Pages not in a directory:');
        foreach ($directlyOnRoot as $title) {
            $output->writeln(sprintf(' - %s', $title));
        }

        $output->writeln(PHP_EOL.PHP_EOL.'---'.PHP_EOL.PHP_EOL);

        $output->writeln('Problematic authors (should be empty!):');
        if (count($problematic_author_entry) == 0) {
            $output->writeln(' - None');
        } else {
            foreach ($problematic_author_entry as $a) {
                $output->writeln(sprintf(' - %s', $a));
            }
        }

        $output->writeln(PHP_EOL.PHP_EOL.'---'.PHP_EOL.PHP_EOL);

        $output->writeln('Numbers:');
        $output->writeln(sprintf('  - "iterations": %d', $counter));
        $output->writeln(sprintf('  - "content pages": %d', count($pages)));
        $output->writeln(sprintf('  - "redirects": %d', count($redirects)));
        $output->writeln(sprintf('  - "translated": %d', count($translations)));
        $output->writeln(sprintf('  - "not in a directory": %d', count($directlyOnRoot)));
        $output->writeln(sprintf('  - "redirects for URL sanity": %d', count($sanity_redirs)));
        $output->writeln(sprintf('  - "edits average": %d', $edit_average));
        $output->writeln(sprintf('  - "edits median": %d', $edit_median));

    }
}
