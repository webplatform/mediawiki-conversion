<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */

namespace WebPlatform\Importer\Converter;

use WebPlatform\ContentConverter\Model\AbstractRevision;
use WebPlatform\ContentConverter\Model\MediaWikiRevision;
use WebPlatform\ContentConverter\Model\MarkdownRevision;
use WebPlatform\ContentConverter\Filter\AbstractFilter;

/**
 * MediaWiki Wikitext to Markdown Converter.
 *
 * This class handles a MediaWikiRevision instance and converts
 * the content into a MarkdownRevision.
 *
 * The apply method loops through an array of patterns,
 * replacements, and other similar functions. You can do do
 * multiple passes; each pass can be done in a wa such that you
 * can incrementally adjust patterns to get toward your
 * desired result in the following pass.
 *
 * Contents here is specific to WebPlatform Docs wiki
 * but you would can make your own and implement this library’s
 * ConverterInterface interface to roll your own.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class MediaWikiToMarkdown extends AbstractFilter implements ConverterInterface
{
    protected $leave_template_note = false;

    // Make stateless #TODO
    protected $transclusionCache = array();

    protected static $front_matter_transclusions = array(
                                                        'Flags',
                                                        'Topics',
                                                        'External_Attribution',
                                                        'Standardization_Status',
                                                        'See_Also_Section',
                                                    );

    public static function toFrontMatter($transclusions)
    {
        $out = array();
        foreach ($transclusions as $t) {
            if (isset($t['type']) && in_array($t['type'], self::$front_matter_transclusions)) {
                $type = strtolower($t['type']);
                if ($t['type'] === 'Topics' && isset($t['members'][0])) {
                    $out['tags'] = $t['members'][0];
                } elseif ($t['type'] === 'Standardization_Status' && isset($t['members']['content'])) {
                    $out[$type] = $t['members']['content'];
                } elseif ($t['type'] === 'Flags' && isset($t['members']['Content'])) {
                    $out[$type] = $t['members'];
                    $out[$type]['issues'] = explode(', ', $t['members']['High-level issues']);
                    $out[$type]['content'] = explode(', ', $t['members']['Content']);
                    unset($out[$type]['Content'], $out[$type]['High-level issues']);
                } else {
                    $out[$type] = $t['members'];
                }
            }
        }

        return $out;
    }

    protected static function helperExternlinks($matches)
    {
        $target = $matches[1];
        $text = empty($matches[2]) ? $matches[1] : $matches[2];

        return sprintf('[%s](%s)', $text, '/'.$target);
    }

    protected function helperTemplateMatchHonker($matches)
    {

        // e.g...
        // $matches[0] = '{{Page_Title|プログラミングの基礎}}';
        // $matches[1] = 'Page_Title|プログラミングの基礎';
        //
        // .. and also ones with new lines
        // $matches[1] = '{{Guide\n|Content=\n== はじめに...';
        //
        // We want type to hold "Guide"
        // e.g. `$type = 'Guide';`
        $type = trim(strstr($matches[1], '|', true));

        // template_args to hold anything else, each entry starts by a pipe ("|") symbol
        // e.g. `$template_args = array('|Is_CC-BY-SA=No','|Sources=DevOpera','|MSDN_link=','|HTML5Rocks_link=');`
        $template_args = substr($matches[1], strlen($type));

        // Make an array of all matches, and give only the ones we actually have
        // something in them.
        $filtered_args = array_filter(explode('|', $template_args));

        // We want expectable format, so we can work with it more easily later.
        // End use will possibly be as YAML data sutructure in a Markdown document
        // front matter so our static site generator can use it.
        //
        // At this time in the code, $filtered_args may look like this;
        //
        //     ["JavaScript, Foo, Bar"]
        //     ["Is_CC-BY-SA=No","MDN_link="]}
        //
        $members = array();
        foreach ($filtered_args as $i => $arg) {

            // Treat entries args ["Key=","Key2=With value"]
            // to look like ["Key":"" ,"Key2":"With value"]
            $first_equal = strpos($arg, '=');
            if (is_int($first_equal)) {
                $k = substr($arg, 0, $first_equal);
                $v = trim(substr($arg, $first_equal + 1));
                // Would we really add a member that has nothing?
                if (!empty($v)) {
                    $members[$k] = $v;
                }
            } elseif ($first_equal === false && is_int(strpos($arg, ','))) {
                // Treat entries that has coma separated, make them array
                $members[] = explode(',', $arg);
            } else {
                // Treant entries that doesn’t have equals, nor coma
                $value = trim($arg);
                if (!empty($value)) {
                    $members['content'] = $value;
                }
            }
        }

        // ...stateless
        $this->transclusionCache[] = array('type' => $type, 'members' => $members);

        //fwrite(STDERR, print_r(array('type' => $type, 'members'=> $members, 'args'=>$args), 1));
        return ($this->leave_template_note === true)?"<!-- we had a template call of type \"$type\" here -->":'';
    }

    public function __construct()
    {

        /*
         * PASS 1: MediaWiki markup caveats that has to be fixed first
         */
        $patterns[] = array(
          // Has to match something like; "|Manual_sections==== 練習問題 ==="
          // in a case where key-value is mingled with a section title, containing a one-too-many equal sign
          "/^\|([a-z_]+)\=(\=)\ ?(.*)\ ?(\=)\s+?/im",
          "/^\|([a-z_]+)\=(\=\=)\ ?(.*)\ ?(\=\=)\s+?/im",
          "/^\|([a-z_]+)\=(\=\=\=)\ ?(.*)\ ?(\=\=\=)\s+?/im",
          "/^\|([a-z_]+)\=(\=\=\=\=)\ ?(.*)\ ?(\=\=\=\=)\s+?/im",
          "/^\|([a-z_]+)\=(\=\=\=\=\=)\ ?(.*)\ ?(\=\=\=\=\=)\s+?/im",
          "/^\|([a-z_]+)\=(\=\=\=\=\=\=)\ ?(.*)\ ?(\=\=\=\=\=\=)\s+?/im",
        );

        $replacements[] = array(
          "|$1=\n$2 $3 $4",
          "|$1=\n$2 $3 $4",
          "|$1=\n$2 $3 $4",
          "|$1=\n$2 $3 $4",
          "|$1=\n$2 $3 $4",
          "|$1=\n$2 $3 $4",
        );

        /**
         * PASS 2
         */
        $patterns[] = array(
          "/^\=[^\s](.*)[^\s]\=/im",
          "/^\=\=[^\s](.*)[^\s]\=\=/im",
          "/^\=\=\=[^\s](.*)[^\s]\=\=\=/im",
          "/^\=\=\=\=[^\s](.*)[^\s]\=\=\=\=/im",
          "/^\=\=\=\=\=[^\s](.*)[^\s]\=\=\=\=\=/im",
          "/^\=\=\=\=\=\=[^\s](.*)[^\s]\=\=\=\=\=\=/im",

          // Explicit delete of empty stuff
          "/^\|("
            .'Manual_links'
            .'|External_links'
            .'|Manual_sections'
            .'|Usage'
            .'|Notes'
            .'|Import_Notes'
            .'|Notes_rows'
          .")\=\s?\n/im",

          "/^\{\{("
            .'Notes_Section'
          .")\n\}\}/im",

          "/^<syntaxhighlight(?:\ lang=\"?(\w)\"?)?>/im",
        );

        $replacements[] = array(
          '= $1 =',
          '== $1 ==',
          '=== $1 ===',
          '==== $1 ====',
          '===== $1 =====',
          '====== $1 ======',

          '',
          '',

          "```$1\n",
        );

        /**
         * PASS 3
         */
        $patterns[] = array(
          "/\r\n/",

          // Headings
          '/^=\ (.+?)\ =$/m',
          '/^==\ (.+?)\ ==$/m',
          '/^===\ (.+?)\ ===$/m',
          '/^====\ (.+?)\ ====$/m',
          '/^=====\ (.+?)\ =====$/m',
          '/^======\ (.+?)\ ======$/m',
          "/^\{\{Page_Title\}\}.*$/im",
          "/^\{\{Compatibility_Section/im",
          "/^\{\{Notes_Section\n\|Notes\=/im",

          // Delete things we won’t use anymore
          //
          // This matcher strips off anything until end of line
          // deleting the line and any contents it might have.
          // Make sure anything here is most likely only to remain on one line!
          "/^\|("
            .'Safari_mobile_prefixed_version'
            .'|Examples'       // "|Examples={{Single Example"; we’ll use "{{Examples_Section" as title
            .'|Specifications' // "|Specifications={{Related Specification"; we’ll use "{{Related_Specifications_Section"
            .'|Safari_mobile_prefixed_supported'
            .'|Safari_mobile_version'
            .'|Android_prefixed_supported'
            .'|Android_prefixed_version'
            .'|Android_supported'
            .'|Android_version'
            .'|Blackberry_prefixed_supported'
            .'|Blackberry_prefixed_version'
            .'|Blackberry_supported'
            .'|Blackberry_version'
            .'|Chrome_mobile_prefixed_supported'
            .'|Chrome_mobile_prefixed_version'
            .'|Chrome_mobile_supported'
            .'|Chrome_mobile_version'
            .'|Chrome_prefixed_supported'
            .'|Chrome_prefixed_version'
            .'|Chrome_supported'
            .'|Chrome_version'
            .'|Firefox_mobile_prefixed_supported'
            .'|Firefox_mobile_prefixed_version'
            .'|Firefox_mobile_supported'
            .'|Firefox_mobile_version'
            .'|Firefox_prefixed_supported'
            .'|Firefox_prefixed_version'
            .'|Firefox_supported'
            .'|Firefox_version'
            .'|IE_mobile_prefixed_supported'
            .'|IE_mobile_prefixed_version'
            .'|IE_mobile_supported'
            .'|IE_mobile_version'
            .'|Internet_explorer_prefixed_supported'
            .'|Internet_explorer_prefixed_version'
            .'|Internet_explorer_supported'
            .'|Internet_explorer_version'
            .'|Opera_mini_prefixed_supported'
            .'|Opera_mini_prefixed_version'
            .'|Opera_mini_supported'
            .'|Opera_mini_version'
            .'|Opera_mobile_prefixed_supported'
            .'|Opera_mobile_prefixed_version'
            .'|Opera_mobile_supported'
            .'|Opera_mobile_version'
            .'|Opera_prefixed_supported'
            .'|Opera_prefixed_version'
            .'|Opera_supported'
            .'|Opera_version'
            .'|Safari_mobile_supported'
            .'|Safari_mobile_version'
            .'|Safari_prefixed_supported'
            .'|Safari_prefixed_version'
            .'|Safari_supported'
            .'|Safari_version'
            .'|Not_required'     // "|Not_required=No", within code examples. This was a flag to handle logic we’re removing
            .'|Imported_tables'  // "|Imported_tables=" ^
            .'|Desktop_rows'     // "|Desktop_rows={{Compatibility Table Desktop Row" ^
            .'|Mobile_rows'      // "|Mobile_rows={{Compatibility Table Mobile Row" ^
            .'|Browser'
            .'|Version'
            .'|Feature'
          .").*\n/im",

          // Harmonize code fencing discrepancies.
          //
          // We’ll rewrite them after that pass.
          "/^<\/?source.*$/im",
          "/<\/?pre>/im",
          "/^\|Language\=.*\n/im",

          // Kitchensink
          "/^\|LiveURL\=(.*)\s?$/m",  # in "|Examples={{Single Example" initiated block. Word seemed unique enough to be expressed that way.
          "/^#(\w)/im",

          // Pattern sensitive rewrite #1
          //
          // The ones we rely on ordering, crossing fingers they remain consistent everywhere.
          // Try to make this list illustrate the order dependency.
          //
          // {{Related_Specifications_Section
          // |Name=DOM Level 3 Events
          // |URL=http://www.w3.org/TR/DOM-Level-3-Events/
          // |Status=Working Draft
          // |Relevant_changes=Section 4.3
          //
          // into
          //
          // ## Related specification
          //
          // * [DOM Level 3 Events](http://www.w3.org/TR/DOM-Level-3-Events/)
          // * **Status**: Working Draft
          // * **Relevant_changes**: Section 4.3
          //
          // Cannot do better than this, "Name", "Status" are likely to appear
          // in other contexts :(
          //
          "/^\{\{Related_Specifications_Section\s?$/mi",
          "/^\|URL\=(.*)$/mu",

          // Cross context safe key-value rewrite
          // Ones we can’t use here:
          //   - State: In Readiness markers, within "{{Flags"
          //   - Description: In Method Parameter
          "/^\|(Name|Status|Relevant_changes|Optional|Data\ type|Index)\=(.*)$/im",

          // API Object Method
          "/^\{\{API_Object_Method(.*)$/im",
          "/^\|Parameters\=\{\{Method\ Parameter/im",
          "/^\}\}\{\{Method\ Parameter$/im",
          //"/^\|Description=/im", # Breaks Examples_Section
          "/^\|(Method_applies_to|Example_object_name|Javascript_data_type)\=/im",

          // Explicit delete
          "/^\{\{(See_Also_Section|API_Name)\}\}/im",
          "/^\}\}\\{\{Compatibility\ (.*)\n/im",

          // Explicit rewrites
          "/^\{\{See_Also_Section/im",

          // Hopefully not too far-reaching
          "/^\|Notes_rows\=\{\{Compatibility\ (.*)\n/im", # Match "|Notes_rows={{Compatibility Notes Row"
          "/^\|Note\=/im",                              # Match "|Note=Use a legacy propri..."

        );

        $replacements[] = array(
          "\n",

          // Headings
          '#$1',
          '##$1',
          '###$1',
          '####$1',
          '#####$1',
          '######$1',
          '',
          "\n\n## Compatibility",
          "\n\n## Notes\n",

          // Delete things we won’t use anymore
          '',

          // Harmonize code fencing discrepancies.
          '```',
          "\n```\n",
          '',

          // Kitchensink
          "```\n* [Live example]($1)\n",
          '1. $1',

          // Pattern sensitive rewrite #1
          "\n\n## Related specifications\n",
          '* **Link**: $1',

          // Cross context safe key-value rewrite
          '* **$1**: $2',

          // API Object Method
          "\n\n\n## API Object Method",
          "\n### Method parameter",
          "\n### Method parameter",
          //"\n", # Breaks Examples_Section
          '* **$1**: ',

          // Explicit delete
          '',
          '',

          // Explicit rewrites
          "\n## See Also",

          // Hopefully not too far-reaching
          '',
          '',
        );

        /**
         * PASS 4
         */
        $patterns[] = array(
          "/\'\'\'\'\'(.+?)\'\'\'\'\'/s",
          "/\'\'\'(.+?)\'\'\'/s",
          "/\'\'(.+?)\'\'/s",
          '/<code>/',
          "/<\/code>/",
          '/<strong>/',
          "/<\/strong>/",
          '/<pre>/',
          "/<\/pre>/",
        );

        $replacements[] = array(
          '**$1**',
          '**$1**',
          '*$1*',
          '`',
          '`',
          '**',
          '**',
          "```\n",
          "\n```",
        );

        /*
         * Work with links
         *
         * We should know most common link patterns variations to harmonize
         * lets do that soon.
         *
        $patterns[] = array(

          "/\[((news|(ht|f)tp(s?)|irc):\/\/(.+?))( (.+))\]/i", //,'<a href="$1">$7</a>',$html);
          "/\[((news|(ht|f)tp(s?)|irc):\/\/(.+?))\]/i", //,'<a href="$1">$1</a>',$html);

        );

        $replacements[] = array(

          '[$7]($1)<a href="$1">$7</a>',
          '$1',

        );
        */

        /*
         * Lets attempt a different approach for the last bit.
         *
         * Since the biggest bit is done, let’s keep some sections
         * to use a {{Template|A=A value}} handler.
         *
         * Previous tests were not good, but maybe with much less
         * cluttered input, we might get something working now.
         *
         * This block would remove ALL '}}' elements, breaking that
         * proposed pass.
         */
        /* THIS ONE IS BOGUS, LETS FIX LATER
        $patterns[] = array(
          "/^\}\}\n/m",
          "/^\}\}$/m",
        );

        $replacements[] = array(
          '',
          '',
        );
        */

        for ($pass = 0; $pass < count($patterns); ++$pass) {
            foreach ($patterns[$pass] as $k => $v) {
                // Apply common filters
                $patterns[$pass][$k] .= 'uS';
                $this->addPass($patterns[$pass], $replacements[$pass]);
            }
        }

        return $this;
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
        // ...stateless (NOT! Gotta fix #TODO)
        $this->transclusionCache = array();

        if ($revision instanceof MediaWikiRevision) {
            $content = $this->filter($revision->getContent());

            // Should we make a loop for that?
            $content = preg_replace_callback("/^\{\{(.*?)\}\}$/imus", array($this, 'helperTemplateMatchHonker'), $content);
            $content = preg_replace_callback('/\[([^\[\]\|\n\': ]+)\]/', 'self::helperExternlinks', $content);
            $content = preg_replace_callback('/\[?\[([^\[\]\|\n\' ]+)[\| ]([^\]\']+)\]\]?/', 'self::helperExternlinks', $content);

            $front_matter = self::toFrontMatter($this->transclusionCache);

            if (empty(trim($content))) {
                $front_matter['is_stub'] = 'true';
                $content = PHP_EOL; // Let’s redefine at only one line instead of using a filter.
            }

            $rev_matter = $revision->getFrontMatterData();
            $newRev = new MarkdownRevision($content, array_merge($rev_matter, $front_matter));

            return $newRev->setTitle($revision->getTitle());
        }

        return $revision;
    }
}
