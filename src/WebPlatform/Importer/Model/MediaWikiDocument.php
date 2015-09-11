<?php

/**
 * WebPlatform MediaWiki Conversion workbench.
 */
namespace WebPlatform\Importer\Model;

use WebPlatform\ContentConverter\Model\MediaWikiDocument as BaseMediaWikiDocument;

/**
 * Adjust MediaWiki Document behavior specific to WebPlatform content.
 *
 * @author Renoir Boulanger <hello@renoirboulanger.com>
 */
class MediaWikiDocument extends BaseMediaWikiDocument
{
    // List namespaces
    public static $NAMESPACE_PREFIXES = array('10' => 'Template:','102' => 'Property:','15' => 'Category:','3000' => 'WPD:','3020' => 'Meta:');

    /** @var string page Title, but in MW it ends up being an URL too */
    protected $title = null;

    /** @var mixed string representation of the possible path or false if no redirect was specified */
    protected $redirect = false;

    const LANG_ENGLISH = 0;

    const LANG_JAPANESE = 'ja';

    const LANG_GERMAN = 'de';

    const LANG_TURKISH = 'tr';

    const LANG_KOREAN = 'ko';

    const LANG_SPANISH = 'es';

    const LANG_PORTUGUESE_BRAZIL = 'pt-br';

    const LANG_PORTUGUESE = 'pt';

    const LANG_CHINESE = 'zh';

    const LANG_CHINESE_HANT = 'zh-hant';

    const LANG_CHINESE_HANS = 'zh-hans';

    const LANG_FRENCH = 'fr';

    const LANG_SWEDISH = 'sv';

    const LANG_DUTCH = 'nl';

    /**
     * String RegEx to find if the page is a page translation.
     *
     * From https://docs.webplatform.org/wiki/Template:Languages?action=raw
     *
     * Removed:
     *
     *   - id (no translations made in this language)
     *   - th (^)
     *
     * Added:
     *
     *   - zh-hant
     *   - zh-hans
     *
     * Should reflect the list of defined translation in [[Template:Languages]] source.
     */
    const REGEX_LANGUAGES = '/\/(ar|ast|az|bcc|bg|ca|cs|da|de|diq|el|eo|es|fa|fi|fr|gl|gu|he|hu|hy|it|ja|ka|kk|km|ko|ksh|kw|mk|ml|mr|ms|nl|no|oc|pl|pt|pt\-br|ro|ru|si|sk|sl|sq|sr|sv|ta|tr|uk|vi|yue|zh|zh\-hant|zh\-hans)"$/';

    /**
     * Commonly used translation codes used in WebPlatform Docs.
     *
     * Each key represent a language code generally put at the end of a page URL (e.g. Main_Page/es).
     *
     * Value is an array of two;
     * 1. CAPITALIZED english name of the language (e.g. self::$translationCodes['zh'][0] would be 'CHINESE'), so we could map back to self::CHINESE,
     * 2. Language name in its native form (e.g. self::$translationCodes['zh'][1] would be '中文')
     *
     * See also:
     *   - https://docs.webplatform.org/w/index.php?title=Special%3AWhatLinksHere&target=Template%3ALanguages&namespace=0
     *   - https://docs.webplatform.org/wiki/WPD:Translations
     *   - https://docs.webplatform.org/wiki/WPD:Multilanguage_Support
     *   - https://docs.webplatform.org/wiki/WPD:Implementation_Patterns
     *   - http://www.w3.org/International/articles/language-tags/
     *
     * Ideally, we should use self::REGEX_LANGUAGES, but in the end after looking up dumpBackup XML file, only those had contents;
     *
     * [de,es,fr,ja,ko,nl,pt-br,sv,tr,zh,zh-hant,zh-hans]
     *
     * @var array
     */
    public static $translationCodes = array(
                    'en' => ['ENGLISH', 'English'],
                    'ja' => ['JAPANESE', '日本語'],
                    'de' => ['GERMAN', 'Deutsch'],
                    'tr' => ['TURKISH', 'Türkçe'],
                    'ko' => ['KOREAN', '한국어'],
                    'es' => ['SPANISH', 'Español'],
                    'pt-br' => ['PORTUGUESE_BRAZIL', 'Português do Brasil'],
                    'pt' => ['PORTUGUESE', 'Português'],
                    'zh' => ['CHINESE', '中文'],
                    'zh-hant' => ['CHINESE_HANT', '中文（繁體）'],
                    'zh-hans' => ['CHINESE_HANS', '中文（简体）'],
                    'fr' => ['FRENCH', 'Français'],
                    'sv' => ['SWEDISH', 'Svenska'],
                    'nl' => ['DUTCH', 'Nederlands'],
                );

    /**
     * We expect this is *only* OK the entry *just before*
     * the last *IS* either "elements" or "attributes" because
     * the current implementation used language codes that was
     * conflated with valid HTML/SVG/SGML elements and attributes.
     *
     * e.g. [tr, id, ...]
     *
     *   - html/elements/tr
     *   - html/attributes/id
     *   - svg/attributes/marker/tr
     *   - mathml/elements/menclose
     *
     * @return bool
     */
    public function isChildOfKnownPageListing()
    {
        $knownPageListings = ['elements','attributes'];

        $needles = explode('/', $this->getName());
        $size = (int) count($needles);

        if ($size < 2) {
            return false;
        }

        return in_array($needles[ $size - 2 ], $knownPageListings);
    }

    public function isTranslation()
    {
        // An edge case. Contents in html/elements/tr,
        if ($this->isChildOfKnownPageListing()) {
            return false;
        }

        return in_array($this->getLastTitleFragment(), array_keys(self::$translationCodes)) === true;
    }

    public function getDocumentTitle()
    {
        $title = $this->title;
        if ($this->isTranslation()) {
            $parts = explode('/', $title);
            $select = count($parts) - 2;

            if (isset($parts[$select])) {
                return $parts[$select];
            }
        }

        return $this->getLastTitleFragment();
    }

    public function getLastTitleFragment()
    {
        $title = $this->getTitle();

        return (strrpos($title, '/') === false)?$title:substr($title, (int) strrpos($title, '/') + 1);
    }
}
