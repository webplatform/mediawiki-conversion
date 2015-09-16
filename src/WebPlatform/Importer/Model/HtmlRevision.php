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

    protected $namespacePrefix = '';

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

        $this->front_matter['broken_links'] = $recv->getBrokenLinks();

        $title = $recv->getTitle();
        $seekNamespaceSeparator = strpos($title, ':');

        // We don't want to convert to namespace if its too far.
        // If your namespace is longer than 5 characters, adjust below.
        if (is_numeric($seekNamespaceSeparator) && $seekNamespaceSeparator > 5) {
            $seekNamespaceSeparator = false;
        }
        if ($seekNamespaceSeparator !== false && is_numeric($seekNamespaceSeparator) && $seekNamespaceSeparator > 2) {
            $this->namespacePrefix = substr($title, 0, $seekNamespaceSeparator);
        }

        $this->setTitle($title);
        $this->makeTags($recv->getCategories());
        $this->setAuthor(new MediaWikiContributor(null), false);

        $this->isDeleted = $recv->isDeleted();
        $this->isEmpty = $recv->isEmpty();

        if ($lint === true) {
            $this->lint($recv->getHtml());
        } else {
            $this->setContent($recv->getHtml());
        }

        $this->mapCompatibilityTableFlag();

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
            $titleNode = $title->getDomNode();
            $titleSpan = $titleNode->firstChild;
            $titleSpan->removeAttribute('id');
            $titleSpan->removeAttribute('class');

            /**
             * Can't do replacement here. It breaks with Japanese pages.
             *
             * we'll live with <hn><span>Foo</span></hn> to be "# <span>Foo</span>" in conversion.
              /
            $titleText = $titleSpan->firstChild->nodeValue;
            $escapedTitle = $titleText; //htmlspecialchars($titleText, ENT_COMPAT|ENT_HTML401, ini_get("default_charset"), false);
            $textNode = $titleNode->ownerDocument->createTextNode($escapedTitle);
            $titleNode->replaceChild($textNode, $titleSpan);
            */
        }
        $firstFirstTitle = $pageDom->get('h1');
        if (count($firstFirstTitle) >= 1) {
            $this->metadata['first_title'] = $firstFirstTitle[0]->getText();
            $firstFirstTitle[0]->delete();
        }
        unset($titlesMatches);

        /**
         * Languages link table.
         *
         *     table.languages .mbox-text span[lang]
         *
         * ```
         * <table class="nmbox languages"><tr><th><b>Language</b></th>
         * <td class="mbox-text">
         *   <span lang="es"><a href="/wiki/css/es" title="css/es">español</a></span>&#160;&#8226;&#32;
         *   <span lang="fr"><a href="/wiki/css/fr" title="css/fr">français</a></span>
         *   ...
         * </td></tr></table>
         * ```
         */
        $langMatches = $pageDom->get('table.languages span[lang] a');
        if (count($langMatches) >= 1) {
            foreach ($langMatches as $lang) {
                $langNode = $lang->getDOMNode();
                $langCode = $langNode->parentNode->getAttribute('lang');
                $langOut = [];
                $langOut['text'] = $lang->getText();
                $langOut['href'] = str_replace('/wiki', '', $langNode->getAttribute('href'));
                $this->front_matter['translations'][$langCode] = $langOut;
            }
        }
        /**
         * Delete table.languages and leftover span.language
         */
        $langMatchesToDelete = $pageDom->get('table.languages,span.language');
        if (count($langMatchesToDelete) >= 1) {
            foreach ($langMatchesToDelete as $el) {
                $el->delete();
            }
        }
        unset($langMatchesToDelete, $langMatches);


        $dataTypeTags = $pageDom->get('[data-meta]');
        if (count($dataTypeTags) >= 1) {
            foreach ($dataTypeTags as $dataTypeTag) {
                $dataTypeTagNode = $dataTypeTag->getDOMNode();
                $dataKey = $dataTypeTagNode->getAttribute('data-meta');
                $dataText = $dataTypeTagNode->firstChild->nodeValue;

                $obj['predicate'] = $dataText;

                if ($dataKey === "summary") {
                    // We already get that elsewhere anyway
                    continue;
                }

                foreach ($dataTypeTagNode->childNodes as $childNode) {
                    if (isset($childNode->tagName) && $childNode->tagName === 'span') {
                        //var_dump($childNode->firstChild); // DEBUG
                        if ($childNode->firstChild instanceof \DOMElement && $childNode->firstChild->tagName === 'a') {
                            $obj['value'] = $childNode->firstChild->nodeValue;
                            $obj['href'] = str_replace('/wiki', '', $childNode->firstChild->getAttribute('href'));
                        } else {
                            $obj['value'] = $childNode->nodeValue;
                        }
                        $childNode->removeAttribute('data-type');
                    }
                }

                $dataTypeTagNode->removeAttribute('data-meta');
                $dataTypeTagNode->removeAttribute('data-type');
                if (!empty($dataTypeValue) && $dataTypeValue !== $dataText) {
                    $obj['value'] = $dataTypeValue;
                }

                $this->front_matter['relationships'][$dataKey] = $obj;

                $someReformattedHtml = $this->toHtml($dataTypeTagNode->childNodes);
                $someReformattedHtml = str_replace(['<span>', '</span>'], '', $someReformattedHtml);
                $this->replaceNodeContentsWithHtmlString($dataTypeTagNode, $someReformattedHtml);
            }
        }
        unset($dataTypeTags);

        /**
         * Lets remove attribution blocks and put them verbatim in the front matter
         */
        $attributionMatches = $pageDom->get('.attribution p');
        if (count($attributionMatches) >= 1) {
            // Garbage markup and redundant text that can be put elsewhere.
            // e.g. if we have attributions, we can add "Portions..." through static site generator.
            // No need to hardcode that text in the docs page themselves.
            $attribRepl['Portions of this content come from the '] = '';
            $attribRepl['This article contains content originally from external sources.'] = '';
            $attribRepl['<br/>'] = '';
            $attribRepl['</i>'] = '';
            $attribRepl['<i>'] = '';
            foreach ($attributionMatches as $attrib) {
                $verbose = str_replace(array_keys($attribRepl), $attribRepl, $attrib->getHtml());
                if (!empty($verbose)) {
                    $this->front_matter['attributions'][] = trim($verbose);
                }
            }
        }
        unset($attributionMatches);
        $attributionBlockMatch = $pageDom->get('.attribution');
        if (count($attributionBlockMatch) >= 1) {
            foreach ($attributionBlockMatch as $attribBlock) {
                $attribBlock->delete();
            }
        }
        unset($attributionBlockMatch);

        /**
         * Extract readiness-state info, pluck it up in the front matter
         */
        $nessMatches = $pageDom->get('.readiness-state');
        if (isset($nessMatches[0])) {
            $this->front_matter['readiness'] = str_replace('readiness-state ', '', $nessMatches[0]->getAttribute('class'));
            $nessMatches[0]->delete();
        }
        unset($nessMatches);

        /**
         * Extract Standardization (s13n) status info, pluck it up in the front matter.
         */
        $s13nMatches = $pageDom->get('.standardization_status');
        if (isset($s13nMatches[0])) {
            $status = (empty($s13nMatches[0]->getText()))?'Unknown':$s13nMatches[0]->getText();
            $this->front_matter['standardization_status'] = $status;
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
                        $this->front_matter['notes'][] = $revisionNotesText;
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
            $summary = htmlspecialchars($documentSummaryMatches[0]->getText(), ENT_COMPAT|ENT_HTML401, ini_get("default_charset"), false);
            if (!empty($summary)) {
                $this->front_matter['summary'] = $summary;
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
        $liCodeAnchorMatches = $pageDom->get('li > code > a');
        if (count($liCodeAnchorMatches)) {
            foreach ($liCodeAnchorMatches as $aElem) {
                $aElemNode = $aElem->getDOMNode();
                $codeBlockChildren = $aElemNode->parentNode->parentNode->childNodes;
                if ($codeBlockChildren->length === 1) {
                    $someReformattedHtml = $this->toHtml($aElemNode->parentNode->childNodes);
                    $this->replaceNodeContentsWithHtmlString($aElemNode->parentNode, $someReformattedHtml);
                }
            }
        }
        unset($liCodeAnchorMatches);

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
                        $this->front_matter['code_samples'][] = $hrefAttribute;
                    } else {
                        //$this->front_matter['external_links'][] = $hrefAttribute;
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
                $assetSrc = $assetFileNode->getAttribute('src');
                $assetFileNode->setAttribute('src', $this->wrapNamespacePrefixTo($assetSrc));
                $this->assets[] = $assetSrc;
            }
        }

        /**
         * Some wiki pages pasted more than once the links, better clean it up.
         */
        if (isset($this->front_matter['code_samples'])) {
            $this->front_matter['code_samples'] = array_unique($this->front_matter['code_samples']);
        }
        if (isset($this->front_matter['external_links'])) {
            $this->front_matter['external_links'] = array_unique($this->front_matter['external_links']);
        }

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
        $tablesMatches = $pageDom->get('table.overview_table,table.summary,table.relatedspecs');
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

            // Let's add is_empty to front matter instead,
            // so we'll catch them with static site
            // generator downstream
            $this->front_matter['is_empty'] = true;
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
            $this->front_matter['tags'] = array_unique($tags);
        }
    }

    private function mapCompatibilityTableFlag()
    {
        $extractTemplateStringsClosure = function (&$item) {
            $name = str_replace(' ', '_', $item['*']);
            $item = substr($name, strpos($name, ':') + 1);
        };

        $localDto = $this->dto->jsonSerialize();
        if (isset($localDto['parse']) && isset($localDto['parse']['templates'])) {
            $templates = $localDto['parse']['templates'];
            array_walk($templates, $extractTemplateStringsClosure);

            if (in_array('Compatibility', $templates)) {
                $title = $localDto['parse']['title'];
                $pkg['feature'] = substr($title, strrpos($title, '/') + 1);
                $pkg['topic'] = substr($title, 0, strpos($title, '/'));
                $this->front_matter['compatibility'] = $pkg;
            }
        }
    }

    protected function convertTwoColsTableIntoDefinitionList(GlHtmlNode $ghn)
    {
        $tableNode = $ghn->getDOMNode();

        if ($tableNode->tagName !== 'table') {
            throw new UnexpectedValueException('This method only accepts table nodes');
        }

        $tableKey = strtolower(str_replace(['wikitable ', 'sortable '], '', $tableNode->getAttribute('class')));

        $hasTableKey = !empty($tableKey);

        $conditionsToUse[] = isset($tableNode->childNodes[0]) && $tableNode->childNodes[0]->tagName === 'tr';
        $conditionsToUse[] = isset($tableNode->childNodes[0]) && count($tableNode->childNodes[0]->childNodes) === 1;

        //echo $tableKey.PHP_EOL; // DEBUG

        /**
         * We want to replace table **only if** its key: value type of table.
         */
        if (!in_array(false, $conditionsToUse)) {

            // I wanted to use objects, but couldn't do it better than this.
            // Whatever.
            $concatString = PHP_EOL;

            $tableData = [];
            foreach ($tableNode->childNodes as $trNodes) {
                $tdCounter = 0;
                $kv = [];
                foreach ($trNodes->childNodes as $tdNodes) {
                    if (count($tdNodes->childNodes) === 1) {
                        /*
                        if ($tdNodes->childNodes[0] instanceof \DOMText) {
                            $text = trim($tdNodes->childNodes[0]->wholeText);
                            if (in_array($text, ['Specification', ])) {

                            }
                            var_dump($tdNodes->childNodes[0]);
                        }
                        */
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
                if ($kv[0] === 'Specification') {
                    // Let's not add this obvious title underneath
                    // something that already has that title in place.
                    continue;
                }
                //  Could not find a better way to have deflist work with Remarkable (the close-open tags below)
                $concatString .= sprintf('<dl>'.PHP_EOL.'  <dt>%s</dt>'.PHP_EOL.'  <dd>%s</dd>'.PHP_EOL.'</dl>'.PHP_EOL, $kv[0], $kv[1]);
            }

            if ($hasTableKey && in_array($tableKey, ['overview_table'])) {
                if (isset($this->front_matter['tables'][$tableKey])) {
                    $this->front_matter['tables'][$tableKey] = array_merge($this->front_matter['tables'][$tableKey], $tableData);
                } else {
                    $this->front_matter['tables'][$tableKey] = $tableData;
                }
            }

            // Yup. Take this big string, DOMify it.
            $concatString .= PHP_EOL.PHP_EOL;
            $this->replaceNodeContentsWithHtmlString($tableNode, $concatString);
            unset($overviewTableMatches);
        }
    }

    private function wrapNamespacePrefixTo($assetUrl)
    {
        $prefix = '';
        if (!empty($this->namespacePrefix)) {
            $prefix = '/'.$this->namespacePrefix;
        }

        return $prefix.$assetUrl;
    }

    private function replaceNodeContentsWithHtmlString(\DOMNode $node, $htmlString)
    {
        $newFragment = $node->ownerDocument->createDocumentFragment();
        $newFragment->appendXML($htmlString);
        $node->parentNode->replaceChild($newFragment, $node);
    }

    private function toHtml(\DOMNodeList $list)
    {
        $string = '';
        foreach ($list as $child) {
            if (isset($child->childNodes) && count($child->childNodes) >= 1) {
                foreach ($child->childNodes as $childChild) {
                    $string .= $childChild->ownerDocument->saveXML($childChild);
                }
                $string = trim($string);
            }
            $string .= $child->ownerDocument->saveXML($child);
        }

        return $string;
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

    public function getTextContent()
    {
        $pageDom = new GlHtml($this->content);

        return $pageDom->get("body")[0]->getHtml();
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
