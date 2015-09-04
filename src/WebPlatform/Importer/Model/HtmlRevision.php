<?php

/**
 * WebPlatform Content Converter.
 */
namespace WebPlatform\Importer\Model;

use WebPlatform\ContentConverter\Model\AbstractRevision;
use WebPlatform\ContentConverter\Model\MediaWikiApiParseActionResponse;
use WebPlatform\ContentConverter\Model\MediaWikiContributor;
use GlHtml\GlHtmlNode;
use GlHtml\GlHtml;
use UnexpectedValueException;

/**
 * HTML Revision, with some cleanup.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class HtmlRevision extends AbstractRevision
{
    /** @var MediaWikiApiParseActionResponse copy of the instance given at constructor time */
    protected $dto;

    protected $metadata = [];

    protected $markdownConvertible = false;

    /** @var array List of files used in reference */
    protected $assets = [];

    /** @var boolean Toggle to true if MediaWiki Parse API returns a "missingtitle" error */
    protected $isDeleted = false;

    /** @var boolean Toggle to true if the received text contents is empty */
    protected $isEmpty = false;

    /**
     * Let’s use MediaWiki API Response JSON as constructor
     *
     * @param MediaWikiApiParseActionResponse $recv what we received from MediaWiki API at Parse action
     */
    public function __construct(MediaWikiApiParseActionResponse $recv, $lint = false)
    {
        parent::constructorDefaults();

        $this->dto = $recv;

        $this->metadata['broken_links'] = $recv->getBrokenLinks();

        $this->setTitle($recv->getTitle());
        $this->makeTags($recv->getCategories());
        $this->setAuthor(new MediaWikiContributor(null), false);

        $this->isDeleted = $recv->isDeleted();
        $this->isEmpty = $recv->isEmpty();

        if ($lint === true) {
            $this->lint($recv->getHtml());
        } else {
            $this->setContent($recv->getHtml());
        }

        return $this;
    }

    public function enableMarkdownConversion()
    {
        $this->markdownConvertible = true;
    }

    public function isMarkdownConvertible()
    {
        return $this->markdownConvertible;
    }

    private function lint($html)
    {
        $pageDom = new GlHtml($html);

        /**
         * Remove inuseful markup in MediaWiki generated HTML for titles
         */
        $titlesMatches = $pageDom->get('h1,h2,h3,h4,h5,h6'); // do we really have an h6 tag?
        foreach ($titlesMatches as $title) {
            $titleText = $title->getText();
            $title->setValue(htmlentities($titleText));
        }
        unset($titlesMatches);

        /**
         * Extract readiness-state info, pluck it up in the front matter
         */
        $nessMatches = $pageDom->get('.readiness-state');
        if (isset($nessMatches[0])) {
            $this->metadata['readiness'] = str_replace('readiness-state ', '', $nessMatches[0]->getAttribute('class'));
            $nessMatches[0]->delete();
        }
        unset($nessMatches);

        /**
         * Extract Standardization status info, pluck it up in the front matter.
         */
        $s13nMatches = $pageDom->get('.standardization_status');
        if (isset($s13nMatches[0])) {
            $status = (empty($s13nMatches[0]->getText()))?'Unknown':$s13nMatches[0]->getText();
            $this->metadata['standardization_status'] = $status;
            $s13nMatches[0]->delete();
        }
        unset($s13nMatches);

        /**
         * Extract revision notes, so we don't see edition work notes among page content.
         */
        $revisionNotesMatches = $pageDom->get('.is-revision-notes');
        if (count($revisionNotesMatches) >= 1) {
            if (isset($revisionNotesMatches[0])) {
                foreach ($revisionNotesMatches as $note) {
                    $revisionNotesText = $note->getText();
                    if (!empty($revisionNotesText) && strcmp('{{{', substr($revisionNotesText, 0, 3)) !== 0) {
                        $this->metadata['notes'][] = $revisionNotesText;
                    }
                    $note->delete();
                }
            }
        }
        unset($revisionNotesMatches);

        /**
         * Extract summary, add it up in the front matter.
         *
         * This will be useful so we'll use it as meta description in generated static html.
         * It also won't matter if, in the future, the front matter summary isn't the same as the
         * one in the body text.
         */
        $documentSummaryMatches = $pageDom->get('[data-meta=summary] [data-type=value]');
        if (count($documentSummaryMatches) >= 1) {
            $summaryNode = $documentSummaryMatches[0]->getDOMNode();
            $summary = $documentSummaryMatches[0]->getText();
            if (!empty($summary)) {
                $this->metadata['summary'] = $summary;
                $textNode = $summaryNode->ownerDocument->createTextNode($summary);
                $summaryNode->parentNode->parentNode->appendChild($textNode);
            }
            $summaryNode->parentNode->parentNode->removeChild($summaryNode->parentNode);
        }
        unset($documentSummaryMatches);

        /**
         * Remove "code" in `li > code > a` (later?)
         *
         * see: css/properties/border-radius
         */
        //$liCodeAnchorMatches = $pageDom->get('li > code > a');

        /**
         * Remove tagsoup in <a/> tags
         */
        $linksMatches = $pageDom->get('a');
        if (count($linksMatches) >= 1) {
            foreach ($linksMatches as $link) {
                $linkNode = $link->getDOMNode();

                $hrefAttribute = preg_replace(['~^\/wiki~'], [''], $linkNode->getAttribute('href'));
                $linkNode->setAttribute('href', $hrefAttribute);

                /**
                 * Remove duplicate information that the href will already have
                 *
                 * <a href="/wiki/foo/bar" title="foo/bar">foo/bar</a>
                 *
                 * into
                 *
                 * <a href="/foo/bar">foo/bar</a>
                 **/
                $classNames = explode(' ', $linkNode->getAttribute('class'));
                if ($linkNode->hasAttribute('title')) {
                    $linkNode->removeAttribute('title');
                }

                /**
                 * Keep record of every external links. Pluck it up in the front matter.
                 *
                 * Thanks to MediaWiki, we know which ones goes outside of the wiki.
                 */
                if (in_array('external', $classNames)) {

                    /**
                     * If an external link has "View live example" in text, let's
                     * move the link after the code sample.
                     */
                    if ($linkNode->textContent === "View live example") {
                        $hrefAttribute = str_replace('code.webplatform.org/gist', 'gist.github.com', $hrefAttribute);
                        $this->metadata['code_samples'][] = $hrefAttribute;
                    } else {
                        //$this->metadata['external_links'][] = $hrefAttribute;
                    }
                }

                /**
                 * Remove surrounding a tag to images.
                 *
                 * <a href="/wiki/File:..."><img src="..." /></a>
                 *
                 * into
                 *
                 * <img src="..." />
                 */
                if ($linkNode->hasAttribute('class')) {
                    if ($linkNode->getAttribute('class') === 'image') {
                        // keep the line below commented; they links to "/File:foo.png".
                        // Which is pointless in a migration to a static site generator.
                        //$this->assets[] = $linkNode->getAttribute('href');
                        $linkNode->parentNode->insertBefore($linkNode->childNodes[0]);
                        $linkNode->parentNode->removeChild($linkNode);
                    }
                }
            }
        }
        unset($linksMatches);

        /**
         * Figure out which images are in use
         */
        $assetUseMatches = $pageDom->get('img');
        if (count($assetUseMatches) >= 1) {
            foreach ($assetUseMatches as $asset) {
                $assetFileNode = $asset->getDOMNode();
                $this->assets[] = $assetFileNode->getAttribute('src');
            }
        }

        /**
         * Some wiki pages pasted more than once the links, better clean it up.
         */
        if (isset($this->metadata['code_samples'])) {
            $this->metadata['code_samples'] = array_unique($this->metadata['code_samples']);
        }
        if (isset($this->metadata['external_links'])) {
            $this->metadata['external_links'] = array_unique($this->metadata['external_links']);
        }

        $codeSampleHeadingMatches = $pageDom->get('.example span.language');
        if (count($codeSampleHeadingMatches) >= 1) {
            foreach ($codeSampleHeadingMatches as $codeSampleHeading) {
                $codeSampleHeading->delete();
            }
        }
        unset($codeSampleHeadingMatches);

        $codeSampleMatches = $pageDom->get('pre[class^=language]');
        if (count($codeSampleMatches) >= 1) {
            foreach ($codeSampleMatches as $exampleNode) {
                $codeSample = $exampleNode->getDOMNode();

                $className = $codeSample->getAttribute('class');
                $languageName = str_replace('language-', '', $className);
                $languageName = str_replace('javascript', 'js', $languageName);
                $codeSample->setAttribute('class', $languageName);
                $codeSample->removeAttribute('data-lang');
            }
        }
        unset($codeSampleMatches);

        /**
         * Rework two colums tables into definition list.
         */
        $tablesMatches = $pageDom->get('table');
        if (count($tablesMatches) >= 1) {
            foreach ($tablesMatches as $table) {
                $this->convertTwoColsTableIntoDefinitionList($table);
            }
            unset($tablesMatches);
        }


        /**
         * Last pass, remove inuseful comments.
         *
         * If it becomes an empty string, flag as empty or deleted document.
         */
        $glClassDoNotExposeDomDammit = $pageDom->get('body');
        // Handle empty documents with only a comment
        if (isset($glClassDoNotExposeDomDammit[0])) {

            /**
             * Comments we want to get rid of starts with
             *
             * string(15) "NewPP limit rep"
             * string(15) "Transclusion ex"
             * string(15) "Saved in parser"
             */
            $excludeComments[] = "NewPP limit rep";
            $excludeComments[] = "Transclusion ex";
            $excludeComments[] = "Saved in parser";
            $xpath = new \DOMXPath($glClassDoNotExposeDomDammit[0]->getDOMNode()->ownerDocument);
            $commentsMatches = $xpath->query('//comment()');
            foreach ($commentsMatches as $p) {
                $commentText = substr(trim($p->nodeValue), 0, 15);
                if (in_array($commentText, $excludeComments) && $p->nodeType === XML_COMMENT_NODE) {
                    $p->parentNode->removeChild($p);
                }
            }
            unset($glClassDoNotExposeDomDammit);

            $this->setContent($pageDom->get('body')[0]->getHtml());
        } else {

            // Yup. Its empty. We could do something at run, but we won't for now.
            $this->isEmpty = true;
            $this->setContent(null);

            // Let's add metadata instead, so we'll catch them with
            // static site generator downstream
            $this->metadata['is_empty'] = true;
        }

    }


    /**
     * We will use categories as tags into the static site.
     * MediaWiki Categories such as API_Method or CSS_Method
     * will be separated by either space or underscore.
     * With that, we'll reuse MediaWiki data as a way to categorize
     * content.
     **/
    protected function makeTags($mediaWikiCategories)
    {
        if (count($mediaWikiCategories) >= 1) {
            $tags = [];
            foreach ($mediaWikiCategories as $tag) {
                $t = explode('_', $tag);
                if (is_array($t)) {
                    $tags = array_merge($tags, $t);
                } else {
                    $tags[] = $t;
                }
            }
            $this->metadata['tags'] = array_unique($tags);
        }
    }

    protected function convertTwoColsTableIntoDefinitionList(GlHtmlNode $ghn, $toFrontMatter = false)
    {
        $tableNode = $ghn->getDOMNode();

        if ($tableNode->tagName !== 'table') {
            throw new UnexpectedValueException('This method only accepts table nodes');
        }

        $tableKey = strtolower(str_replace('wikitable ', '', $tableNode->getAttribute('class')));

        $hasTableKey = !empty($tableKey);

        $conditionsToUse[] = isset($tableNode->childNodes[0]) && $tableNode->childNodes[0]->tagName === 'tr';
        $conditionsToUse[] = isset($tableNode->childNodes[0]) && count($tableNode->childNodes[0]->childNodes) === 1;

        /**
         * We want to replace table **only if** its key: value type of table.
         */
        if (!in_array(false, $conditionsToUse)) {

            // I wanted to use objects, but couldn't do it better than this.
            // Whatever.
            if ($hasTableKey === true) {
                $concatString = sprintf(PHP_EOL.'<dl data-table="%s">'.PHP_EOL, $tableKey);
            } else {
                $concatString = PHP_EOL.'<dl>'.PHP_EOL;
            }

            $tableData = [];
            foreach ($tableNode->childNodes as $trNodes) {
                $tdCounter = 0;
                $kv = [];
                foreach ($trNodes->childNodes as $tdNodes) {
                    if (count($tdNodes->childNodes) === 1) {
                        $innerTdHTML = '';
                        foreach ($tdNodes->childNodes as $itd) {
                            $innerTdHTML .= $itd->ownerDocument->saveXML($itd);
                        }
                        $innerTdHTML = trim($innerTdHTML);
                        ++$tdCounter;
                        $kv[] = $innerTdHTML;
                    }
                }

                if (!isset($kv[1])) {
                    // We've gone thus far for no reason.
                    // Get away!
                    return;
                }

                $tableData[$kv[0]] = $kv[1];
                $concatString .= sprintf('  <dt>%s</dt>'.PHP_EOL.'  <dd>%s</dd>'.PHP_EOL.PHP_EOL, $kv[0], $kv[1]);
            }

            if ($toFrontMatter && $hasTableKey) {
                $this->metadata['tables'][$tableKey] = $tableData;
            }

            // Yup. Take this big string, DOMify it.
            $concatString .= '</dl>'.PHP_EOL.PHP_EOL;
            $newTable = $tableNode->ownerDocument->createDocumentFragment();
            $newTable->appendXML($concatString);

            $tableNode->parentNode->replaceChild($newTable, $tableNode);

            unset($overviewTableMatches);
        }
    }

    public function getAssets()
    {
        return array_unique($this->assets);
    }

    public function isEmpty()
    {
        return $this->isEmpty;
    }

    public function isDeleted()
    {
        return $this->isDeleted;
    }

    public function getMetadata()
    {
        return $this->metadata;
    }

    public function getContent()
    {
        return $this->content;
    }

    public function getApiResponseObject()
    {
        return $this->dto;
    }
}
