<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */

namespace WebPlatform\Importer\GitPhp;

use Bit3\GitPhp\Command\CommitCommandBuilder as BaseCommitCommandBuilder;

/**
 * Commit command builder.
 *
 * Let’s override GIT_COMMITTER_* shell environment variables
 * with the ones of the original authors.
 */
class CommitCommandBuilder extends BaseCommitCommandBuilder
{
    public function author($author)
    {
        preg_match('/^(.*)\ <(.*)>/', $author, $matches);

        if (isset($matches[1])) {
            $this->processBuilder->setEnv('GIT_COMMITTER_NAME', $matches[1]);
        }
        if (isset($matches[2])) {
            $this->processBuilder->setEnv('GIT_COMMITTER_EMAIL', $matches[2]);
        }

        return parent::author($author);
    }

    public function date($date)
    {
        $this->processBuilder->setEnv('GIT_COMMITTER_DATE', $date);

        return parent::date($date);
    }
}
