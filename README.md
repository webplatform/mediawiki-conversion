# Export MediaWiki into flat files

## Features

* Convert MediaWiki wiki page history into Git repository
* Dump every page into text files, organized by their url (e.g. `css/properties`, `css/properties/index.txt`), without any modifications
* Read data from MediaWiki’s recommended MediaWiki way of backups (i.e. `maintenance/dumpBackup.php`)
* Get "reports" about the content: deleted pages, redirects, translations, number of revisions per page
* Harmonize titles and converts into valid file name (e.g. replace :,(,))
* Create list of rewrite rules to keep original URLs refering back to harmonized file name
* Write history of deleted pages "underneath" history of current content
* Ability to run script from backed up XML file (i.e. once we have XML files, no need to run script on same server)
* Import metadata such as Categories, and list of authors into generated files
* Ability to detect if a page is a translation, create a file in the same folder with language name


## Steps


### Gather MediaWiki backup and user data

1. Make sure the folder `mediawiki/` exists side by side with this repository and that you can use the MediaWiki instance.

  ```
  mediawiki-conversion/
  mediawiki/
  //...
  ```

  If you want an easy way, you could use [MediaWiki-Vagrant](https://github.com/wikimedia/mediawiki-vagrant) and import your own database *mysqldump* into it.

1. Make sure you run the following from where you can run PHP code and use the database, e.g. *MediaWiki vagrant* VM.

1. Run `dumpBackup` make target; This should export content and create a cache of all users

    make dumpBackup

1. **NOTE** Once here, we do not need to run the remaining from the same machine as the one we run MediaWiki


### Run import

MediaWiki isn’t required locally.

Make sure that you have a copy of your data available in a MediaWiki installation running with data, we´ll use the API
to get the parser to give us the generated HTML at the 3rd pass.

1. Get a "feel" of your data

  Run this command and you’ll know which pages are marked as deleted in history, the redirects, how the files will be called
  and so on. This gives out a very verbosic output, you may want to send the output to a file.

  This command makes no external requests, it only reads `data/users.json` (from `make dumpBackup` earlier) and
  the dumpBackup XML file in `data/dumps/main_full.xml`.

          mkdir reports
          app/console mediawiki:summary > reports/summary.yml

  You can review WebPlatform Docs content summary that was in MediaWiki until 2015-07-28 in `reports/` directory of
  [webplatform/mediawiki-conversion][mwc] repository.

  More in [#Reports]


1. Create `errors/` directory.

  That’s where the script will create file with the index counter number where we couldn’t get MediaWiki API render action
  to give us HTML output at 3rd pass.

          mkdir errors


1. Create `out/` directory.

  That’s where this script will create a new git repository and convert MediaWiki revisions into Git commits

          mkdir out

1. Review `WebPlatform\Importer\Commands\RunCommand` class, adapt to your installation.

  * **apiUrl** should point to your own MediaWiki installation you are exporting from
  * If you need to superseed a user, look at the comment "Fix duplicates and merge them as only one" uncomment and adjust to your own project


1. Run first pass

  When you delete a document in MediaWiki, you can set a redirect. Instead of writing history of the page
  at a location that will be deleted we’ll write it at the "redirected" location.

  This command makes no external requests, it only reads `data/users.json` (from `make dumpBackup` earlier) and
  the dumpBackup XML file in `data/dumps/main_full.xml`.

          app/console mediawiki:run 1

  At the end of the first pass you should end up with an empty `out/` directory with all the deleted pages history in a new git repository.


1. Run second pass

  Run through all history, except deleted documents, and write git commit history.

  **This command can take more than one hour to complete**. It all depends of the number of wiki pages and revisions.

          app/console mediawiki:run 2


1. Run third pass

  This is the most time consuming pass. It’ll make a request to retrieve the HTML output of the current
  latest revision of every wiki page through MediaWiki’s internal Parser API, see [MediaWiki Parsing Wikitext][action-parser-docs].

  At this pass you can *resume-at* and *retry* pages that didn’t work at a previous run.

  While the two other pass commits every revision as a single commit, this one is intended to be ONE big commit containing
  ALL the conversion result.

  Instead of risking to lose terminal feedback you can pipe the output into a log file.


  **First time 3rd pass**

          app/console mediawiki:run 3 > run.log

  If everything went well, you should see nothing in `errors/` folder. If that’s so; you are lucky!

  Tail the progress in a separate terminal tab. Each run has an "index" specified, if you want to resume at a specific point
  you can just use that index value in `--resume-at=n`.

          tail -f run.log


  **3rd pass had been interrupted**

  This can happen if the machine running the process had been suspended, or lost network connectivity. You can
  resume at any point by specifying the `--resume-at=n` index it been interrupted.

          app/console mediawiki:run 3 --resume-at 2450 >> run.log


  **3rd pass completed, but we had errors**

  The most possible scenario.

  Gather a coma separated list of erroneous pages and run only them.

          // for example
          app/console mediawiki:run 3 --retry=1881,1898,1900,1902,1966,1999 >> run.log

### Result of import

The following repository had been through this script and *successfully imported* [WebPlatform Docs content from MediaWiki][wpd-repo] into a git repository.


  [mwc]: https://github.com/webplatform/mediawiki-conversion
  [action-parser-docs]: https://www.mediawiki.org/wiki/API:Parsing_wikitext
  [wpd-repo]: https://github.com/webplatform/docs
