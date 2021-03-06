<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */
namespace WebPlatform\Importer\Commands;

use WebPlatform\ContentConverter\Persistency\GitCommitFileRevision;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use WebPlatform\Importer\Model\MediaWikiDocument;
use Symfony\Component\Console\Input\InputOption;
use WebPlatform\Importer\Filter\TitleFilter;
use SimpleXMLElement;
use Exception;

/**
 * Read and create a summary from a MediaWiki dumpBackup XML file.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class SummaryCommand extends AbstractImporterCommand
{
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
                    new InputOption('missed', '', InputOption::VALUE_NONE, 'Give XML node indexes of missed conversion so we can run a 3rd pass only for them'),
                    new InputOption('max-revs', '', InputOption::VALUE_OPTIONAL, 'Do not make full run, limit it to maximum of revisions per document ', 0),
                    new InputOption('max-pages', '', InputOption::VALUE_OPTIONAL, 'Do not make full run, limit to a maximum of documents', 0),
                    new InputOption('namespace-prefix', '', InputOption::VALUE_OPTIONAL, 'If not against main MediaWiki namespace, set prefix (e.g. Meta) so we can create a git repo with all contents on root so that we can use export as a submodule.', false),
                    new InputOption('display-author', '', InputOption::VALUE_NONE, 'Display or not the author and email address (useful to hide info for public reports), defaults to false'),
                    new InputOption('indexes', '', InputOption::VALUE_NONE, 'Whether or not we display loop indexes'),
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
        $revMaxHops = (int) $input->getOption('max-revs'); // Maximum number of revisions per page we go through
        $namespacePrefix = $input->getOption('namespace-prefix');

        $displayIndex = $input->getOption('indexes');
        $displayAuthor = $input->getOption('display-author');

        $redirects = [];
        $pages = [];
        $urlParts = [];
        $urlPartsAll = [];
        $missedIndexes = [];

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

        if ($listMissed === true) {
            $this->loadMissed(DATA_DIR.'/missed.yml');
        }

        $this->loadUsers(DATA_DIR.'/users.json');

        $this->titleFilter = new TitleFilter();

        $streamer = $this->sourceXmlStreamFactory(DATA_DIR.'/'.$xmlSource);
        $counter = 0;
        while ($node = $streamer->getNode()) {
            $pageNode = new SimpleXMLElement($node);
            if (isset($pageNode->title)) {
                ++$counter;
                if ($maxHops > 0 && $maxHops === $counter - 1) {
                    $output->writeln(sprintf('Reached desired maximum of %d documents', $maxHops).PHP_EOL);
                    break;
                }

                $wikiDocument = new MediaWikiDocument($pageNode);
                $persistable = new GitCommitFileRevision($wikiDocument, 'out/', '.md');

                $title = $wikiDocument->getTitle();
                $normalized_location = $wikiDocument->getName();
                $file_path = $this->titleFilter->filter($persistable->getName());
                $file_path = ($namespacePrefix === false) ? $file_path : str_replace(sprintf('%s/', $namespacePrefix), '', $file_path);
                $redirect_to = $this->titleFilter->filter($wikiDocument->getRedirect()); // False if not a redirect, string if it is

                $language_code = $wikiDocument->getLanguageCode();
                $language_name = $wikiDocument->getLanguageName();
                $revs = $wikiDocument->getRevisions()->count();
                $revList = $wikiDocument->getRevisions();
                $revLast = $wikiDocument->getLatest();

                $output->writeln(sprintf('"%s":', $title));
                $output->writeln(sprintf('  - id: %d', $wikiDocument->getId()));
                if ($displayIndex === true) {
                    $output->writeln(sprintf('  - index: %d', $counter));
                }
                $output->writeln(sprintf('  - normalized: %s', $normalized_location));
                $output->writeln(sprintf('  - file: %s', $file_path));

                if ($wikiDocument->isTranslation() === true) {
                    $output->writeln(sprintf('  - lang: %s (%s)', $language_code, $language_name));
                }

                if ($wikiDocument->hasRedirect() === true) {
                    $output->writeln(sprintf('  - redirect_to: %s', $redirect_to));
                } else {
                    /**
                     * Gather what we can know from the location.
                     *
                     * Explode all parts in two separate arrays so we’ll be able to tell
                     * if we have conflicts (e.g. CSS/Selectors .. css/selectors). So we can
                     * harmonize the names to have **ONLY ONE** way of writing the casing for a
                     * given path.
                     *
                     * If you want to define how to write an URL part, refer to TitleFilter class.
                     */
                    $urlsWithContent[] = $title;
                    foreach (explode('/', $normalized_location) as $urlDepth => $urlPart) {
                        $urlPartKey = strtolower($urlPart);
                        $urlParts[$urlPartKey] = $urlPart;
                        $urlPartsAll[$urlPartKey][] = $urlPart;
                    }
                }

                if ($listMissed === true && in_array($normalized_location, $this->missed)) {
                    $missedIndexes[$counter] = $title;
                }

                $output->writeln(sprintf('  - revs: %d', $revs));
                $output->writeln(sprintf('  - revisions:'));

                /* ----------- REVISION --------------- **/
                $revCounter = 0;
                for ($revList->rewind(); $revList->valid(); $revList->next()) {
                    ++$revCounter;

                    if ($revMaxHops > 0 && $revMaxHops === $revCounter) {
                        $output->writeln(sprintf('    - stop: Reached maximum %d revisions', $revMaxHops).PHP_EOL.PHP_EOL);
                        break;
                    }

                    $wikiRevision = $revList->current();

                    /* -------------------- Author -------------------- **/
                    // An edge case where MediaWiki may give author as user_id 0, even though we dont have it
                    // so we’ll give the first user instead.
                    $contributor_id = ($wikiRevision->getContributorId() === 0) ? 1 : $wikiRevision->getContributorId();

                    /**
                     * Fix duplicates and merge them as only one.
                     *
                     * Please adjust to suit your own.
                     *
                     * Queried using jq;
                     *
                     *     cat data/users.json | jq '.[]|select(.user_real_name == "Renoir Boulanger")'
                     *
                     * #TODO: Change the hardcoded list.
                     */
                    if (in_array($contributor_id, [172943, 173060, 173278, 173275, 173252, 173135, 173133, 173087, 173086, 173079, 173059, 173058, 173057])) {
                        $contributor_id = getenv('MEDIAWIKI_USERID');
                    }

                    if (isset($this->users[$contributor_id])) {
                        $contributor = clone $this->users[$contributor_id]; // We want a copy, because its specific to here only anyway.
                        $wikiRevision->setContributor($contributor, false);
                    } else {
                        // In case we didn’t find data for $this->users[$contributor_id]
                        $contributor = clone $this->users[1]; // We want a copy, because its specific to here only anyway.
                        $wikiRevision->setContributor($contributor, false);
                    }
                    /* -------------------- /Author -------------------- **/

                    $output->writeln(sprintf('    - id: %d', $wikiRevision->getId()));
                    if ($displayIndex === true) {
                        $output->writeln(sprintf('      index: %d', $revCounter));
                    }

                    $persistArgs = $persistable->setRevision($wikiRevision)->getArgs();
                    foreach ($persistArgs as $argKey => $argVal) {
                        if ($argKey === 'message') {
                            $argVal = trim(mb_strimwidth($argVal, strpos($argVal, ': ') + 2, 100));
                        }
                        if ($argKey === 'message' && empty($argVal)) {
                            // Lets not pollute report with empty messages
                            continue;
                        }
                        if ($displayAuthor === false && $argKey === 'author') {
                            continue;
                        }
                        $output->writeln(sprintf('      %s: "%s"', $argKey, $argVal));
                    }

                    if ($revLast->getId() === $wikiRevision->getId() && $wikiDocument->hasRedirect()) {
                        $output->writeln('      is_last_and_has_redirect: True');
                    }
                }

                /* ----------- REVISION --------------- */

                $rev_count[] = $revs;

                // Which pages are directly on /wiki/foo. Are there some we
                // should move elsewhere such as the glossary items?
                if (count(explode('/', $title)) == 1 && $wikiDocument->hasRedirect() === false) {
                    $directlyOnRoot[] = $title;
                }

                if ($revs > 99) {
                    $moreThanHundredRevs[] = sprintf('%s (%d)', $title, $revs);
                }

                if ($wikiDocument->isTranslation() === true && $wikiDocument->hasRedirect() === false) {
                    $translations[] = $title;
                }

                // The ones with invalid URL characters that shouldn’t be part of
                // a page name because they may confuse with their natural use (:,(,),!,?)
                if ($title !== $normalized_location && $wikiDocument->hasRedirect() === false) {
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
                if ($wikiDocument->hasRedirect() === true) {
                    // Pages we know are redirects within MediaWiki, we won’t
                    // pass them within the $pages aray because they would be
                    // empty content with only a redirect anyway.
                    if ($normalized_location !== $redirect_to) {
                        $redirects[str_replace('_', ' ', $normalized_location)] = $redirect_to;
                    }
                } elseif (!in_array($normalized_location, array_keys($pages))) {
                    // Pages we know has content, lets count them!
                    if ($wikiDocument->hasRedirect() === false) {
                        $pages[$normalized_location] = $title;
                    }
                } elseif (in_array($title, $temporary_acceptable_duplicates)) {
                    // Lets not throw, we got that covered.
                } else {
                    // Hopefully we should never encounter this.
                    $previous = $pages[$normalized_location];
                    $duplicatePagesExceptionText = 'We have duplicate entry for %s it '
                                                   .'would be stored in %s which would override content of %s';
                    throw new Exception(sprintf($duplicatePagesExceptionText, $title, $file_path, $previous));
                }

                $output->writeln(PHP_EOL.PHP_EOL);
            } /* End of if (isset($pageNode->title)) */
        } /* End of while ($node = $streamer->getNode()) */

        /*
         * Work some numbers on number of edits
         *
         * - Average
         * - Median
         */
        $total_edits = 0;
        sort($rev_count);
        $edit_average = array_sum($rev_count) / $counter;

        // Calculate median
        $value_in_middle = floor(($counter - 1) / 2);
        if ($counter % 2) {
            // odd number, middle is the median
            $edit_median = $rev_count[$value_in_middle];
        } else {
            // even number, calculate avg of 2 medians
            $low = $rev_count[$value_in_middle];
            $high = $rev_count[$value_in_middle + 1];
            $edit_median = (($low + $high) / 2);
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
        $this->filesystem->dumpFile('reports/numbers.txt', implode($numbers, PHP_EOL));

        $this->filesystem->dumpFile('reports/hundred_revs.txt', implode($moreThanHundredRevs, PHP_EOL));

        natcasesort($translations);
        $this->filesystem->dumpFile('reports/translations.txt', implode(PHP_EOL, $translations));
        natcasesort($directlyOnRoot);
        $this->filesystem->dumpFile('reports/directly_on_root.txt', implode(PHP_EOL, $directlyOnRoot));
        natcasesort($urlsWithContent);
        $this->filesystem->dumpFile('reports/url_all.txt', implode(PHP_EOL, $urlsWithContent));

        natcasesort($urlParts);
        $this->filesystem->dumpFile('reports/url_parts.txt', implode(PHP_EOL, $urlParts));

        // Creating list for https://github.com/webplatform/mediawiki-conversion/issues/2
        ksort($urlPartsAll);
        $urlPartsAllOut = array('All words that exists in an URL, and the different ways they are written (needs harmonizing!):');
        foreach ($urlPartsAll as $urlPartsAllKey => $urlPartsAllRow) {
            $urlPartsAllEntryUnique = array_unique($urlPartsAllRow);
            if (count($urlPartsAllEntryUnique) > 1) {
                $urlPartsAllOut[] = sprintf(' - %s', implode(', ', $urlPartsAllEntryUnique));
            }
        }
        $this->filesystem->dumpFile('reports/url_parts_variants.txt', implode(PHP_EOL, $urlPartsAllOut));

        ksort($redirects, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($sanity_redirs, SORT_NATURAL | SORT_FLAG_CASE);

        $nginx_almost_same_1 = ['# Most likely OK to ignore, but good enough to check if adresses here works'];
        $nginx_almost_same_2 = ['# Most likely OK to ignore, but good enough to check if adresses here works'];
        $nginx_almost_same_casing = [];
        $nginx_redirects_spaces = [];
        $nginx_redirects = [];

        $nginx_esc['Meta:'] = 'Meta/';
        $nginx_esc['WPD:'] = 'WPD/';
        $nginx_esc[':'] = '\\:';
        $nginx_esc['('] = '\\(';
        $nginx_esc[')'] = '\\)';
        $nginx_esc['?'] = '\\?)';
        $nginx_esc[' '] = '(\ |_)'; // Ordering matter, otherwise the () will be escaped and we want them here!

        $rewriteCheck[' '] = '(\ |_)'; // Ordering matter, otherwise the () will be escaped and we want them here!

        $location_spaghetti = [];
        $location_spaghetti_duplicated = [];
        $hopefully_not_duplicate = [];

        $prepare_nginx_redirects = array_merge($sanity_redirs, $redirects);
        foreach ($prepare_nginx_redirects as $url => $redirect_to) {
            // NGINX Case-insensitive redirect? Its done through (?i)! Should be documented!!!
            $new_location = str_replace(array_keys($nginx_esc), $nginx_esc, $url);
            $url_match_attempt = str_replace('(\ |_)', '_', $new_location);
            $work_item = $url.':'.PHP_EOL.'  - new_location: "'.$new_location.'"'.PHP_EOL.'  - url_match_attempt: "'.$url_match_attempt.'"'.PHP_EOL.'  - redirect_to: "'.$redirect_to.'"'.PHP_EOL;
            $duplicate = false;

            if (array_key_exists(strtolower($url), $hopefully_not_duplicate)) {
                $location_spaghetti_duplicated[strtolower($url)] = $work_item;
                $duplicate = true;
            } else {
                $hopefully_not_duplicate[strtolower($url)] = $work_item;
            }
            $location_spaghetti[] = $work_item;

            if ($duplicate === true) {
                $nginx_almost_same_1[] = sprintf('rewrite (?i)^/%s$ /%s break;', $new_location, $redirect_to);
            } elseif ($url_match_attempt === $redirect_to) {
                $nginx_almost_same_2[] = sprintf('rewrite (?i)^/%s$ /%s break;', $new_location, $redirect_to);
            } elseif (strtolower($url_match_attempt) === strtolower($redirect_to)) {
                $nginx_almost_same_casing[] = sprintf('rewrite (?i)^/%s$ /%s break;', $new_location, $redirect_to);
            } elseif (stripos($url, ' ') > 1) {
                $nginx_redirects_spaces[] = sprintf('rewrite (?i)^/%s$ /%s break;', $new_location, $redirect_to);
            } else {
                $nginx_redirects[] = sprintf('rewrite (?i)^/%s$ /%s break;', $new_location, $redirect_to);
            }
        }
        $this->filesystem->dumpFile('reports/location_spaghetti_duplicated.txt', implode(PHP_EOL, $location_spaghetti_duplicated));
        $this->filesystem->dumpFile('reports/location_spaghetti.txt', implode(PHP_EOL, $location_spaghetti));
        $this->filesystem->dumpFile('reports/4_nginx_redirects_spaces.map', implode(PHP_EOL, $nginx_redirects_spaces));
        $this->filesystem->dumpFile('reports/3_nginx_almost_same_1.map', implode(PHP_EOL, $nginx_almost_same_1));
        $this->filesystem->dumpFile('reports/3_nginx_almost_same_2.map', implode(PHP_EOL, $nginx_almost_same_2));
        $this->filesystem->dumpFile('reports/2_nginx_almost_same_casing.map', implode(PHP_EOL, $nginx_almost_same_casing));
        $this->filesystem->dumpFile('reports/1_nginx.map', implode(PHP_EOL, $nginx_redirects));

        $redirects_sanity_out = array('URLs to return new Location (from => to):');
        foreach ($sanity_redirs as $title => $sanitized) {
            $redirects_sanity_out[] = sprintf(' - "%s": "%s"', $title, $sanitized);
        }
        $this->filesystem->dumpFile('reports/redirects_sanity.txt', implode(PHP_EOL, $redirects_sanity_out));

        $redirects_out = array('Redirects (from => to):');
        foreach ($redirects as $url => $redirect_to) {
            $redirects_out[] = sprintf(' - "%s": "%s"', $url, $redirect_to);
        }
        $this->filesystem->dumpFile('reports/redirects.txt', implode(PHP_EOL, $redirects_out));

        if ($listMissed === true) {
            try {
                $missed_out = $this->yaml->serialize($missedIndexes);
            } catch (Exception $e) {
                $missed_out = sprintf('Could not create YAML out of missedIndexes array; Error was %s', $e->getMessage());
            }
            $this->filesystem->dumpFile('reports/missed_retry_argument.txt', 'app/console mediawiki:run 3 --retry='.implode(',', array_keys($missedIndexes)));
            $this->filesystem->dumpFile('reports/missed_entries.yml', 'Missed:'.PHP_EOL.$missed_out);
            $output->writeln('Created missed_retry_argument.txt and missed_entries.yml in reports/ you can try to recover!');
        }
    }
}
