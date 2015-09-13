<?php

/**
 * WebPlatform Content Converter.
 */
namespace WebPlatform\Importer\Model;

use WebPlatform\ContentConverter\Model\MarkdownRevision as BaseMarkdownRevision;
use Symfony\Component\Yaml\Dumper;

/**
 * Markdown Revision, with some project specific adjustments.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class MarkdownRevision extends BaseMarkdownRevision
{
    public function getFrontMatter()
    {
        $yaml = new Dumper();
        $yaml->setIndentation(2);

        if (!empty($this->getTitle()) && !isset($this->front_matter['title'])) {
            $this->front_matter['title'] = $this->getTitle();
        }

        ksort($this->front_matter);

        $out[] = '---';
        $titleCopy = $this->front_matter['title'];
        unset($this->front_matter['title']);
        $out[] .= sprintf('title: %s', $titleCopy);

        if (!empty($this->front_matter)) {
            $out[] = $yaml->dump($this->front_matter, 3, 0, false, false);
        }
        $out[] = '---';

        return implode($out, PHP_EOL);
    }
}
