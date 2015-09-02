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

    /**
     * Let’s use MediaWiki API Response JSON as constructor
     *
     * @param MediaWikiApiParseActionResponse $recv what we received from MediaWiki API at Parse action
     */
    public function __construct(MediaWikiApiParseActionResponse $recv)
    {
        parent::constructorDefaults();

        $this->dto = $recv;

        $this->metadata['categories'] = $recv->getCategories();
        $this->metadata['broken_links'] = $recv->getBrokenLinks();

        $this->lint($recv->getHtml());
        $this->setTitle($recv->getTitle());
        $this->setAuthor(new MediaWikiContributor(null), false);

        return $this;
    }

    private function lint($html)
    {
        $pageDom = new GlHtml($html);

        $readinessMatches = $pageDom->get('.readiness-state');
        if (isset($readinessMatches[0])) {
            $this->metadata['readiness'] = str_replace('readiness-state ', '', $readinessMatches[0]->getAttribute('class'));
            $readinessMatches[0]->delete();
        }

        $standardizationStatus = $pageDom->get('.standardization_status');
        if (isset($standardizationStatus[0])) {
            $this->metadata['standardisation_status'] = $standardizationStatus[0]->getText();
            $standardizationStatus[0]->delete();
        }

        $contentRevisionNote = $pageDom->get('.is-revision-notes');
        if (count($contentRevisionNote) >= 1) {
            if (isset($contentRevisionNote[0])) {
                foreach ($contentRevisionNote as $note) {
                    $contentRevisionNoteText = $note->getText();
                    $note->delete();
                    if (!empty($contentRevisionNoteText) && strcmp('{{{', substr($contentRevisionNoteText, 0, 3)) !== 0) {
                        $this->metadata['notes'][] = $contentRevisionNoteText;
                    }
                }
            }
        }

        $titles = $pageDom->get('h1,h2,h3,h4');
        foreach ($titles as $title) {
            $title->replaceInner($title->getText());
        }

        $this->setContent($pageDom->get('body')[0]->getHtml());
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
