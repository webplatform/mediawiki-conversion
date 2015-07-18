<?php

/**
 * WebPlatform MediaWiki Conversion.
 */

namespace WebPlatform\Importer\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Prewk\XmlStringStreamer;

use WebPlatform\ContentConverter\Model\MediaWikiDocument;
use WebPlatform\ContentConverter\Model\MediaWikiContributor;
use WebPlatform\ContentConverter\Persistency\GitCommitFileRevision;

use SimpleXMLElement;
use DateTime;
use Exception;

/**
 * Read and create a summary from a MediaWiki dumpBackup XML file
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class SummaryCommand extends Command
{
    protected $users = array();

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
                ]
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $maxHops = (int) $input->getOption('max-pages');    // Maximum number of pages we go through
        $revMaxHops = (int) $input->getOption('max-revs'); // Maximum number of revisions per page we go through

        $counter = 1;    // Increment the number of pages we are going through
        $redirects = [];
        $pages = [];
        $problematic_author_entry = [];
        $titleMatrix = [];

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

        $this->users = [];
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
                $persistable = new GitCommitFileRevision($wikiDocument, 'out/content/', '.md'); // make sure $titleMatric considers this too

                $title = $wikiDocument->getTitle();
                $normalized_location = $persistable->getName();
                $file_path  = $persistable->getName();
                $is_redirect = $wikiDocument->getRedirect(); // False if not a redirect, string if it is

                foreach (explode("/", $normalized_location) as $urlDepth => $urlPart) {
                    if (preg_match('/\.md$/', $urlPart) === 0 && $urlDepth > 1) {
                        // Lets grab only after out/content, see also GitCommitFileRevision
                        // constructor arguments.
                        $titleMatrix[$urlDepth][strtolower($urlPart)] = $urlPart;
                    }
                }

                $is_translation = $wikiDocument->isTranslation();
                $language_code = $wikiDocument->getLanguageCode();

                $revs  = $wikiDocument->getRevisions()->count();

                $output->writeln(sprintf('"%s":', $title));
                $output->writeln(sprintf('  - normalized: %s', $normalized_location));
                $output->writeln(sprintf('  - file: %s', $file_path));

                if ($is_redirect !== false) {
                    $output->writeln(sprintf('  - redirect_to: %s', $is_redirect));
                }

                if ($is_translation === true) {
                    $output->writeln(sprintf('  - lang: %s', $language_code));
                }

                $output->writeln(sprintf('  - revs: %d', $revs));
                $output->writeln(sprintf('  - revisions:'));

                $revList = $wikiDocument->getRevisions();
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
                        $contributor = $this->users[$contributor_id];
                        if ($wikiRevision->getContributorId() === 0) {
                            $contributor->setRealName($wikiRevision->getContributorName());
                        }
                        $wikiRevision->setContributor($contributor, false);
                    } else {
                        // Hopefully we won’t get any here!
                        $problematic_author_entry[] = sprintf('{revision_id: %d, contributor_id: %d}', $revision_id, $contributor_id);
                    }
                    /** -------------------- /Author -------------------- **/

                    $author = $wikiRevision->getAuthor();
                    $contributor_name = (is_object($author))?$author->getRealName():'Anonymous Contributor';
                    $author_string = (string) $author;
                    $author_string = (empty($author_string))?$contributor_name.' <public-webplatform@w3.org>':$author_string;
                    $timestamp = $wikiRevision->getTimestamp()->format(DateTime::RFC2822);

                    $comment = $wikiRevision->getComment();
                    $comment_shorter = mb_strimwidth($comment, strpos($comment, ': ') + 2, 100);

                    $output->writeln(sprintf('    - id: %d', $revision_id));
                    $output->writeln(sprintf('      timestamp: %s', $timestamp));
                    $output->writeln(sprintf('      full_name: %s', $contributor_name));
                    $output->writeln(sprintf('      author: %s', $author_string));
                    $output->writeln(sprintf('      comment: %s', $comment_shorter));

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

        $output->writeln(PHP_EOL.PHP_EOL.'---'.PHP_EOL.PHP_EOL);

        $output->writeln('URLs to make consistent:');
        foreach ($titleMatrix as $level) {
            $output->writeln('  level:');
            foreach ($level as $k => $v) {
                $output->writeln(sprintf('    - %s', $v));
            }
        }

    }
}
