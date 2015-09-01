<?php

/**
 * WebPlatform Content Converter.
 */
namespace WebPlatform\ContentConverter\Model;

/**
 * HTML Revision.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class HtmlRevision extends AbstractRevision
{
    public function __construct(MediaWikiApiResponseArray $obj)
    {
        $content = $obj->getHtmlString();
        $title = $obj->getTitle();

        $this->setContent($content);
        $this->setTitle($title);

        $datetime = new DateTime();
        $datetime->setTimezone(new DateTimeZone('Etc/UTC'));
        $this->setTimestamp($datetime);
        $this->setComment('Conversion pass: Reformatted into HTML');

        return $this;
    }

    public function getContent()
    {
        return $this->content;
    }
}
