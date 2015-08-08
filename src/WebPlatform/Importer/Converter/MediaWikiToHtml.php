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
    protected function getPageFromApi($title)
    {
        try {
            $recv = $this->makeRequest($title);
        } catch (Exception $e) {
            return $e;
        }

        $dto = json_decode($recv, true);

        if (!isset($dto['parse']) || !isset($dto['parse']['text'])) {
            throw new Exception('We did could not use data we received');
        }

        return $dto['parse'];
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

            $editorialNotes = $pageDom->get('.editorial-notes');
            if (isset($editorialNotes[0])) {
                $editorialNotesText = $editorialNotes[0]->getText();
                $editorialNotes[0]->delete();
                if (!empty($editorialNotesText) && strcmp('{{{', substr($editorialNotesText, 0, 3)) !== 0) {
                    $matter_local['editorial_notes'] = $editorialNotesText;
                }
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
