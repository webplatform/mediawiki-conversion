<?php

/**
 * WebPlatform MediaWiki Conversion.
 */

namespace WebPlatform\Importer\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Filesystem;
use Prewk\XmlStringStreamer;

use WebPlatform\ContentConverter\Model\MediaWikiDocument;
use WebPlatform\ContentConverter\Model\MediaWikiContributor;
use WebPlatform\ContentConverter\Persistency\GitCommitFileRevision;

use SimpleXMLElement;
use Exception;

/**
 * Read and create a summary from a MediaWiki dumpBackup XML file
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class SummaryCommand extends Command
{
    protected $users = array();

    /** @var Symfony\Component\Filesystem\Filesystem Symfony Filesystem handler */
    protected $filesystem;

    protected function configure()
    {
        $description = <<<DESCR
                Walk through MediaWiki dumpBackup XML file,
                summarize revisions give details about the
                wiki contents.

                - List all pages
                - Which pages are translations
                - Which pages are redirects
                - Number of edits ("Revision") per page
                - Edits average and median

DESCR;
        $this
            ->setName('mediawiki:summary')
            ->setDescription($description)
            ->setDefinition(
                [
                    new InputOption('max-revs', '', InputOption::VALUE_OPTIONAL, 'Do not run full import, limit it to maximum of revisions per document ', 0),
                    new InputOption('max-pages', '', InputOption::VALUE_OPTIONAL, 'Do not run  full import, limit to a maximum of documents', 0),
                    new InputOption('display-author', '', InputOption::VALUE_NONE, 'Display or not the author and email address (useful to hide info for public reports), defaults to false'),

                ]
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->users = [];
        $this->filesystem = new Filesystem;

        $displayAuthor = $input->getOption('display-author');
        $maxHops = (int) $input->getOption('max-pages');    // Maximum number of pages we go through
        $revMaxHops = (int) $input->getOption('max-revs'); // Maximum number of revisions per page we go through

        $counter = 0;    // Increment the number of pages we are going through
        $redirects = [];
        $pages = [];
        $problematicAuthors = [];
        $urlParts = [];
        $urlsWithContent = [];

        $moreThanHundredRevs = [];
        $translations = [];
        $sanity_redirs = [];
        $directlyOnRoot = [];
        $rev_count = []; // So we can know what’s the average

        // Pages we have to make sure aren’t duplicate on the CMS prior
        // to the final migration.
        $temporary_acceptable_duplicates = [];
        //$temporary_acceptable_duplicates[] = 'css/selectors/pseudo-classes/:lang'; // DONE

        /** -------------------- Author --------------------
         *
         * Author array of MediaWikiContributor objects with $this->users[$uid],
         * where $uid is MediaWiki user_id.
         *
         * You may have to increase memory_limit value,
         * but we’ll load this only once.
         **/
        $users_file = DATA_DIR.'/users.json';
        $users_loop = json_decode(file_get_contents($users_file), 1);

        foreach ($users_loop as &$u) {
            $uid = (int) $u["user_id"];
            $this->users[$uid] = new MediaWikiContributor($u);
            unset($u); // Dont fill too much memory, if that helps.
        }
        /** -------------------- /Author -------------------- **/

        /** -------------------- XML source -------------------- **/
        $file = DATA_DIR.'/dumps/main_full.xml';
        $streamer = XmlStringStreamer::createStringWalkerParser($file);
        /** -------------------- /XML source -------------------- **/

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
                $normalized_location = $wikiDocument->getName();
                $file_path  = $persistable->getName();
                $is_redirect = $wikiDocument->getRedirect(); // False if not a redirect, string if it is

                $is_translation = $wikiDocument->isTranslation();
                $language_code = $wikiDocument->getLanguageCode();
                $language_name = $wikiDocument->getLanguageName();

                $revs  = $wikiDocument->getRevisions()->count();

                $output->writeln(sprintf('"%s":', $title));
                $output->writeln(sprintf('  - index: %d', $counter));
                $output->writeln(sprintf('  - normalized: %s', $normalized_location));
                $output->writeln(sprintf('  - file: %s', $file_path));

                if ($is_redirect !== false) {
                    $output->writeln(sprintf('  - redirect_to: %s', $is_redirect));
                } else {
                    $urlsWithContent[] = $title;
                    foreach (explode("/", $normalized_location) as $urlDepth => $urlPart) {
                        $urlPartKey = strtolower($urlPart);
                        $urlParts[$urlPartKey] = $urlPart;
                        $urlPartsAll[$urlPartKey][] = $urlPart;
                    }

                }

                if ($is_translation === true) {
                    $output->writeln(sprintf('  - lang: %s (%s)', $language_code, $language_name));
                }

                $output->writeln(sprintf('  - revs: %d', $revs));
                $output->writeln(sprintf('  - revisions:'));

                $revList = $wikiDocument->getRevisions();
                $revLast = $wikiDocument->getLatest();
                $revCounter = 0;

                /** ----------- REVISION --------------- **/
                for ($revList->rewind(); $revList->valid(); $revList->next()) {
                    if ($revMaxHops > 0 && $revMaxHops === $revCounter) {
                        $output->writeln(sprintf('    - stop: Reached maximum %d revisions', $revMaxHops).PHP_EOL.PHP_EOL);
                        break;
                    }

                    $wikiRevision = $revList->current();
                    $revision_id = $wikiRevision->getId();

                    /** -------------------- Author -------------------- **/
                    // An edge case where MediaWiki may give author as user_id 0, even though we dont have it
                    // so we’ll give the first user instead.
                    $contributor_id = ($wikiRevision->getContributorId() === 0)?1:$wikiRevision->getContributorId();
                    if (isset($this->users[$contributor_id])) {
                        $contributor = clone $this->users[$contributor_id]; // We want a copy, because its specific to here only anyway.
                        $wikiRevision->setContributor($contributor, false);
                    } else {
                        // In case we didn’t find data for $this->users[$contributor_id]
                        $contributor = clone $this->users[1]; // We want a copy, because its specific to here only anyway.
                        $wikiRevision->setContributor($contributor, false);
                    }
                    /** -------------------- /Author -------------------- **/

                    $output->writeln(sprintf('    - id: %d', $revision_id));
                    $output->writeln(sprintf('      index: %d', $revCounter));

                    $persistArgs = $persistable->setRevision($wikiRevision)->getArgs();
                    foreach ($persistArgs as $argKey => $argVal) {
                        if ($argKey === 'message') {
                            $argVal = mb_strimwidth($argVal, strpos($argVal, ': ') + 2, 100);
                        }
                        if ($displayAuthor === false && $argKey === 'author') {
                            continue;
                        }
                        $output->writeln(sprintf('      %s: %s', $argKey, $argVal));

                    }

                    if ($revLast->getId() === $wikiRevision->getId() && $wikiDocument->hasRedirect()) {
                        $output->writeln('      is_last_and_has_redirect: True');
                    }

                    ++$revCounter;
                }

                /** ----------- REVISION --------------- */

                $rev_count[] = $revs;

                // Which pages are directly on /wiki/foo. Are there some we
                // should move elsewhere such as the glossary items?
                if (count(explode('/', $title)) == 1 && $is_redirect === false) {
                    $directlyOnRoot[] = $title;
                }

                if ($revs > 99) {
                    $moreThanHundredRevs[] = sprintf('%s (%d)', $title, $revs);
                }

                if ($is_translation === true) {
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
                if ($is_redirect !== false) {
                    // Pages we know are redirects within MediaWiki, we won’t
                    // pass them within the $pages aray because they would be
                    // empty content with only a redirect anyway.
                    $redirects[$normalized_location] = $is_redirect;
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

        $numbers = array('Numbers:');
        $numbers[] = sprintf('  - "iterations": %d', $counter);
        $numbers[] = sprintf('  - "content pages": %d', count($pages));
        $numbers[] = sprintf('  - "redirects": %d', count($redirects));
        $numbers[] = sprintf('  - "translated": %d', count($translations));
        $numbers[] = sprintf('  - "not in a directory": %d', count($directlyOnRoot));
        $numbers[] = sprintf('  - "redirects for URL sanity": %d', count($sanity_redirs));
        $numbers[] = sprintf('  - "edits average": %d', $edit_average);
        $numbers[] = sprintf('  - "edits median": %d', $edit_median);
        $this->filesystem->dumpFile('data/numbers.txt', implode($numbers, PHP_EOL));

        $this->filesystem->dumpFile("data/hundred_revs.txt", implode($moreThanHundredRevs, PHP_EOL));
        $this->filesystem->dumpFile("data/problematic_authors.txt", implode($problematicAuthors, PHP_EOL));

        natcasesort($translations);
        $this->filesystem->dumpFile("data/translations.txt", implode(PHP_EOL, $translations));
        natcasesort($directlyOnRoot);
        $this->filesystem->dumpFile("data/directly_on_root.txt", implode(PHP_EOL, $directlyOnRoot));
        natcasesort($urlsWithContent);
        $this->filesystem->dumpFile("data/url_all.txt", implode(PHP_EOL, $urlsWithContent));

        natcasesort($urlParts);
        $this->filesystem->dumpFile("data/url_parts.txt", implode(PHP_EOL, $urlParts));

        // Creating list for https://github.com/webplatform/mediawiki-conversion/issues/2
        ksort($urlPartsAll);
        $urlPartsAllOut = array('All words that exists in an URL, and the different ways they are written (needs harmonizing!):');
        foreach ($urlPartsAll as $urlPartsAllKey => $urlPartsAllRow) {
            $urlPartsAllEntryUnique = array_unique($urlPartsAllRow);
            if (count($urlPartsAllEntryUnique) > 1) {
                $urlPartsAllOut[] = sprintf(' - %s', implode(', ', $urlPartsAllEntryUnique));
            }
        }
        $this->filesystem->dumpFile("data/url_parts_variants.txt", implode(PHP_EOL, $urlPartsAllOut));

        $sanity_redirects_out = array('URLs to return new Location (from => to):');
        foreach ($sanity_redirs as $title => $sanitized) {
            $sanity_redirects_out[] = sprintf(' - "%s": "%s"', $title, $sanitized);
        }
        $this->filesystem->dumpFile("data/sanity_redirects.txt", implode(PHP_EOL, $sanity_redirects_out));

        $redirects_out = array('Redirects (from => to):');
        foreach ($redirects as $url => $redirect_to) {
            $redirects_out[] = sprintf(' - "%s": "%s"', $url, $redirect_to);
        }
        $this->filesystem->dumpFile("data/redirects.txt", implode(PHP_EOL, $redirects_out));
    }
}
