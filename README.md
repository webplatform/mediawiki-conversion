# Export MediaWiki into flat files

**Work in Progress!!**

## Features

* Use recommended MediaWiki way of backups (i.e. `maintenance/dumpBackup.php`)
* Ability to run script from backed up xml files (i.e. once we have XML files, no need to run script on same server)
* Import metadata such as Categories, and list of authors into generated files


### Roadmap

**Priorities**:

1. Dump every page into text files, organized by their url (e.g. `css/properties`, `css/properties/index.txt`), without any modifications
1. Import metadata such as Categories into new file
1. Create different file per language


**Nice to have**:

1. Set in place conversion mechanism to transform into Markdown or ReStructed
1. Recover history per each document



## Steps

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


## Dependencies

* [Symfony2 Console](https://github.com/symfony/Console) to run from the terminal
* [Symfony2 Process](https://github.com/symfony/Process) to fork processes and speed up import
* [Symfony2 Finder](https://github.com/symfony/Finder) to find files in filesystem
* [Symfony2 Filesystem](https://github.com/symfony/Filesystem) to write into the filesystem
* [Symfony2 HttpFoundation](https://github.com/symfony/HttpFoundation) to leverage reading as a stream large files.
* [Doctrine2 annotations](https://github.com/doctrine/annotations) to leverage @annotations in entity classes and tests
* [Doctrine2 collections](https://github.com/doctrine/collections)

## See also

* [Export MediaWiki gist](https://gist.github.com/renoirb/ad878a58092473267f26)
* [MediaWiki to Confluence migration](http://www.slideshare.net/NilsHofmeister/aughh-confluence)

