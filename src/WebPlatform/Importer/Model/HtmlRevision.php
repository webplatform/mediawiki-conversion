<?php

/**
 * WebPlatform Content Converter.
 */
namespace WebPlatform\Importer\Model;

use WebPlatform\ContentConverter\Model\AbstractRevision;
use WebPlatform\ContentConverter\Model\MediaWikiApiParseActionResponse;
use WebPlatform\ContentConverter\Model\MediaWikiContributor;
use GlHtml\GlHtml;

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
        $titlesMatches = $pageDom->get('h1,h2,h3,h4');
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
         * Extract summary, pluck it up in the front matter.
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
            }
            $summaryNode->parentNode->parentNode->appendChild($textNode);
            $summaryNode->parentNode->parentNode->removeChild($summaryNode->parentNode);
        }
        unset($documentSummaryMatches);

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
                    $this->metadata['external_links'][] = $hrefAttribute;
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
                        $linkNode->parentNode->insertBefore($linkNode->childNodes[0]);
                        $linkNode->parentNode->removeChild($linkNode);
                    }
                }
            }
        }
        unset($linksMatches);

        $codeSampleMatches = $pageDom->get('pre[class^=language]');
        if (count($codeSampleMatches) >= 1) {
            foreach ($codeSampleMatches as $exampleNode) {
                $codeSample = $exampleNode->getDOMNode();

                $className = $codeSample->getAttribute('class');
                $languageName = str_replace('language-', '', $className);
                $codeSample->setAttribute('class', $languageName);
                $codeSample->removeAttribute('data-lang');

                //$codeContents = htmlentities(str_replace(array_keys($codeSampleTouchups), $codeSampleTouchups, $exampleNode->getHtml()));
                //var_dump($codeContents);
                //var_dump($codeSample->nodeValue);
                //$codeContents = $codeSample->textContent;
                //$code = htmlentities($codeSample->textContent);
                //$codeSample->textContent = $code;
                //$codeSampleParent = $codeSample->parentNode;
                //$isInsideExampleParent = ($codeSampleParent->getAttribute('class') === "example")?true:false;
                //$codeSample->setAttribute('foo', (($isInsideExampleParent === true)?'yes':'no'));
                //if ($isInsideExampleParent) {
                //    $codeSampleParentParent = $codeSample->parentNode->parentNode;
                //    $codeSampleParentParent->replaceChild($codeSample, $codeSample->parentNode);
                //}
            }
        }
        unset($codeSampleMatches);

        $this->setContent($pageDom->get('body')[0]->getHtml());
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
