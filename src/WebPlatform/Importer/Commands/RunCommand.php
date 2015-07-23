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
use Bit3\GitPhp\GitRepository;
use Bit3\GitPhp\GitException;

use WebPlatform\ContentConverter\Model\MediaWikiContributor;
use WebPlatform\ContentConverter\Persistency\GitCommitFileRevision;

use WebPlatform\Importer\Model\MediaWikiDocument;
use WebPlatform\Importer\Converter\MediaWikiToMarkdown;
use WebPlatform\Importer\Filter\TitleFilter;

use SplDoublyLinkedList;
use SimpleXMLElement;
use Exception;
use DomainException;

/**
 * Read and create a summary from a MediaWiki dumpBackup XML file
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class RunCommand extends Command
{
    protected $users = array();

    /** @var WebPlatform\ContentConverter\Converter\MediaWikiToMarkdown Symfony Filesystem handler */
    protected $converter;

    /** @var Symfony\Component\Filesystem\Filesystem Symfony Filesystem handler */
    protected $filesystem;

    /** @var Bit3\GitPhp\GitRepository Git Repository handler */
    protected $git;

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
                    new InputOption('max-revs', '', InputOption::VALUE_OPTIONAL, 'Do not run full import, limit it to maximum of revisions per page ', 0),
                    new InputOption('max-pages', '', InputOption::VALUE_OPTIONAL, 'Do not run  full import, limit to a maximum of pages', 0),
                    new InputOption('git', '', InputOption::VALUE_NONE, 'Do run git import (write to filesystem is implicit), defaults to false'),
                ]
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->users = [];
        $this->filesystem = new Filesystem;
        $this->titleFilter = new TitleFilter;

        $useGit = $input->getOption('git');
        $maxHops = (int) $input->getOption('max-pages');    // Maximum number of pages we go through
        $revMaxHops = (int) $input->getOption('max-revs'); // Maximum number of revisions per page we go through
        $passNbr = (int) $input->getArgument('pass');

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

        if ($passNbr === 3) {
            // We are at conversion pass, instantiate our Converter!
            $this->converter = new MediaWikiToMarkdown;
        }

        while ($node = $streamer->getNode()) {
            if ($maxHops > 0 && $maxHops === $counter) {
                $output->writeln(sprintf('Reached desired maximum of %d documents', $maxHops).PHP_EOL.PHP_EOL);
                break;
            }
            $pageNode = new SimpleXMLElement($node);
            if (isset($pageNode->title)) {

                $wikiDocument = new MediaWikiDocument($pageNode);
                $persistable = new GitCommitFileRevision($wikiDocument, 'out/content/', '.md');

                $title = $wikiDocument->getTitle();
                $normalized_location = $wikiDocument->getName();
                $file_path  = $this->titleFilter->filter($persistable->getName());
                $has_redirect = $wikiDocument->hasRedirect();
                $redirect_to = $this->titleFilter->filter($wikiDocument->getRedirect()); // False if not a redirect, string if it is

                $is_translation = $wikiDocument->isTranslation();
                $language_code = $wikiDocument->getLanguageCode();
                $language_name = $wikiDocument->getLanguageName();

                $output->writeln(sprintf('"%s":', $title));

                $output->writeln(sprintf('  - normalized: %s', $normalized_location));
                $output->writeln(sprintf('  - file: %s', $file_path));

                if ($has_redirect === true) {
                    $output->writeln(sprintf('  - redirect_to: %s', $redirect_to));
                }

                /**
                 * Objective; merge deleted content history under current content.
                 *
                 * 1st pass: Only those with redirects (i.e. deleted pages). Should leave an empty out/ directory!
                 * 2nd pass: Only those without redirects (i.e. current content).
                 * 3nd pass: Only for those without redirects, they are going to get the latest version passed through the convertor
                 */
                if ($wikiDocument->hasRedirect() === false && $passNbr === 1) {
                    // Skip all NON redirects for pass 1
                    $output->writeln(sprintf('  - skip: Document %s WITHOUT redirect, at pass 1 (handling redirects)', $title).PHP_EOL.PHP_EOL);
                    continue;
                } elseif ($wikiDocument->hasRedirect() && $passNbr === 2) {
                    // Skip all redirects for pass 2
                    $output->writeln(sprintf('  - skip: Document %s WITH redirect, at pass 2 (handling non redirects)', $title).PHP_EOL.PHP_EOL);
                    continue;
                } elseif ($wikiDocument->hasRedirect() && $passNbr === 3) {
                    // Skip all redirects for pass 2
                    $output->writeln(sprintf('  - skip: Document %s WITH redirect, at pass 3', $title).PHP_EOL.PHP_EOL);
                    continue;
                }

                if ($passNbr < 1 || $passNbr > 3) {
                    throw new DomainException('This command has only three pases.');
                }

                if ($is_translation === true) {
                    $output->writeln(sprintf('  - lang: %s', $language_code));
                }

                foreach (explode("/", $normalized_location) as $urlDepth => $urlPart) {
                    $urlParts[strtolower($urlPart)] = $urlPart;
                }

                $revs  = $wikiDocument->getRevisions()->count();
                $output->writeln(sprintf('  - index: %d', $counter));
                $output->writeln(sprintf('  - revs: %d', $revs));
                $output->writeln(sprintf('  - revisions:'));

                $revList = $wikiDocument->getRevisions();
                $revLast = $wikiDocument->getLatest();
                $revCounter = 0;

                if ($passNbr === 3) {
                    // Overwriting $revList for last pass we’ll
                    // use for conversion.
                    $revList = new SplDoublyLinkedList;

                    // Pass some data we already have so we can
                    // get it in the converted document.
                    if ($is_translation === true) {
                        $revLast->setFrontMatter(array('lang'=>$language_code));
                    }
                    $revList->push($revLast);
                }

                /** ----------- REVISION --------------- **/
                for ($revList->rewind(); $revList->valid(); $revList->next()) {
                    if ($revMaxHops > 0 && $revMaxHops === $revCounter) {
                        $output->writeln(sprintf('    - stop: Reached maximum %d revisions', $revMaxHops).PHP_EOL.PHP_EOL);
                        break;
                    }

                    $wikiRevision = $revList->current();

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

                    // Lets handle conversion only at 3rd pass.
                    if ($passNbr === 3) {
                        try {
                            $revision = $this->converter->apply($wikiRevision);
                        } catch (Exception $e) {
                            throw new Exception(sprintf('Could not do conversion of %s', $wikiDocument->getTitle()), null, $e);
                        }

                        // user_id 10080 is Renoirb (yours truly)
                        $revision->setAuthor($this->users[10080]);
                        $revision_id = $revLast->getId();
                        $output->writeln(sprintf('    - id: %d', $revision_id));
                    } else {
                        $revision = $wikiRevision;
                        $revision_id = $wikiRevision->getId();
                        $output->writeln(sprintf('    - id: %d', $revision_id));
                        $output->writeln(sprintf('      index: %d', $revCounter));
                    }

                    $persistArgs = $persistable->setRevision($revision)->getArgs();
                    foreach ($persistArgs as $argKey => $argVal) {
                        if ($argKey === 'message') {
                            $argVal = mb_strimwidth($argVal, strpos($argVal, ': ') + 2, 100);
                        }
                        $output->writeln(sprintf('      %s: %s', $argKey, $argVal));

                    }

                    $removeFile = false;
                    if ($passNbr < 3 && $revLast->getId() === $wikiRevision->getId() && $wikiDocument->hasRedirect()) {
                        $output->writeln('      is_last_and_has_redirect: True');
                        $removeFile = true;
                    }

                    if ($useGit === true) {
                        $persistable->setRevision($revision);

                        $this->filesystem->dumpFile($file_path, (string) $persistable);
                        try {
                            $this->git
                                ->add()
                                // Make sure out/ matches what we set at GitCommitFileRevision constructor.
                                ->execute(preg_replace('/^out\//', '', $file_path));
                        } catch (GitException $e) {
                            $message = sprintf('Could not add file %s for revision %d', $file_path, $revision_id);
                            throw new Exception($message, null, $e);
                        }

                        if ($passNbr < 3) {
                            try {
                                $this->git
                                    ->commit()
                                    ->message($persistArgs['message'])
                                    ->author('"'.$persistArgs['author'].'"')
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
                                    ->author('"'.$persistArgs['author'].'"')
                                    ->date('"'.$persistArgs['date'].'"')
                                    ->allowEmpty()
                                    ->execute();

                                $this->filesystem->remove($file_path);
                            }
                        }
                    }

                    ++$revCounter;
                }
                /** ----------- REVISION --------------- **/

                $output->writeln(PHP_EOL.PHP_EOL);
                ++$counter;
            }
        }

        if ($passNbr === 3) {
            $output->writeln('Third pass. One. Commit.'.PHP_EOL.PHP_EOL);
            try {
                $this->git
                    ->commit()
                    ->message($revision->getComment())
                    ->author('"'.$persistArgs['author'].'"')
                    ->date('"'.$persistArgs['date'].'"')
                    ->allowEmpty()
                    ->execute();

            } catch (GitException $e) {
                var_dump($this->git);
                $message = sprintf('Could not commit for revision %d', $revision_id);
                throw new Exception($message, null, $e);
            }
        }
    }
}
