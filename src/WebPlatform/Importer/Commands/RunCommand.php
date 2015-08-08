<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */
namespace WebPlatform\Importer\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Filesystem\Filesystem;
use Prewk\XmlStringStreamer;
use Bit3\GitPhp\GitException;
use WebPlatform\Importer\GitPhp\GitRepository;
use WebPlatform\Importer\Model\MediaWikiDocument;
use WebPlatform\Importer\Converter\MediaWikiToHtml;
use WebPlatform\Importer\Filter\TitleFilter;
use WebPlatform\ContentConverter\Helpers\YamlHelper;
use WebPlatform\ContentConverter\Model\MediaWikiContributor;
use WebPlatform\ContentConverter\Persistency\GitCommitFileRevision;
use SplDoublyLinkedList;
use SimpleXMLElement;
use Exception;
use DomainException;

/**
 * Read and create a summary from a MediaWiki dumpBackup XML file.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class RunCommand extends Command
{
    protected $users = array();

    protected $missed = array();

    /** @var WebPlatform\ContentConverter\Converter\MediaWikiToHtml Symfony Filesystem handler */
    protected $converter;

    /** @var Symfony\Component\Filesystem\Filesystem Symfony Filesystem handler */
    protected $filesystem;

    /** @var Bit3\GitPhp\GitRepository Git Repository handler */
    protected $git;

    /** @var WebPlatform\ContentConverter\Helpers\YamlHelper Yaml Helper instance */
    protected $yaml;

    protected function configure()
    {
        $description = <<<DESCR
                Walk through MediaWiki dumpBackup XML file and run through revisions
                to convert them into static files.

                Script is designed to run in three passes that has to be run in
                this order.

                1.) Handle deleted pages

                    When a Wiki page is moved, MediaWiki allows to leave a redirect behind.
                    The objective of this pass is to put the former content underneath all history
                    such that this pass leaves an empty output directory but with all the deleted
                    file history kept.


                2.) Handle pages that weren’t deleted in history

                    Write history on top of deleted content. That way we won’t get conflicts between
                    content that got deleted from still current content.

                    Beware; This command can take MORE than an HOUR to complete.


                3.) Convert content

                    Loop through ALL documents that still has content, take latest revision and pass it through
                    a converter.

