<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */
namespace WebPlatform\ContentConverter\Helpers;

use Exception;

/**
 * MediaWiki subtelty helper.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class MediaWikiHelper
{
    protected $apiUrl;

    public function setApiUrl($apiUrl)
    {
        $this->apiUrl = $apiUrl;

        return $this;
    }

    /**
     * Make a (forgiving) HTTP request to an origin
     *
     * I’m aware that its a bad idea to set `CURLOPT_SSL_VERIFYPEER` to
     * false. But the use-case here is about supporting requests to origins
     * potentially behind a Varnish server with a self-signed certificate and
     * to allow run import on them, we got to prevent check.
     *
     * We have to remember that the point of this library is to export content
     * into static files, not to make financial transactions and bypass things that
     * should be taken care of.
     **/
    public function makeRequest($title, $cookieString = null)
    {

        $url = $this->apiUrl.urlencode($title);

        $ch = curl_init();
        // http://php.net/manual/en/function.curl-setopt.php
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_REFERER, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TCP_NODELAY, true);

        if (!empty($cookieString)) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookieString);
        }

        try {
            $content = curl_exec($ch);
            curl_close($ch);
        } catch (Exception $e) {
            throw new Exception('Could not retrieve data from remote service', null, $e);
        }

        return mb_convert_encoding($content, 'UTF-8',
               mb_detect_encoding($content, 'UTF-8, ISO-8859-1', true));
    }

    protected function retrieve($title)
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

        return $dto;
    }
}
