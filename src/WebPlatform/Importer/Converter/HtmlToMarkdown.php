<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */
namespace WebPlatform\Importer\Converter;

use WebPlatform\ContentConverter\Converter\ConverterInterface;
use WebPlatform\ContentConverter\Model\AbstractRevision;
use WebPlatform\ContentConverter\Model\MarkdownRevision;
use WebPlatform\Importer\Model\HtmlRevision;

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
            // Since MediaWikiApiParseActionResponse
            // implements \JsonSerializable
            $dto = $revision->getApiResponseObject()->jsonSerialize();

            $content = $revision->getContent();
            $matter_local = $revision->getMetadata();

            $title = (isset($dto['parse']['displaytitle'])) ? $dto['parse']['displaytitle'] : $revision->getTitle();
            $matter_local['uri'] = $title;

            if (isset($matter_local['broken_links']) && count($matter_local['broken_links']) >= 1) {
                $links = $matter_local['broken_links'];
                unset($matter_local['broken_links']);
                $matter_local['todo_broken_links']['note'] = 'During import MediaWiki could not find the following links,';
                $matter_local['todo_broken_links']['note'] .= ' please fix and adjust this list.';
                $matter_local['todo_broken_links']['links'] = $links;
            }

            $matter_local['readiness'] = str_replace('_', ' ', $matter_local['readiness']);

            $newRev = new MarkdownRevision($content, $matter_local);
            $newRev->setTitle(substr($title, (int) strrpos($title, '/') + 1));
            $newRev->setAuthor($revision->getAuthor());

            return $newRev;


        }

        return $revision;
    }
}
