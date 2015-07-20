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
use Bit3\GitPhp\GitRepository;
use Bit3\GitPhp\GitException;

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
class RunCommand extends Command
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
                summarize revisions give details about the
                wiki contents.

                ...

DESCR;
        $this
            ->setName('mediawiki:run')
            ->setDescription($description)
            ->setDefinition(
                [
                    new InputOption('max-revs', '', InputOption::VALUE_OPTIONAL, 'Do not run full import, limit it to maximum of revisions per document ', 0),
                    new InputOption('max-pages', '', InputOption::VALUE_OPTIONAL, 'Do not run  full import, limit to a maximum of documents', 0),
                    new InputOption('git', '', InputOption::VALUE_NONE, 'Do run git import (write to filesystem is implicit), defaults to false'),
                ]
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->users = [];
        $this->filesystem = new Filesystem;

        $useGit = $input->getOption('git');
        $maxHops = (int) $input->getOption('max-pages');    // Maximum number of pages we go through
        $revMaxHops = (int) $input->getOption('max-revs'); // Maximum number of revisions per page we go through

        $counter = 0;    // Increment the number of pages we are going through
        $redirects = [];
        $pages = [];
        $problematicAuthors = [];
        $urlParts = [];

        if ($useGit === true) {
            $repoInitialized = (realpath(GIT_OUTPUT_DIR.'/.git') === false)?false:true;
            //die(var_dump(array($repoInitialized, realpath(GIT_OUTPUT_DIR))));
            $this->git = new GitRepository(realpath(GIT_OUTPUT_DIR));
            if ($repoInitialized === false) {
                $this->git->init()->execute();
            }
        }

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

                foreach (explode("/", $normalized_location) as $urlDepth => $urlPart) {
                    $urlParts[strtolower($urlPart)] = $urlPart;
                }

                $output->writeln(sprintf('  - index: %d', $counter));
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

                    $author_string = (string) $wikiRevision->getContributor();
                    $timestamp = $wikiRevision->getTimestamp()->format(DateTime::RFC2822);

                    $comment = $wikiRevision->getComment();
                    $comment_shorter = mb_strimwidth($comment, strpos($comment, ': ') + 2, 100);

                    $output->writeln(sprintf('    - id: %d', $revision_id));
                    $output->writeln(sprintf('      rev_counter: %d', $revCounter));
                    $output->writeln(sprintf('      timestamp: "%s"', $timestamp));
                    $output->writeln(sprintf('      author: "%s"', $author_string));
                    $output->writeln(sprintf('      comment: "%s"', $comment_shorter));

                    $removeFile = false;
                    if ($revLast->getId() === $wikiRevision->getId() && $wikiDocument->hasRedirect()) {
                        $output->writeln('      is_last_and_has_redirect: True');
                        $removeFile = true;
                    }

                    if ($useGit === true) {
                        $persistable->setRevision($wikiRevision);

                        $this->filesystem->dumpFile($persistable->getName(), (string) $persistable);
                        try {
                            $this->git
                                ->add()
                                // Make sure out/ matches what we set at GitCommitFileRevision constructor.
                                ->execute(preg_replace('/^out\//', '', $persistable->getName()));
                        } catch (GitException $e) {
                            $message = sprintf('Could not add file %s for revision %d', $persistable->getName(), $revision_id);
                            throw new Exception($message, null, $e);
                        }

                        try {
                            $this->git
                                ->commit()
                                ->message('"'.$comment.'"')
                                ->author('"'.$author_string.'"')
                                ->date('"'.$timestamp.'"')
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
                                    ->execute(preg_replace('/^out\//', '', $persistable->getName()));
                            } catch (GitException $e) {
                                $message = sprintf('Could remove %s at revision %d', $persistable->getName(), $revision_id);
                                throw new Exception($message, null, $e);
                            }

                            $this->git
                                ->commit()
                                ->message('"Remove file; '.$comment.'"')
                                ->author('"'.$author_string.'"')
                                ->date('"'.$timestamp.'"')
                                ->allowEmpty()
                                ->execute();

                            $this->filesystem->remove($persistable->getName());
                        }
                    }

                    ++$revCounter;
                }
                /** ----------- REVISION --------------- **/

                $output->writeln(PHP_EOL.PHP_EOL);
                ++$counter;
            }
        }
    }
}
