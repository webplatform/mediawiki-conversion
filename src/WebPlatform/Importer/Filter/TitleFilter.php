<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */

namespace WebPlatform\Importer\Filter;

use WebPlatform\ContentConverter\Filter\AbstractFilter;

/**
 * Title Filter.
 *
 * Ensure document titles from WebPlatform MediaWiki 2015-07 won’t create conflicting file names when
 * we use its title as a filename.
 *
 * Conflicts can be caused to folders being created with different casing.
 *
 * For example, consider the following and notice how casing can vary:
 *
 *    concepts/Internet and Web/The History of the Web
 *    concepts/Internet and Web/the history of the web/es
 *    concepts/Internet and Web/the history of the web/ja
 *    tutorials/canvas/canvas tutorial
 *    tutorials/canvas/Canvas tutorial/Applying styles and colors
 *    tutorials/canvas/Canvas tutorial/Basic animations
 *
 * Would create the following files and folders;
 *
 *    concepts/
 *      - Internet_and_Web/
 *        - The_History_of_the_Web/
 *          - index.html
 *        - the_history_of_the_web/
 *          - es.html
 *          - ja.html
 *    tutorials/
 *      - canvas/
 *        - canvas_tutorial/
 *          - index.html
 *        - Canvas_tutorial/
 *          - Applying_styles_and_colors/
 *            - index.html
 *
 * Notice that we would have at the same directory level with two folders
 * with almost the same name but with different casing patterns.
 *
 * On a case-sensitive filesystem, this will create conflicts we are attempting to solve here.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class TitleFilter extends AbstractFilter
{
    /**
     * Rewrite only ones that would end up creating two folders with different Casing !== casing and create
     * an issue when we write files on a filesystem due to case sensitivity.
     *
     * List of replacements from mediawiki-conversion/data/url_parts_variants.txt, and notes on why some are commented
     * and other are. All should have been compared with their actual use from 2015-07-24 snapshot of our content and
     * all the urls in use from mediawiki-conversion/data/url_all.txt
     *
     * Don’t rewrite unless necessary. Otherwise we might lose links within the content.
     **/
    public function __construct()
    {

        // ones we shouldn’t impact               // Keep commented // Why we commented
        // -------------------------------------- // -------------- // --------------------
        //$words[] = 'Accept';                    // X              // http/headers/Accept, html/attributes/accept, html/attributes/acceptCharset
        //$words[] = 'ReadOnly';                  // X              // html/attributes/readonly, .../MediaStreamTrack/readonly
        //$words[] = 'Accessibility_basics';      // X              // Accessibility_basics
        //$words[] = 'Accessibility_testing';     // X              // Accessibility_testing
        $words[] = 'Accessibility_article_ideas';
        //$words[] = 'Animatable';                // X
        //$words[] = 'Animation';                 // X              // css/properties/animation, css/properties/animations,
        $words[] = 'Canvas_tutorial';
        //$words[] = 'Connection';                // X
        //$words[] = 'Cookie';                    // X              // http/headers/Cookie, dom/Document/cookie
        //$words[] = 'css';                       // X
        //$words[] = 'DataTransfer';              // X              // dom/DragEvent/dataTransfer, dom/DataTransfer, dom/DataTransfer/clearData
        //$words[] = 'Date';                      // X
        //$words[] = 'DOCTYPE';                   // X              // html/elements/DOCTYPE, dom/Document/doctype
        //$words[] = 'Document';                  // X
        //$words[] = 'element';                   // X
        //$words[] = 'Error';                     // X
        //$words[] = 'Event';                     // X
        //$words[] = 'File';                      // X
        //$words[] = 'FileSystem';                // X
        //$words[] = 'Floats_and_clearing';       // X               // tutorials/floats_and_clearing, Floats_and_clearing
        //$words[] = 'formTarget';                // X               // html/attributes/formtarget, dom/HTMLInputElement/formTarget, html/attributes/formtarget
        //$words[] = 'Function';                  // X               // concepts/programming/javascript/functions, css/functions, javascript/Function, javascript/Function/bind
        //$words[] = 'GamePad';                   // X               // tutorials/gamepad, apis/gamepad/Gamepad, apis/gamepad/GamepadEvent/gamepad
        //$words[] = 'GeoLocation';               // X               // apis/geolocation, apis/geolocation/Coordinates/accuracy, apis/geolocation/Geolocation/clearWatch
        $words[] = 'Getting_Your_Content_Online';
        //$words[] = 'Global';                    // X
        $words[] = 'History';
        $words[] = 'How_does_the_Internet_Work';
        $words[] = 'Internet_and_Web';
        //$words[] = 'ID';                        // X
        //$words[] = 'Image';                     // X
        //$words[] = 'Implementation';            // X
        //$words[] = 'indexeddb';                 // X
        //$words[] = 'ISO';                       // X
        $words[] = 'JavaScript_for_mobile';
        //$words[] = 'Link';                      // X
        //$words[] = 'Location';                  // X               // apis/location/assign, apis/workers/WorkerGlobalScope/location, dom/KeyboardEvent/location, dom/Location/hash
        //$words[] = 'Math';                      // X
        //$words[] = 'MoveEnd';                   // X
        //$words[] = 'MoveStart';                 // X
        //$words[] = 'Navigator';                 // X
        //$words[] = 'Node';                      // X
        //$words[] = 'Number';                    // X
        //$words[] = 'oauth';                     // X
        //$words[] = 'Object';                    // X
        //$words[] = 'onLine';                    // X
        //$words[] = 'Option';                    // X
        //$words[] = 'Performance';               // X
        //$words[] = 'PhotoSettingsOptions';      // X
        //$words[] = 'PointerEvents';             // X
        //$words[] = 'Position';                  // X
        //$words[] = 'Q';                         // X
        //$words[] = 'Range';                     // X
        //$words[] = 'Region';                    // X
        $words[] = 'removeStream';
        //$words[] = 'selection';                 // X
        //$words[] = 'selectors';                 // X
        //$words[] = 'storage';                   // X
        //$words[] = 'string';                    // X
        //$words[] = 'StyleMedia';                // X
        //$words[] = 'styleSheet';                // X
        //$words[] = 'Styling_lists_and_links';   // X               // guides/Styling lists and links, tutorials/styling lists and links
        //$words[] = 'Styling_tables';            // X               // guides/styling tables, Styling tables
        //$words[] = 'text';                      // X
        //$words[] = 'tfoot';                     // X
        //$words[] = 'the_basics_of_html';        // X               // guides/the basics of html/ko, guides/the basics of html, tutorials/The basics of HTML
        $words[] = 'The_History_of_the_Web';
        //$words[] = 'thead';                     // X
        //$words[] = 'timeStamp';                 // X
        //$words[] = 'tutorials';                 // X
        //$words[] = 'Unicode';                   // X
        //$words[] = 'url';                       // X
        //$words[] = 'websocket';                 // X
        $words[] = 'What_does_a_good_web_page_need';

        // Ones that are common in an URL but yet, in this
        // precise context, had casing discrepancies.
        $words[] = 'css\/cssom\/styleSheet';
        $words[] = 'css\/selectors';
        $words[] = 'dom\/DOMTokenList';
        $words[] = 'tutorials\/HTML_forms';

        $matchers = [];
        foreach ($words as $k => $word) {
            $matchers[] = sprintf('/%s/iuS', $word);
            // We need remove RegEx escaping for
            // replacement at addPass below.
            $words[$k] = stripslashes($word);
        }

        $this->addPass($matchers, $words);

        return $this;
    }
}
