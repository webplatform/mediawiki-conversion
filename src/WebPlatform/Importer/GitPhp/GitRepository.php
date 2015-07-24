<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */

namespace WebPlatform\Importer\GitPhp;

use Bit3\GitPhp\GitRepository as BaseGitRepository;

/**
 * Extends Git Repository so we can inject our own shell environment to Process
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class GitRepository extends BaseGitRepository
{
    /**
     * Create commit command.
     *
     * @return CommitCommandBuilder
     */
    public function commit()
    {
        return new CommitCommandBuilder($this);
    }
}