DESCR;
        $this
            ->setName('mediawiki:run')
            ->setDescription($description)
            ->setDefinition(
                [
                    new InputArgument('pass', InputArgument::REQUIRED, 'The pass number: 1,2,3', null),
                    new InputOption('xml-source', '', InputOption::VALUE_OPTIONAL, 'What file to read from. Argument is relative from data/ folder from this directory (e.g. foo.xml in data/foo.xml)', 'dumps/main_full.xml'),
                    new InputOption('max-revs', '', InputOption::VALUE_OPTIONAL, 'Do not run full import, limit it to maximum of revisions per page ', 0),
                    new InputOption('max-pages', '', InputOption::VALUE_OPTIONAL, 'Do not run  full import, limit to a maximum of pages', 0),
                    new InputOption('missed', '', InputOption::VALUE_NONE, 'Give XML node indexes of missed conversion so we can run a 3rd pass only for them'),
                    new InputOption('namespace-prefix', '', InputOption::VALUE_OPTIONAL, 'If not against main MediaWiki namespace, set prefix (e.g. Meta) so we can create a git repo with all contents on root so that we can use export as a submodule.', false),
                    new InputOption('resume-at', '', InputOption::VALUE_OPTIONAL, 'Resume run at a specific XML document index number ', 0),
                ]
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->yaml = new YamlHelper();

        $this->users = [];
        $this->filesystem = new Filesystem();
        $this->titleFilter = new TitleFilter();

        $passNbr = (int) $input->getArgument('pass');

        $resumeAt = (int) $input->getOption('resume-at');

        $xmlSource = $input->getOption('xml-source');
        $maxHops = (int) $input->getOption('max-pages');   // Maximum number of pages we go through
        $revMaxHops = (int) $input->getOption('max-revs'); // Maximum number of revisions per page we go through
        $listMissed = $input->getOption('missed');
        $namespacePrefix = $input->getOption('namespace-prefix');

        $counter = 0;    // Increment the number of pages we are going through
        $redirects = [];
        $pages = [];
        $urlParts = [];

        if ($listMissed === true && $passNbr === 3) {
            $missed_file = DATA_DIR.'/missed.yml';
            if (realpath($missed_file) === false) {
                throw new Exception(sprintf('Could not find missed file at %s', $missed_file));
            }
            $missedFileContents = file_get_contents($missed_file);
            try {
                $missed = $this->yaml->unserialize($missedFileContents);
            } catch (Exception $e) {
                throw new Exception(sprintf('Could not get file %s contents to be parsed as YAML. Is it in YAML format?', $missed_file), null, $e);
            }
            if (!isset($missed['missed'])) {
                throw new Exception('Please ensure missed.yml has a list of titles under a "missed:" top level key');
            }
            $this->missed = $missed['missed'];
        } elseif ($listMissed === true && $passNbr !== 3) {
            throw new DomainException('Missed option is only supported at 3rd pass');
        }

        $repoInitialized = (realpath(GIT_OUTPUT_DIR.'/.git') === false) ? false : true;
        $this->git = new GitRepository(realpath(GIT_OUTPUT_DIR));
        if ($repoInitialized === false) {
            $this->git->init()->execute();
        }

        if ($passNbr === 3) {

            /*
             * Your MediaWiki API URL
             *
             * https://www.mediawiki.org/wiki/API:Data_formats
             * https://www.mediawiki.org/wiki/API:Parsing_wikitext
             **/
            $apiUrl = getenv('MEDIAWIKI_API_ORIGIN').'/w/api.php?action=parse&pst=1&utf8=';
            $apiUrl .= '&prop=indicators|text|templates|categories|links|displaytitle';
            $apiUrl .= '&disabletoc=true&disablepp=true&disableeditsection=true&preview=true&format=json&page=';

            // We are at conversion pass, instantiate our Converter!
            $this->converter = new MediaWikiToHtml();
            $this->converter->setApiUrl($apiUrl);
        }

        /* -------------------- Author --------------------
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
            $uid = (int) $u['user_id'];
            $this->users[$uid] = new MediaWikiContributor($u);
            unset($u); // Dont fill too much memory, if that helps.
        }
        /* -------------------- /Author -------------------- **/

        /* -------------------- XML source -------------------- **/
        $file = realpath(DATA_DIR.'/'.$xmlSource);
        if ($file === false) {
            throw new Exception(sprintf('Cannot run script, source XML file ./data/%s could not be found', $xmlSource));
        }
        $streamer = XmlStringStreamer::createStringWalkerParser($file);
        /* -------------------- /XML source -------------------- **/

        while ($node = $streamer->getNode()) {

            /*
             * 3rd pass, handle interruption by telling where to resume work.
             *
             * This is useful if job stopped and you want to resume work back at a specific point.
             */
            if ($counter < $resumeAt) {
                ++$counter;
                continue;
            }

            /*
             * Limit the number of pages we’ll work on.
             *
             * Useful if you want to test conversion script without going through all the content.
             */
            if ($maxHops > 0 && $maxHops === $counter) {
                $output->writeln(sprintf('Reached desired maximum of %d documents', $maxHops).PHP_EOL);
                break;
            }

            $pageNode = new SimpleXMLElement($node);
            if (isset($pageNode->title)) {
                $wikiDocument = new MediaWikiDocument($pageNode);
                //
                // While importing WPD, Meta and Users namespaces, we were writing into 'out/' directly!
                //
                // See note at similar location in SummaryCommand for rationale.
                //
                $writeTo = ($namespacePrefix === false) ? 'out/content/' : 'out/';
                $persistable = new GitCommitFileRevision($wikiDocument, $writeTo, '.md');

                $title = $wikiDocument->getTitle();
                $normalized_location = $wikiDocument->getName();
                $file_path = $this->titleFilter->filter($persistable->getName());
                $file_path = ($namespacePrefix === false) ? $file_path : str_replace(sprintf('%s/', $namespacePrefix), '', $file_path);
                $redirect_to = $this->titleFilter->filter($wikiDocument->getRedirect()); // False if not a redirect, string if it is

                $is_translation = $wikiDocument->isTranslation();
                $language_code = $wikiDocument->getLanguageCode();
                $language_name = $wikiDocument->getLanguageName();

                if ($listMissed === true && !in_array($normalized_location, $this->missed)) {
                    ++$counter;
                    continue;
                }

                if ($passNbr === 3 && $wikiDocument->hasRedirect() === false) {
                    $random = rand(2, 5);
                    $output->writeln(PHP_EOL.sprintf('--- sleep for %d to not break production ---', $random));
                    sleep($random);
                }

                $revs = $wikiDocument->getRevisions()->count();

                $output->writeln(sprintf('"%s":', $title));
                $output->writeln(sprintf('  - index: %d', $counter));
                $output->writeln(sprintf('  - normalized: %s', $normalized_location));
                $output->writeln(sprintf('  - file: %s', $file_path));

                if ($wikiDocument->hasRedirect() === true) {
                    $output->writeln(sprintf('  - redirect_to: %s', $redirect_to));
                }

                if ($is_translation === true) {
                    $output->writeln(sprintf('  - lang: %s (%s)', $language_code, $language_name));
                }

                /*
                 * Merge deleted content history under current content.
                 *
                 * 1st pass: Only those with redirects (i.e. deleted pages). Should leave an empty out/ directory!
                 * 2nd pass: Only those without redirects (i.e. current content).
                 * 3nd pass: Only for those without redirects, they are going to get the latest version passed through the convertor
                 */
                if ($wikiDocument->hasRedirect() === false && $passNbr === 1) {
                    // Skip all NON redirects for pass 1
                    $output->writeln(sprintf('  - skip: Document %s WITHOUT redirect, at pass 1 (handling redirects)', $title).PHP_EOL.PHP_EOL);
                    ++$counter;
                    continue;
                } elseif ($wikiDocument->hasRedirect() && $passNbr === 2) {
                    // Skip all redirects for pass 2
                    $output->writeln(sprintf('  - skip: Document %s WITH redirect, at pass 2 (handling non redirects)', $title).PHP_EOL.PHP_EOL);
                    ++$counter;
                    continue;
                } elseif ($wikiDocument->hasRedirect() && $passNbr === 3) {
                    // Skip all redirects for pass 2
                    $output->writeln(sprintf('  - skip: Document %s WITH redirect, at pass 3', $title).PHP_EOL.PHP_EOL);
                    ++$counter;
                    continue;
                }

                if ($passNbr < 1 || $passNbr > 3) {
                    throw new DomainException('This command has only three pases.');
                }

                foreach (explode('/', $normalized_location) as $urlDepth => $urlPart) {
                    $urlParts[strtolower($urlPart)] = $urlPart;
                }

                $revList = $wikiDocument->getRevisions();
                $revLast = $wikiDocument->getLatest();
                $revCounter = 0;

                if ($passNbr === 3) {

                    // Overwriting $revList for last pass we’ll
                    // use for conversion.
                    $revList = new SplDoublyLinkedList();

                    // Pass some data we already have so we can
                    // get it in the converted document.
                    if ($is_translation === true) {
                        $revLast->setFrontMatter(array('lang' => $language_code));
                    }
                    $revList->push($revLast);
                } else {
                    $output->writeln(sprintf('  - revs: %d', $revs));
                    $output->writeln(sprintf('  - revisions:'));
                }

                /* ----------- REVISIONS --------------- **/
                for ($revList->rewind(); $revList->valid(); $revList->next()) {
                    if ($revMaxHops > 0 && $revMaxHops === $revCounter) {
                        $output->writeln(sprintf('    - stop: Reached maximum %d revisions', $revMaxHops).PHP_EOL.PHP_EOL);
                        break;
                    }

                    $wikiRevision = $revList->current();

                    /* -------------------- Author -------------------- **/
                    // An edge case where MediaWiki may give author as user_id 0, even though we dont have it
                    // so we’ll give the first user instead.
                    $contributor_id = ($wikiRevision->getContributorId() === 0) ? 1 : $wikiRevision->getContributorId();

                    /*
                     * Fix duplicates and merge them as only one.
                     *
                     * Please adjust to suit your own.
                     *
                     * Queried using jq;
                     *
                     *     cat data/users.json | jq '.[]|select(.user_real_name == "Renoir Boulanger")'
                     */
                    //if (in_array($contributor_id, [172943, 173060])) {
                    //    $contributor_id = 10080;
                    //}

                    if (isset($this->users[$contributor_id])) {
                        $contributor = clone $this->users[$contributor_id]; // We want a copy, because its specific to here only anyway.
                        $wikiRevision->setContributor($contributor, false);
                    } else {
                        // In case we didn’t find data for $this->users[$contributor_id]
                        $contributor = clone $this->users[1]; // We want a copy, because its specific to here only anyway.
                        $wikiRevision->setContributor($contributor, false);
                    }
                    /* -------------------- /Author -------------------- **/

                    // Lets handle conversion only at 3rd pass.
                    if ($passNbr === 3) {
                        try {
                            $revision = $this->converter->apply($wikiRevision);
                            $revision->setTitle($wikiDocument->getLastTitleFragment());
                        } catch (Exception $e) {
                            $output->writeln(sprintf('    - ERROR: %s, left a note in errors/%d.txt', $e->getMessage(), $counter));
                            $this->filesystem->dumpFile(sprintf('errors/%d.txt', $counter), $e->getMessage());
                            //throw new Exception('Debugging why API call did not work.', 0, $e); // DEBUG
                            ++$counter;
                            continue;
                        }

                        // user_id 10080 is Renoirb (yours truly)
                        $revision->setAuthor($this->users[10080]);
                        $revision_id = $revLast->getId();
                    } else {
                        $revision = $wikiRevision;
                        $revision_id = $wikiRevision->getId();
                        $output->writeln(sprintf('    - id: %d', $revision_id));
                        $output->writeln(sprintf('      index: %d', $revCounter));
                    }

                    $persistArgs = $persistable->setRevision($revision)->getArgs();
                    if ($passNbr < 3) {
                        foreach ($persistArgs as $argKey => $argVal) {
                            if ($argKey === 'message') {
                                $argVal = mb_strimwidth($argVal, strpos($argVal, ': ') + 2, 100);
                            }
                            $output->writeln(sprintf('      %s: %s', $argKey, $argVal));
                        }
                    }

                    $removeFile = false;
                    if ($passNbr < 3 && $revLast->getId() === $wikiRevision->getId() && $wikiDocument->hasRedirect()) {
                        $output->writeln('      is_last_and_has_redirect: True');
                        $removeFile = true;
                    }

                    $persistable->setRevision($revision);
                    $this->filesystem->dumpFile($file_path, (string) $persistable);
                    try {
                        $this->git
                            ->add()
                            // Make sure out/ matches what we set at GitCommitFileRevision constructor.
                            ->execute(preg_replace('/^out\//', '', $file_path));
                    } catch (GitException $e) {
                        $message = sprintf('Could not add file "%s" with title "%s" for revision %d', $file_path, $title, $revision_id);
                        throw new Exception($message, null, $e);
                    }

                    if ($passNbr < 3) {

                        // We won’t expose all WebPlatform user emails to the public. Instead,
                        // we’ll create a bogus email alias based on their MediaWiki username.
                        $real_name = $wikiRevision->getContributor()->getRealName();
                        $username = $wikiRevision->getContributor()->getName();
                        $email = sprintf('%s@%s', $username, COMMITER_ANONYMOUS_DOMAIN);
                        $author_overload = sprintf('%s <%s>', $real_name, $email);

                        try {
                            $this->git
                                ->commit()
                                // In order to enforce git to use the same commiter data
                                // than the author’s we had to overload CommitCommandBuilder
                                // class.
                                //
                                // In WebPlatform\Importer\GitPhp\CommitCommandBuilder, we
                                // overload [date, author] methods so we can inject the same
                                // matching GIT_COMMITTER_* values at commit time.
                                ->message($persistArgs['message'])
                                ->author('"'.$author_overload.'"')
                                ->date('"'.$persistArgs['date'].'"')
                                ->allowEmpty()
                                ->execute();
                        } catch (GitException $e) {
                            var_dump($this->git);
                            $message = sprintf('Could not commit for revision %d', $revision_id);
                            throw new Exception($message, null, $e);
                        }

                        if ($removeFile === true) {
                            try {
                                $this->git
                                    ->rm()
                                    // Make sure out/ matches what we set at GitCommitFileRevision constructor.
                                    ->execute(preg_replace('/^out\//', '', $file_path));
                            } catch (GitException $e) {
                                $message = sprintf('Could remove %s at revision %d', $file_path, $revision_id);
                                throw new Exception($message, null, $e);
                            }

                            $this->git
                                ->commit()
                                ->message('Remove file; '.$persistArgs['message'])
                                // ... no need to worry here. We overloaded author, date
                                // remember?
                                ->author('"'.$author_overload.'"')
                                ->date('"'.$persistArgs['date'].'"')
                                ->allowEmpty()
                                ->execute();

                            $this->filesystem->remove($file_path);
                        }
                    } /* End of $passNubr === 3 */

                    ++$revCounter;
                }
                /* ----------- REVISIONS --------------- **/
                $output->writeln(PHP_EOL);
            }
            ++$counter;
        }

        if ($passNbr === 3) {
            $output->writeln('3rd pass. One. Commit.'.PHP_EOL.PHP_EOL);
            //$output->writeln('OK!'); // DEBUG
            //return; // DEBUG
            try {
                $this->git
                    ->commit()
                    ->message($revision->getComment())
                    ->execute();
            } catch (GitException $e) {
                var_dump($this->git);
                $message = sprintf('Could not commit for revision %d', $revision_id);
                throw new Exception($message, null, $e);
            }
        }
    }
}
