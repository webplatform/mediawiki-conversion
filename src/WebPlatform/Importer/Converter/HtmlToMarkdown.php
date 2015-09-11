<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */
namespace WebPlatform\Importer\Converter;

use WebPlatform\ContentConverter\Converter\ConverterInterface;
use WebPlatform\ContentConverter\Model\AbstractRevision;
use WebPlatform\Importer\Model\MarkdownRevision;
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
        "to"    => "markdown_github+blank_before_header+blank_before_blockquote+definition_lists",
        "atx-headers" => null,
        "parse-raw" => null,
        "no-highlight" => null,
        "ascii" => null
    );

    public function __construct()
    {
        $this->converter = new Pandoc();

        /**
         * Found language code in WebPlatform code samples
         *
         *  - brush:
         *  - css
         *  - de1
         *  - glsl
         *  - html
         *  - html4strict
         *  - http
         *  - js
         *  - lang-css
         *  - lang-markup
         *  - other
         *  - php
         *  - prettyprint
         *  - python
         *  - script
         *  - xml
         *  - yaml
         *  - style=&quot;background-color:
         */
        $validLanguageCodes['html'] = ['markup', 'xhtml', 'html5', 'html4strict', 'lang-markup'];
        $validLanguageCodes['css'] = ['lang-css'];
        $validLanguageCodes['svg'] = [];
        $validLanguageCodes['xml'] = [];
        $validLanguageCodes['yaml'] = [];
        $validLanguageCodes['js'] = ['script', 'javascript'];

        $this->languageCodeCallback = function ($matches) use ($validLanguageCodes) {
            if (!is_array($matches) || !isset($matches[1])) {
                return '```';
            }

            if (in_array($matches[1], array_keys($validLanguageCodes))) {
                return sprintf('``` %s', $matches[1]);
            }

            // Some entries such as '``` {.script style="font-size: 16px;"}' has been found in $matches[0] :(
            // ... in this case, we'll change $matches[1] to have ' style="..."' removed.
            $matches[1] = substr($matches[1], 0, strpos($matches[1], ' '));
            // ... Yup. Another input has "brush: .js" at $matches[1]. Let's trim that out too.
            $matches[1] = str_replace('brush: .', '', $matches[1]);

            foreach ($validLanguageCodes as $kp => $possibilities) {
                if (in_array($matches[1], $possibilities)) {
                    return sprintf('``` %s', $kp);
                }
            }

            return '```';
        };

        return $this;
    }

    public function markdownify($html)
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
            $matter_local = $revision->getFrontMatterData();

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
                $content = $this->markdownify($html);
                $content = preg_replace_callback("/```\s?\{\.(.*)\}/muS", $this->languageCodeCallback, $content);
            } else {
                $content = $html;
            }

            if (isset($matter_local['tables']) && is_array($matter_local['tables'])) {
                $newTables = [];
                foreach ($matter_local['tables'] as $tableKey => $tableData) {
                    $newTableData = [];
                    foreach ($tableData as $subTableKey => $subtableValue) {
                        $rowKeyCopy = $this->markdownify($subTableKey);
                        $rowDataCopy = $this->markdownify($subtableValue);
                        $newTableData[$rowKeyCopy] = $rowDataCopy;
                    }
                    $newTables[$tableKey] = $newTableData;
                }
                unset($matter_local['tables']);
                $matter_local = array_merge($matter_local, $newTables);
            }

            if (isset($matter_local['attributions'])) {
                $newAttributions = [];
                foreach ($matter_local['attributions'] as $attributionRow) {
                    $rowData = $this->markdownify($attributionRow);
                    if (!empty($rowData)) {
                        $newAttributions[] = $rowData;
                    }
                }
                if (count($newAttributions) >= 1) {
                    $matter_local['attributions'] = $newAttributions;
                } else {
                    unset($matter_local['attributions']);
                }
            }

            $newRev = new MarkdownRevision($content, $matter_local);
            $newRev->setAuthor($revision->getAuthor());

            return $newRev;
        }

        return $revision;
    }
}
