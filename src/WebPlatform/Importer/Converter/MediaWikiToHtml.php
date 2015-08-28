<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */
namespace WebPlatform\Importer\Converter;

use WebPlatform\ContentConverter\Converter\MediaWikiToHtml as BaseConverter;
use WebPlatform\ContentConverter\Converter\ConverterInterface;
use WebPlatform\ContentConverter\Model\AbstractRevision;
use WebPlatform\ContentConverter\Model\MediaWikiRevision;
use WebPlatform\ContentConverter\Model\MarkdownRevision;
use GlHtml\GlHtml;
use Exception;

/**
 * Wikitext to HTML converter using MediaWiki API.
 *
 * This class creates an HTTP request to a MediaWiki API endpoint
 * and uses its own Parser to give us HTML.
 *
 * Every wiki has its own subtelties, the purpose of this class
 * is to extend the original so we can handle specifics for WebPlatform
 * Docs MediaWiki and how we want to export its contents.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class MediaWikiToHtml extends BaseConverter implements ConverterInterface
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
        if ($revision instanceof MediaWikiRevision) {
            try {
                $mwparse = $this->getPageFromApi($revision->getTitle());
            } catch (Exception $e) {
                $title = $revision->getTitle();
                $url = $this->apiUrl.urlencode($title);
                $message = sprintf('Could not get data from API for %s with the following URI %s', $title, $url);
                throw new Exception($message, 0, $e);
            }

            if (!isset($mwparse['text']) || !isset($mwparse['text']['*'])) {
                throw new Exception('MediaWiki API did not return HTML string from parser');
            }

            $content = $mwparse['text']['*'];
            $matter_local = [];

            $matter_local['displaytitle'] = $mwparse['displaytitle'];

            if (isset($mwparse['categories']) && is_array($mwparse['categories'])) {
                foreach ($mwparse['categories'] as $catObj) {
                    $matter_local['categories'][] = $catObj['*'];
                }
            }

            if (isset($mwparse['links']) && is_array($mwparse['links'])) {
                foreach ($mwparse['links'] as $linkObj) {
                    if (!isset($linkObj['exists'])) {
                        $broken_links[] = $linkObj['*'];
                    }
                }
                if (isset($broken_links) && count($broken_links) >= 1) {
                    $matter_local['todo_broken_links']['note'] = 'During import MediaWiki could not find the following links,';
                    $matter_local['todo_broken_links']['note'] .= ' please fix and adjust this list.';
                    $matter_local['todo_broken_links']['links'] = $broken_links;
                }
            }

            $pageDom = new GlHtml($content);

            $readinessMatches = $pageDom->get('.readiness-state');
            if (isset($readinessMatches[0])) {
                $matter_local['readiness'] = str_replace('readiness-state ', '', $readinessMatches[0]->getAttribute('class'));
                $readinessMatches[0]->delete();
            }

            $standardizationStatus = $pageDom->get('.standardization_status');
            if (isset($standardizationStatus[0])) {
                $matter_local['standardisation_status'] = $standardizationStatus[0]->getText();
                $standardizationStatus[0]->delete();
            }

            $contentRevisionNote = $pageDom->get('.is-revision-notes');
            if (count($contentRevisionNote) >= 1) {
                if (isset($contentRevisionNote[0])) {
                    foreach ($contentRevisionNote as $note) {
                        $contentRevisionNoteText = $note->getText();
                        $note->delete();
                        if (!empty($contentRevisionNoteText) && strcmp('{{{', substr($contentRevisionNoteText, 0, 3)) !== 0) {
                            $matter_local['notes'][] = $contentRevisionNoteText;
                        }
                    }
                }
            }

            $dataMetasOut = [];
            // Use data-type instead, and if data-meta exists, we know the key,
            // the other one must be the value.
            $tags = $pageDom->get('[data-meta]');
            if (count($tags) >= 1) {
                foreach ($tags as $tag) {
                    //$dataMetasKey = $tag->getDOMNode()->parentNode->getAttribute('data-meta');
                    //$dataNodeObj = $tag->getDOMNode()->firstChild;
                    //$dataMetasBody = '';

                    $metaName = $tag->getDOMNode()->parentNode->getAttribute('data-meta');
                    $obj = ['content'=> $tag->getHtml(), 'name'=> $metaName];
                    var_dump($obj);
                    
                    /*
                    if (isset($dataNodeObj->tagName) && $dataNodeObj->tagName !== 'span') {
                        echo 'Is NOT a Span. Dig deeper.'.PHP_EOL;
                        //$dataMetasBody = $dataNodeObj->nextSibling->textContent;
                        var_dump($dataNodeObj->nextSibling->textContent);
                    } else {
                        echo 'Is a Span'.PHP_EOL;
                        var_dump($dataNodeObj->textContent);
                    }

                    if (isset($dataNodeObj->wholeText)) {
                        echo 'Has wholeText';
                        var_dump($dataNodeObj->wholeText);
                    }
                    */

                    //if (is_string($dataNodeObj->nextSibling) && $dataNodeObj->childNodes === null) {
                    //    echo 'case 1'.PHP_EOL;
                        /**
                         * When we have text directly in the node
                         *
                         *
                         * Returns
                         *
                         * <span data-meta="return" data-type="key">Returns an object of type <span data-type="value">Object</span></span>
                         *
                         * e.g.:
                         *
                         * object(DOMText)#176272 (19) {
                         *     ["wholeText"]=> string(26) "Returns an object of type ",
                         *     ["data"]=> string(26) "Returns an object of type ",
                         *     ["length"]=> int(26),
                         *     ["nodeName"]=> string(5) "#text",
                         *     ["nodeValue"]=> string(26) "Returns an object of type ",
                         *     ["nodeType"]=> int(3),
                         *     ["parentNode"]=> string(22) "(object value omitted)",
                         *     ["childNodes"]=> NULL,
                         *     ["firstChild"]=> NULL,
                         *     ["lastChild"]=> NULL,
                         *     ["previousSibling"]=> NULL,
                         *     ["nextSibling"]=> string(22) "(object value omitted)",
                         *     ["attributes"]=> NULL,
                         *     ["ownerDocument"]=> string(22) "(object value omitted)",
                         *     ["namespaceURI"]=> NULL,
                         *     ["prefix"]=> string(0) "",
                         *     ["localName"]=> NULL,
                         *     ["baseURI"]=> NULL,
                         *     ["textContent"]=> string(26) "Returns an object of type "
                         * }
                         */
                    //    $dataMetasBody = $dataNodeObj->nextSibling->textContent;
                    //} elseif ($dataNodeObj->childNodes !== null && count($dataNodeObj->childNodes) > 1) {
                    //    echo 'case 2'.PHP_EOL;

                        /**
                         * When we have nested italic.
                         *
                         * We want internal value "apis/web-storage/Storage";
                         *
                         * e.g.
                         *
                         *     {{API_Object_Property
                         *     |Property_applies_to=apis/web-storage/Storage
                         *     }}
                         *
                         * If we dig at API_Object_Property has, we have...
                         *
                         *     {{#if:{{{Property_applies_to|}}}|<span data-meta="applies_to" data-type="key">''Property of <span data-type="value">[[{{{Property_applies_to|}}}]]''</span></span>|}}
                         *
                         * Notice the ''property...'' between doubled single quotes.
                         *
                         * Generates the following HTML
                         *
                         *     <span data-meta="applies_to" data-type="key">
                         *       <i>Property of
                         *         <span data-type="value">
                         *           <a href="/wiki/apis/web-storage/Storage" title="apis/web-storage/Storage">apis/web-storage/Storage</a>
                         *         </span>
                         *       </i>
                         *     </span>
                         *
                         * object(DOMElement)#176272 (17) {
                         *   ["tagName"]=> string(1) "i",
                         *   ["schemaTypeInfo"]=> NULL,
                         *   ["nodeName"]=> string(1) "i",
                         *   ["nodeValue"]=> string(36) "Property of apis/web-storage/Storage",
                         *   ["nodeType"]=> int(1),
                         *   ["parentNode"]=> string(22) "(object value omitted)",
                         *   ["childNodes"]=> string(22) "(object value omitted)",
                         *   ["firstChild"]=> string(22) "(object value omitted)",
                         *   ["lastChild"]=> string(22) "(object value omitted)",
                         *   ["previousSibling"]=> NULL,
                         *   ["attributes"]=> string(22) "(object value omitted)",
                         *   ["ownerDocument"]=> string(22) "(object value omitted)",
                         *   ["namespaceURI"]=> NULL,
                         *   ["prefix"]=> string(0) "",
                         *   ["localName"]=> string(1) "i",
                         *   ["baseURI"]=> NULL,
                         *   ["textContent"]=> string(36) "Property of apis/web-storage/Storage"
                         * }
                         */
                    //    $dataMetasBody = $dataNodeObj->childNodes[1]->textContent;
                    //} else {
                    //    echo 'case else'.PHP_EOL;
                    //}

                    //var_dump($dataNodeObj);

                    //if (!empty($dataMetasBody)) {
                    //    $dataMetasOut[$dataMetasKey] = $dataMetasBody;
                    //}
                }
                //$matter_local['foo'] = $dataMetasOut;
            }

            $titles = $pageDom->get('h1,h2,h3,h4');
            foreach ($titles as $title) {
                $title->replaceInner($title->getText());
            }

            // Replacing HTML with purified version
            //$configObject = [ 'safe' => 1, 'deny_attribute' => '*', 'keep_bad' => 2, 'make_tag_strict' => 1, 'balance' => 2];
            //$configObject['elements'] => 'a,h1,h2,h3,h4,pre,code'
            $content = $pageDom->get('body')[0]->getHtml();

            $matter_rev = $revision->getFrontMatterData();

            $newRev = new MarkdownRevision($content, array_merge($matter_rev, $matter_local));

            return $newRev->setTitle($revision->getTitle());
        }

        return $revision;
    }
}
