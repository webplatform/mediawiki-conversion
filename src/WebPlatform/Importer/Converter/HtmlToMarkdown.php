<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */
namespace WebPlatform\Importer\Converter;

use WebPlatform\ContentConverter\Converter\ConverterInterface;
use WebPlatform\ContentConverter\Model\AbstractRevision;
use WebPlatform\ContentConverter\Model\HtmlRevision;

/**
 * HTML to Markdown converter using Markdownify PHP library.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class HtmlToMarkdown implements ConverterInterface
{

    /**
     * Apply Wikitext rewrites.
     *
     * @param AbstractRevision $revision Input we want to transfer into Markdown
     *
     * @return AbstractRevision
     */
    public function apply(AbstractRevision $revision)
    {
        if ($revision instanceof HtmlRevision) {

        }

        return $revision;
    }
}
