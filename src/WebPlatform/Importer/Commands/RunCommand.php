<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */
namespace WebPlatform\Importer\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Bit3\GitPhp\GitException;
use WebPlatform\ContentConverter\Persistency\GitCommitFileRevision;
use WebPlatform\ContentConverter\Model\MediaWikiApiResponseArray;
use WebPlatform\ContentConverter\Model\HtmlRevision;
use WebPlatform\Importer\Converter\MediaWikiToHtml;
use WebPlatform\Importer\Model\MediaWikiDocument;
use WebPlatform\Importer\GitPhp\GitRepository;
use WebPlatform\Importer\Filter\TitleFilter;
use SplDoublyLinkedList;
use SimpleXMLElement;
use DomainException;
use Exception;

/**
 * Read and create a summary from a MediaWiki dumpBackup XML file.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class RunCommand extends AbstractImporterCommand
{
    /** @var WebPlatform\ContentConverter\Converter\ConverterInterface Converter instance */
    protected $converter;

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
                    new InputOption('missed', '', InputOption::VALUE_NONE, 'Give XML node indexes of missed conversion so we can run a 3rd pass only for them'),
                    new InputOption('max-revs', '', InputOption::VALUE_OPTIONAL, 'Do not run full import, limit it to maximum of revisions per page ', 0),
                    new InputOption('max-pages', '', InputOption::VALUE_OPTIONAL, 'Do not run  full import, limit to a maximum of pages', 0),
                    new InputOption('namespace-prefix', '', InputOption::VALUE_OPTIONAL, 'If not against main MediaWiki namespace, set prefix (e.g. Meta) so we can create a git repo with all contents on root so that we can use export as a submodule.', false),
                    new InputOption('resume-at', '', InputOption::VALUE_OPTIONAL, 'Resume run at a specific XML document index number ', 0),
                ]
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        $passNbr = (int) $input->getArgument('pass');

        $xmlSource = $input->getOption('xml-source');
        $listMissed = $input->getOption('missed');

        $maxHops = (int) $input->getOption('max-pages');   // Maximum number of pages we go through
        $revMaxHops = (int) $input->getOption('max-revs'); // Maximum number of revisions per page we go through
        $namespacePrefix = $input->getOption('namespace-prefix');

        $resumeAt = (int) $input->getOption('resume-at');

        $redirects = [];
        $pages = [];

        if ($listMissed === true && $passNbr === 3) {
            $this->loadMissed(DATA_DIR.'/missed.yml');
        } elseif ($listMissed === true && $passNbr !== 3) {
            throw new DomainException('Missed option is only supported at 3rd pass');
        }

        $repoInitialized = (realpath(GIT_OUTPUT_DIR.'/.git') === false) ? false : true;
        $this->git = new GitRepository(realpath(GIT_OUTPUT_DIR));
        if ($repoInitialized === false) {
            $this->git->init()->execute();
        }

        if ($passNbr === 3) {
            // We are at conversion pass, instantiate our Converter!
            // instanceof WebPlatform\ContentConverter\Converter\ConverterInterface
            $this->converter = new MediaWikiToHtml();
            $this->initMediaWikiHelper('parse');
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

                /*
                 * Handle interruption by telling where to resume work.
                 *
                 * This is useful if job stopped and you want to resume work back at a specific point.
                 */
                if ($counter < $resumeAt) {
                    continue;
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

                /*
                 * This is when we want only to pass through files described in data/missed.yml
                 *
                 * Much useful if you want to make slow API requests and not run the import again.
                 */
                if ($listMissed === true && !in_array($normalized_location, $this->missed)) {
                    continue;
                }

                /*
                 * At 3rd pass, let’s not make API requests to documents we know are redirects
                 * and therefore empty.
                 */
                if ($passNbr === 3 && $wikiDocument->hasRedirect() === false) {
                    //$random = rand(2, 5);
                    //$output->writeln(PHP_EOL.sprintf('--- sleep for %d to not break production ---', $random));
                    //sleep($random);
                }

                $output->writeln(sprintf('"%s":', $title));
                $output->writeln(sprintf('  - id: %d', $wikiDocument->getId()));
                $output->writeln(sprintf('  - index: %d', $counter));
                $output->writeln(sprintf('  - normalized: %s', $normalized_location));
                $output->writeln(sprintf('  - file: %s', $file_path));

                if ($wikiDocument->isTranslation() === true) {
                    $output->writeln(sprintf('  - lang: %s (%s)', $language_code, $language_name));
                }

                if ($wikiDocument->hasRedirect() === true) {
                    $output->writeln(sprintf('  - redirect_to: %s', $redirect_to));
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

                if ($passNbr === 3) {
                    // Overwriting $revList for last pass we’ll
                    // use for conversion.
                    $revList = new SplDoublyLinkedList();

                    // Pass some data we already have so we can
                    // get it in the converted document.
                    if ($wikiDocument->isTranslation() === true) {
                        $revLast->setFrontMatter(array('lang' => $language_code));
                    }
                    $revList->push($revLast);
                } else {
                    $output->writeln(sprintf('  - revs: %d', $revs));
                    $output->writeln(sprintf('  - revisions:'));
                }

                /* ----------- REVISIONS --------------- **/
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

                    /*
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

                    // Lets handle conversion only at 3rd pass.
                    if ($passNbr === 3) {
                        try {
                            /*
                             * We are at third pass and in this case we got to
                             * create a revision. The author is therefore the person
                             * who’s running the script. Let’s define it.
                             */
                            $contributor_id = getenv('MEDIAWIKI_USERID');
                            $contributor = clone $this->users[$contributor_id]; // We want a copy, because its specific to here only anyway.

                            /* @var MediaWikiApiResponseArray object to work with */
                            $respObj = $this->fetchDocument($wikiDocument);
                            $revision = new HtmlRevision($respObj);
                            $revision->setTitle($wikiDocument->getLastTitleFragment());
                        } catch (Exception $e) {
                            $output->writeln(sprintf('    - ERROR: %s, left a note in errors/%d.txt', $e->getMessage(), $counter));
                            $this->filesystem->dumpFile(sprintf('errors/%d.txt', $counter), $e->getMessage());
                            throw new Exception('Debugging why API call did not work.', 0, $e); // DEBUG
                            continue;
                        }

                        $revision->setAuthor($contributor);
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
                        $email = sprintf('%s@%s', $username, getenv('COMMITER_ANONYMOUS_DOMAIN'));
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
                }
                /* ----------- REVISIONS --------------- **/
                $output->writeln(PHP_EOL);
            }
        }
    }
}
