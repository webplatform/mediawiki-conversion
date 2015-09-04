# Export MediaWiki wiki pages and history into a git repository

Or any set of `<page />` with `<revision />` stored in an XML file. See [required XML schema](#xml-schema).

Expected outcome of this script will give you a Git repository.

![MediaWiki history into Git repository](https://cloud.githubusercontent.com/assets/296940/8805874/a16047a4-2fa1-11e5-8ddf-22ea3d179dc9.png)

With idempotent revisions

![mediawiki-git-comparing-commits](https://cloud.githubusercontent.com/assets/296940/8805914/e457145c-2fa1-11e5-8cbf-323cac481846.png)


### What does it do?

This project was created while making a migration from MediaWiki of WebPlatform Docs into a set of static files, converted into Markdown, into a Git repository.

Project took about a month of full time work, but the project succeeded.


#### Desired outcome

What this project helped achieve:

1. Re-create into a Git repository all edits history made in MediaWiki, and preserve date of contribution and author
1. Dump every page into text files, organized by their url (e.g. `css/properties`, `css/properties/index.md`), without any modifications
1. Export in separate Git repositories other content namespaces, not part of the main content, but still desired to be part of export (e.g. Meta documentation, User pages, etc.)
1. Convert every page into HTML using MediaWiki. Not re-inventing a MediaWiki parser. It's too rough!
1. Cache MediaWiki API HTTP calls responses. Because a small mistake might make you need to run again, and we want to save time.
1. Generate web server rewrite rules so we can still keep MediaWiki URLs, but serve the right static file
1. Get to know more about the contributions.
1. Add to git repository only uploads that are in use in the content. See [Exporting file uploads](#exporting-file-uploads).
1. Get a list of all broken links on a given page. Because MediaWiki knows which ones are broken in current page, you can export into static site and have them as notes for tidy-up work later.
1. Get a list of all code sample links.
1. Keep track of commit author without revealing real email address, e.g. Julia, who has an account on *foo.example.org* with username "Julia", becomes **Julia@foo.example.org** committer.
1. If a contributor wants to reveal his identity in git history, he can add a line in a `.mailmap` file on the exported git repository


#### Pain points encountered

There are a few things that may require you to adjust your MediaWiki installation.

While creating this project, the following happened and may happen to you too:

1. Templates that ends up creating bogus HTML when you convert into Markdown.
2. File uploads are on external Swift endpoint, we had to ensure image reference to become local before running import.
3. Code samples on many services (Dabblet, JSBin, CodePen, etc). Where are they, so we can back them up.
4. Too many file uploads, only commit ones that are still in use.
5. Some HTML blocks contains useful data, but would be inuseful to be persisted in raw HTML. How about moving them into the "Front matter" for use in a static site generator.

Those made us make a few adjustments in the configuration and patch the `SyntaxHighlight_GeSHI` extension. See [webplatform/mediawiki-conversion#19 issue](https://github.com/webplatform/mediawiki-conversion/issues/19)



#### Features

Other non use-case things this workspace helps you with.

* Use MediaWiki’s recommended MediaWiki way of backups (i.e. `maintenance/dumpBackup.php`) as data source to reproduce history
* Manage git commits per revisions with preserved author, commiter(!), and date
* Fully convert history into git, recreating metadata: author, committer, date, contents
* Write history of deleted (with or without redirects) "underneath" history of current content
* Get "reports" about the content: deleted pages, redirects, translations, number of revisions per page
* Harmonize titles and converts into valid file name (e.g. `:`,`(`,`)`,`@`) in their URL (e.g. `css/atrules/@viewport`, redirects to `css/atrules/viewport` and serve from HTML file that would be generated from `css/atrules/viewport/index.md`)
* Create list of rewrite rules to keep original URLs refering back to harmonized file name
* Write history of deleted pages "underneath" history of current content
* Ability to run script from backed up XML file (i.e. once we have XML files, no need to run script on same server)
* Import metadata such as Categories, and list of authors into generated files
* Ability to detect if a page is a translation, create a file in the same folder with language name
* Adds to front matter links that are known to be broken by MediaWiki


#### Utilities

Every Mediawiki installation is different, there’s no silver bullet.

You can use this as a starting point to extract and convert your own MediaWiki managed site.

But you'll most-likely have to fork this code and adapt to suit your content.

All commands requires a data source. In all cases, except for 3rd pass `run`, and `cache-warmer`, commands from this project reads directly without any calls through the Internet.

**Commands features**:

This isn’t an exhaustive list, but common options available to most commands.

* **--missed**: runs only through pages that are listed in `data/missed.yml`
* **--resume-at=n**: Allows you to stop the command, and resume from that point. Use the last successful page's **index**.
* **--max-pages=n**: limit the number of pages to test drive.
* **--max-revs=n**: limit the number of pages to test drive.

To get full list of available options, refer to the command help (e.g. `app/console mediawiki:run --help`).


##### summary

Generates a few reports and helps you craft your own web server redirect map.

    app/console mediawiki:summary

Since every MediaWik handles URLs with characters that maps not well to a file system path,
you'll have to provide redirects to the new location. See [keeping URLs](#keeping-urls)


##### cache-warmer

To speed up `run` at 3rd pass, the *cache-warmer* makes an HTTP call to the MediaWiki instance to keep a local copy of Parser API response body.


##### refresh-pages

What if you realize that a page has changes that requires you to edit the content in MediaWiki, or that you had to edit a transclusion template on multiple pages.

You'd want to purge a few pages, but if its too many for you to do it manually, this'll do.

To refresh only specific pages:

* Go in your web browser that has a session on your wiki instance
* Grab the `..._session=foo` session identifier from the browser's developer tools and add it into this project's `.env` file.
* Make a list of pages to refresh, add them into [data/missed.yml](./data/missed.yml)
* Run the script like this

    app/console mediawiki:refresh-pages --missed

If you have more than one data source, you could add the `--xml-source=` argument too.

    app/console mediawiki:refresh-pages --missed --xml-source=dumps/foo.xml


### Potential quirks

* Keeps commit dates, but order of commits isn’t in chronological order
* Commits follows this loop: loop through page and page create a commit for each revisions.


---


## Use


### Review your MediaWiki setup

There are a few things that may require you to adjust your MediaWiki installation.

While creating this project, the following happened and may happen to you too:

1. Templates that ends up creating bogus HTML when you convert into Markdown.
2. File uploads are on external Swift endpoint, we had to ensure image reference to become local before running import.
3. Code samples on many services (Dabblet, JSBin, CodePen, etc). Where are they, so we can back them up.
4. Too many file uploads, only commit ones that are still in use.
5. Some HTML blocks contains useful data, but would be inuseful to be persisted in raw HTML. How about moving them into the "Front matter" for use in a static site generator.


### Gather MediaWiki user data

If you don't want to impact production, you could run a on a separate computer from [MediaWiki-Vagrant](https://github.com/wikimedia/mediawiki-vagrant) and import your own database *mysqldump* into it.

This will create a usable `data/users.json` that this workbench requires from MediaWiki.

For format details, refer to [Users.json Schema](#users-json-schema).

1. Make sure the folder `mediawiki/` exists side by side with this repository and that you can use the MediaWiki instance.

    mediawiki-conversion/
    mediawiki/
    // ...

1. Run `app/export_users` script.

Notice that the **export_users** script actually uses MediaWiki's configuration file directly.


### Gather MediaWiki XML backup export

This step, like *Gater user data*, also requires a MediaWiki running installation. You could also prevent impact on production, by running from a on a separate computer from [MediaWiki-Vagrant](https://github.com/wikimedia/mediawiki-vagrant) and import your own database *mysqldump* into it.

This will create an XML file that'll contain all history.

You'll have to know which namespaces your MediaWiki installation has.

    php ../mediawiki/maintenance/dumpBackup.php --full --filter=namespace:0,108 > data/dumps/main_full.xml

Notice that the example above runs `dumpBackup.php` from this project's repository.

After this point, you don't need to run anything else directly against MediaWiki code. Except through HTTP, at `mediawiki:run`, at 3rd pass.

If you built a temporary [MediaWiki-Vagrant](https://github.com/wikimedia/mediawiki-vagrant), you can delete it now.


### Generate reports from exported data

As previously said, MediaWiki isn’t required locally anymore.

Make sure that you have a copy of your data available in a MediaWiki installation running with data, we´ll use the API
to get the parser to give us the generated HTML at the 3rd pass.

1. **Configure variables**

  Configuration is managed through `.env` file. You can copy `.env.example` into `.env` and adjust with your own details.

  Most important ones are:

    * (required) `MEDIAWIKI_API_ORIGIN` to match your own MediaWiki installation. This variable is used at `run` and `cache-warmer` commands to make HTTP calls
    * (required) `COMMITER_ANONYMOUS_DOMAIN` to make your contributor email address to `Joe@example.org`. This is meant to prevent expose history and users, without giving away their real email address.)
    * `MEDIAWIKI_USERID` your MediaWiki administrator account userid. Required to craft a valid cookie when you run `cache-warmer` to send `?action=purge` requests to MediaWiki.
    * `MEDIAWIKI_USERNAME` same as above.
    * `MEDIAWIKI_WIKINAME` name of your database, should be the same value you use in your *LocalSettings.php* at `$wgDBname` variable. Required for cookie name.


1. **Get a feel of your data**

  Run this command and you’ll know which pages are marked as deleted in history, the redirects, how the files will be called
  and so on. This gives out a very verbosic output, you may want to send the output to a file.

  This command makes no external requests, it only reads `data/users.json` (see [Gather MediaWiki user data](#gather-mediawiki-user-data)) and
  the dumpBackup XML file in `data/dumps/main_full.xml`.

  ```
  mkdir reports
  app/console mediawiki:summary > reports/summary.yml
  ```

  You can review WebPlatform Docs content summary that was in MediaWiki until 2015-07-28 in `reports/` directory of
  [webplatform/mediawiki-conversion][mwc] repository.

  If you want more details you can use the `--display-author` switch.
  The option had been added so we can commit the file without leaking our users email addresses.

  More in [Reports](#reports) below.


1. **Create `errors/` directory**

  That’s where the script will create file with the index counter number where we couldn’t get MediaWiki API render action
  to give us HTML output at 3rd pass.

  ```
  mkdir errors
  ```


1. **Create `out/` directory**

  That’s where this script will create a new git repository and convert MediaWiki revisions into Git commits

  ```
  mkdir out
  ```


1. **Review [TitleFilter][title-filter] and adapt the rules according to your content**

  Refer to [Reports](#Reports), at the [URL parts variants](#url-parts-variants) report where you may find possible file name conflicts.


1. **Run first pass**

  When you delete a document in MediaWiki, you can set a redirect. Instead of writing history of the page
  at a location that will be deleted we’ll write it at the "redirected" location.

  This command makes no external requests, it only reads `data/users.json` (from `make dumpBackup` earlier) and
  the dumpBackup XML file in `data/dumps/main_full.xml`.

  ```
  app/console mediawiki:run 1
  ```

  At the end of the first pass you should end up with an empty `out/` directory with all the deleted pages history in a new git repository.


1. **Run second pass**

  Run through all history, except deleted documents, and write git commit history.

  **This command can take more than one hour to complete**. It all depends of the number of wiki pages and revisions.

  ```
  app/console mediawiki:run 2
  ```

1. **Third pass and caching**

  The third pass is the most time consuming step. The importer will make an HTTP request to a MediaWiki endpoint
  for each wiki page to get HTML.

  To help speed up, you can create a cached copy of the output from MediaWiki Parser API.
  Each cached copy will be written into `out/.cache/0.json` where `0` stands for the wiki document id.

  You can "warm up" the cache by doing like this

      app/console mediawiki:cache-warmer

  If you updated a wiki page since a previous `mediawiki:cache-warmer` or `mediawiki:run 3` run,
  you’ll have to delete the cached file.

  If a cached file doesn’t exist, either `cache-warmer` or `mediawiki:run 3` pass will create another one automatically.


1. **Run third pass**

  This is the most time consuming pass. It’ll make a request to retrieve the HTML output of the current
  latest revision of every wiki page through MediaWiki’s internal Parser API, see [MediaWiki Parsing Wikitext][action-parser-docs].

  In order to save time, the 3rd pass creates a local copy of the contents from the API so that we don’t make
  HTTP calls to MediaWiki.

  At this pass you can *resume-at* if your script had been interrupted.

  Also, if your run had errors (see in `errors/` folder) you can add the ones you want to be
  re-run through the `data/missed.yml` file using `--missed` argument.

  While the two other pass commits every revision as a single commit, this one is intended to be ONE big commit containing
  ALL the conversion result.

  Instead of risking to lose terminal feedback you can pipe the output into a log file.

  If you have code blocks, refer to [Handle MediaWiki code syntax highlighting](#handle-mediawiki-code-syntax-highlighting)

  **First time 3rd pass**

  ```
  app/console mediawiki:run 3 > run.log
  ```

  If everything went well, you should see nothing in `errors/` folder. If that’s so; you are lucky!

  Tail the progress in a separate terminal tab. Each run has an "index" specified, if you want to resume at a specific point
  you can just use that index value in `--resume-at=n`.

  ```
  tail -f run.log
  ```


  **3rd pass had been interrupted**

  This can happen if the machine running the process had been suspended, or lost network connectivity. You can
  resume at any point by specifying the `--resume-at=n` index it been interrupted.

  ```
  app/console mediawiki:run 3 --resume-at=2450 >> run.log
  ```


  **3rd pass completed, but we had errors**

  The most possible scenario.

  Gather a coma separated list of erroneous pages and run only them.

  You’ll need to tell `data/missed.yml` which documents needs to be re-run. Each entry has to be in the same
  name as it would be after the import.

  For example. we missed:

    - html/attributes/href_base
    - apis/xhr/methods/open_XDomainRequest

  We would enter them in `data/missed.yml` like this, and tell `mediawiki:run` to read from that list.

  ```yaml
  # data/missed.yml
  missed:
    - html/attributes/href_base
    - apis/xhr/methods/open_XDomainRequest
  ```

  Then we would run:

  ```
  app/console mediawiki:run 3 --missed >> run.log
  ```


  If you had missed entries during an import made on content with namespace, you would have to format with
  the namespace name as a prefix to the entry;

  ```yaml
  # data/missed.yml
  missed:
    - WPD/Wishlist
    - WPD/Stewardship_Committee_Charter
  ```

  And run like this (see [Import other MediaWiki namespaces](#import-other-mediawiki-namespaces) below for usage details)


  ```
  app/console mediawiki:run 3 --xml-source=dumps/wpd_full.xml --namespace-prefix=WPD --missed >> run.log 2>&1
  ```

1. **Run third pass** (once more), but import image uploads

  MediaWiki manages uploads. If you are moving out, you might want to import only what your users uploaded that are still in use.

  To import them, you can use, once again 3rd pass, but only for image uploads like so:

  ```
  app/console mediawiki:run 3 --only-assets >> run.log 2>&1
  ```




1. **Import other MediaWiki namespaces**

  Importing other namespaces is also possible. This import script assumes that the main namespace would contain
  static site generator code while the other namespaces wouldn’t.

  What we want in the end is a clean main content repository that contains other namespaces as if they are folders, but yet
  are contained in separate git repositories. Git submodule isn’t always desirable, but our present use-case is perfect for that.

  Imagine you have content in your wiki that starts with "WPD:", you would have exported from your current MediaWiki instance the content
  through `dumpBackup` script like this

  ```
  php maintenance/dumpBackup.php --full --filter=namespace:3000 > ~/wpd_full.xml
  ```

  The XML file would look like this;

  ```xml
  <!-- Truncated XML, only to illustrate -->
  <foo>
  <siteinfo>
    <namespaces>
      <namespace key="3000" case="case-sensitive">WPD</namespace>
      <!-- truncated -->
    </namespaces>
  </siteinfo>
  <page>
    <title>WPD:Wishlist</title>
    <!-- truncated -->
  </page>
  <!-- more page elements here -->
  </foo>
  ```

  Notice the "WPD" and the `namespace key="3000"` matching. What matters to us here is that you see `<title>WPD:...</title>`

  Once you have the `wpd_full.xml` file imported in this repository `data/dumps/`, you can run the previously explained commands with the following options.

  ```
  app/console mediawiki:summary --xml-source=dumps/wpd_full.xml --namespace-prefix=WPD > reports/summary_wpd.yml
  app/console mediawiki:run 1 --xml-source=dumps/wpd_full.xml --namespace-prefix=WPD > run_wpd.log
  app/console mediawiki:run 2 --xml-source=dumps/wpd_full.xml --namespace-prefix=WPD >> run_wpd.log
  ```

  At this point we have all contents from the XML edits converted into commits in a new git repository.

  ```
  app/console mediawiki:cache-warmer --xml-source=dumps/wpd_full.xml
  app/console mediawiki:run 3 --xml-source=dumps/wpd_full.xml --namespace-prefix=WPD >> run_wpd.log
  app/console mediawiki:run 3 --only-assets --xml-source=dumps/wpd_full.xml --namespace-prefix=WPD >> run_wpd.log
  ```

  The difference will be that instead of creating a file as `out/content/WPD/Wishlist/index.md`, it would create them as `out/Wishlist/index.md` so we can
  use that new `out/` git repository as a git submodule from the main content repository.


---


## Reports

This repository has reports generated during [WebPlatform Docs content from MediaWiki][wpd-repo] migration commited in the `reports/` folder.
You can overwrite or delete them to leave trace of your own migration.
They were commited in this repository to illustrate how this workbench got from the migration.

### Directly on root

This report shows wiki documents that are directly on root, it helps to know what are the pages at top level before running the import.

```
// file reports/directly_on_root.txt
absolute unit
accessibility article ideas
Accessibility basics
Accessibility testing
// ...
```

### Hundred Rev(ision)s

This shows the wiki pages that has more than 100 edits.

```
// file reports/hundred_revs.txt
tutorials/Web Education Intro (105)
// ...
```

### Numbers

A summary of the content:

* Iterations: Number of wiki pages
* Content pages: Pages that are still with content (i.e. not deleted)
* redirects: Pages that redirects to other pages (i.e. when deleted, author asked to redirect)


```
// file reports/numbers.txt
Numbers:
  - iterations: 5079
  - redirects: 404
  - translated: 101
  - "content pages": 4662
  - "not in a directory": 104
  - "redirects for URL sanity": 1217
  - "edits average": 7
  - "edits median": 5
```

### Redirects

Pages that had been deleted and author asked to redirect.

This will be useful for a webserver 301 redirect map

```
// file reports/redirects.txt
Redirects (from => to):
 - "sxsw_talk_proposal": "WPD/sxsw_talk_proposal"
 - "css/Properties/color": "css/properties/color"
// ...
```

### Sanity redirects

All pages that had invalid filesystem characters (e.g. `:`,`(`,`)`,`@`) in their URL (e.g. `css/atrules/@viewport`) to make sure we don’t lose the original URL, but serve the appropriate file.

```
// file reports/sanity_redirects.txt
URLs to return new Location (from => to):
 - "tutorials/Web Education Intro": "tutorials/Web_Education_Intro"
 - "concepts/programming/about javascript": "concepts/programming/about_javascript"
 - "concepts/accessibility/accessibility basics": "concepts/accessibility/accessibility_basics"
// ...
```

### Summary

Shows all pages, the number of revisions, the date and message of the commit.

This report is generated through `app/console mediawiki:summary` and we redirect output to this file.

```yml
# file reports/symmary.yml
"tutorials/Web Education Intro":
  - normalized: tutorials/Web_Education_Intro
  - file: out/content/tutorials/Web_Education_Intro/index.md
  - revs: 105
  - revisions:
    - id: 1
      date: Tue, 29 May 2012 17:37:32 +0000
      message: Edited by MediaWiki default
    - id: 1059
      date: Wed, 22 Aug 2012 15:56:45 +0000
      message: Edited by Cmills
# ...
```

### URL all

All URLs sorted (as much as PHP can sort URLs).

```
// file reports/url_all.txt
absolute unit
accessibility article ideas
Accessibility basics
Accessibility testing
after
alignment
apis
apis/ambient light
apis/appcache
// ...
```

### URL parts

A list of all URL components, only unique entries.

If you have collisions due to casing, you should review in **url parts variants**.

```
// file reports/url_parts.txt
0_n_Properties
1_9_Properties
3d_css
3d_graphics_and_effects
20thing_pageflip
a
abbr
abort
// ...
```

### URL parts variants

A list of all URL components, showing variants in casing that will create file name conflicts during coversion.

Not all of the entries in "reports/url_parts_variants.md" are problematic, you’ll have to review all your URLs and adapt your own copy of `TitleFilter`, see [WebPlatform/Importer/Filter/TitleFilter][title-filter] class.

More about this at [Possible file name conflicts due to casing inconsistency](#possible-file-name-conflicts-due-to-casing-inconsistency)

```
// file reports/url_parts_variants.txt
All words that exists in an URL, and the different ways they are written (needs harmonizing!):
 - css, CSS
 - canvas_tutorial, Canvas_tutorial
 - The_History_of_the_Web, The_history_of_the_Web, the_history_of_the_web
// ...
```

Beware of the false positives. In the example above, we might have "css" in many parts of the URL, we can’t just rewrite for EVERY cases. In this case, you’ll notice in [TitleFilter class][title-filter] that we rewrite explicitly in the following format `'css\/cssom\/styleSheet';`, `'css\/selectors';`, etc.

You’ll have to adapt *TitleFilter* to suit your own content.


### NGINX Redirects

What will be the NGINX redirects.

This will most likely need tampering to suit your own project specifities.

```
// file reports/nginx_redirects.map
rewrite ^/wiki/css/selectors/pseudo-elements/\:\:after$ /css/selectors/pseudo-elements/after permanent;
rewrite ^/wiki/css/selectors/pseudo-classes/\:lang\(c\)$ /css/selectors/pseudo-classes/lang permanent;
rewrite ^/wiki/css/selectors/pseudo-classes/\:nth-child\(n\)$ /css/selectors/pseudo-classes/nth-child permanent;
rewrite ^/wiki/css/functions/skew\(\)$ /css/functions/skew permanent;
rewrite ^/wiki/html/attributes/background(\ |_)\(Body(\ |_)element\)$ /html/attributes/background_Body_element permanent;
// ...
```


---


## Successful imports

Here’s a list of repository that were created through this workspace.

* [**WebPlatform Docs** content from MediaWiki][wpd-repo] into a git repository


---


## Design decisions

### Handle MediaWiki code Syntax Highlighting

```php
# File mediawiki/extensions/SyntaxHighlight_GeSHi/SyntaxHighlight_GeSHi.class.php, around line: 63
# Right after the following line
$lang = strtolower( $lang );

// webplatform/mediawiki-conversion superseed GeSHi Syntax Highlight output
$lang = str_replace(['markup', 'html5'], 'html', $lang);
$lang = str_replace(['javascript', 'script'], 'js', $lang);
return sprintf("\n<pre class=\"language-%s\">\n%s\n</pre>\n", $lang, $text);
```


### Possible file name conflicts due to casing inconsistency

Conflicts can be caused to folders being created with different casing.

For example, consider the following and notice how we may get capital letters and others wouldn’t:

* concepts/Internet and Web/The History of the Web
* concepts/Internet and Web/the history of the web/es
* concepts/Internet and Web/the history of the web/ja
* tutorials/canvas/canvas tutorial
* tutorials/canvas/Canvas tutorial/Applying styles and colors
* tutorials/canvas/Canvas tutorial/Basic animations

This conversion workbench is about creating files and folders, the list of
titles above would therefore become;

```
concepts/
  - Internet_and_Web/
    - The_History_of_the_Web/
      - index.html
    - the_history_of_the_web/
      - es.html
      - ja.html
tutorials/
  - canvas/
    - canvas_tutorial/
      - index.html
    - Canvas_tutorial/
      - Applying_styles_and_colors/
        - index.html
```

Notice that we would have at the same directory level with two folders
with almost the same name but with different casing patterns.

This is what *[TitleFilter][title-filter] class* is for.


### Required

Two files are required to run the workbench;

* ***data/dumps/main_full.xml*** with all the pages and revisions as described in [XML Schema](#xml-schema)
* ***data/users.json*** with matching values from *contributor* XML node from *XML Schema*, as described in [Users.json Schema](#usersjson-schema).

#### XML Schema

MediaWiki `maintenance/dumpBackup` script ([see manual][mw-dumpbackup], [export manual][mw-export] and [xsd definition][mw-dumpbackup-xsd]) has the following XML schema but this script isn’t requiring MediaWiki at all.

In other words, if you can get an XML file with the same schema you can also use this script without changes.

Here are the essential pieces that this script expects along with notes about where they matter in the context of this workbench.

Notice the `<contributor />` XML node, you’ll have to make sure you also have same values in **data/users.json**, see [users.json][#usersjson-schema].

```xml
<foo>
  <!-- The page XML node will be manipulated via the WebPlatform\ContentConverter\Model\MediaWikiDocument class -->
  <page>
    <!-- The URL of the page. This should be the exact string your CMS supports -->
    <title>tutorials/Web Education Intro</title>
    <!-- id isn’t essential, but we use it because it helps assess how the run is going -->
    <id>1</id>
    <!-- The revision XML node will be manipulated via the WebPlatform\ContentConverter\Model\MediaWikiRevision class -->
    <revision>
      <!-- same as the page id note above -->
      <id>39463</id>
      <!-- format is in explicit "Zulu" Time.  -->
      <!-- To import this value in PHP, script does it like this:
           $date = new \DateTime($timestamp, new \DateTimeZone('Etc/UTC'))); -->
      <timestamp>2013-10-24T20:33:53Z</timestamp>
      <!-- contributor XML node requires both username and id pair. The values must match in data/users.json -->
      <contributor>
        <username>Jdoe</username>
        <!-- id must be an integer. This workbench will typecast this node into an integer. -->
        <id>11</id>
      </contributor>
      <!-- comment can be any string you want. The commit message will strip off space, HTML code, and and new lines -->
      <comment>Some optionnal edit comment</comment>
      <!-- The page content at that revision. Format isn’t important -->
      <text xml:space="preserve">Some '''text''' to import</text>
    </revision>
    <!-- more revision goes here -->
  </page>
  <!-- more page nodes goes here -->
</foo>
```


#### Users.json Schema

The origin of the data isn’t important but you have to make sure that it matches with values in [XML schema](#xml-schema):


* "`user_id`"  === `//foo/page/revision/contributor/id`. Note that the value is a string but the class `WebPlatform\ContentConverter\Model\MediaWikiContributor` will typecast into an integer
* "`user_name`" === `//foo/page/revision/contributor/username`.

As for the email address, it isn’t required because we’ll create a  git committer ID concatenating the value of "`user_name`" AND the value you would set in `lib/mediawiki.php` at the `COMMITER_ANONYMOUS_DOMAIN` constant (e.g. `COMMITER_ANONYMOUS_DOMAIN` is set to "docs.webplatform.org", commit author and commiter will be `Jdoe@docs.webplatform.org`).

```json
[
  {
     "user_email": "jdoe@example.org"
    ,"user_id": "11"
    ,"user_name": "Jdoe"
    ,"user_real_name": "John H. Doe"
    ,"user_email_authenticated": null
  }
]
```

  [mwc]: https://github.com/webplatform/mediawiki-conversion
  [action-parser-docs]: https://www.mediawiki.org/wiki/API:Parsing_wikitext
  [wpd-repo]: https://github.com/webplatform/docs
  [title-filter]: src/WebPlatform/Importer/Filter/TitleFilter.php
  [mw-dumpbackup]: https://www.mediawiki.org/wiki/Manual:DumpBackup.php
  [mw-dumpbackup-xsd]: https://www.mediawiki.org/xml/export-0.8.xsd
  [mw-export]: https://www.mediawiki.org/wiki/Help:Export
  [mw-special-export]: https://www.mediawiki.org/wiki/Manual:Parameters_to_Special:Export

