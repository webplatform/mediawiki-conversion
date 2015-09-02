<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */
namespace WebPlatform\Importer\Converter;

use WebPlatform\ContentConverter\Converter\ConverterInterface;
use WebPlatform\ContentConverter\Model\AbstractRevision;
use WebPlatform\ContentConverter\Model\MarkdownRevision;
use WebPlatform\Importer\Model\HtmlRevision;
use Pandoc\Pandoc;

/**
 * HTML to Markdown converter using Markdownify PHP library.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class HtmlToMarkdown implements ConverterInterface
{

    protected $converter;

    protected $options = array(
        "from"  => "html",
        "to"    => "markdown_github+blank_before_header+blank_before_blockquote",
        "atx-headers" => null,
        "parse-raw" => null,
        "no-highlight" => null,
        "ascii" => null
    );

    public function __construct()
    {
        $this->converter = new Pandoc();

        return $this;
    }

    protected function convert($html)
    {
        return $this->converter->runWith($html, $this->options);
    }

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
            $title = (isset($dto['parse']['displaytitle'])) ? $dto['parse']['displaytitle'] : $revision->getTitle();

            $html = $revision->getContent();
            $matter_local = $revision->getMetadata();

            $matter_local['uri'] = $title;

            if (isset($matter_local['broken_links']) && count($matter_local['broken_links']) >= 1) {
                $links = $matter_local['broken_links'];
                $matter_local['todo_broken_links']['note'] = 'During import MediaWiki could not find the following links,';
                $matter_local['todo_broken_links']['note'] .= ' please fix and adjust this list.';
                $matter_local['todo_broken_links']['links'] = $links;
            }
            unset($matter_local['broken_links']);

            if (isset($matter_local['readiness'])) {
                $matter_local['readiness'] = str_replace('_', ' ', $matter_local['readiness']);
            }

            if ($revision->isMarkdownConvertible() === true) {
                $content = $this->convert($html);
            } else {
                $content = $html;
            }

            $newRev = new MarkdownRevision($content, $matter_local);
            $newRev->setTitle(substr($title, (int) strrpos($title, '/') + 1));
            $newRev->setAuthor($revision->getAuthor());

            return $newRev;
        }

        return $revision;
    }
}
