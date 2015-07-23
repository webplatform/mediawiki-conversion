SHELL := bash
default: import

dump:
	@if [[ -f ../mediawiki/maintenance/dumpBackup.php ]]; then\
		php ../mediawiki/maintenance/dumpBackup.php --full --filter=namespace:0,108 > data/dumps/main_full.xml;\
		php ../mediawiki/maintenance/dumpBackup.php --full --filter=namespace:3000 > data/dumps/wpd_full.xml;\
		php ../mediawiki/maintenance/dumpBackup.php --current --filter=namespace:0 > data/dumps/main.xml;\
		php ../mediawiki/maintenance/dumpBackup.php --current --filter=namespace:3000 > data/dumps/wpd.xml;\
		php ../mediawiki/maintenance/dumpBackup.php --current --filter=namespace:4 > data/dumps/project.xml;\
		php ../mediawiki/maintenance/dumpBackup.php --current --filter=namespace:2,200 > data/dumps/user.xml;\
		php ../mediawiki/maintenance/dumpBackup.php --current --filter=namespace:3020 > data/dumps/meta.xml;\
		app/export_users > data/users.json;\
	fi


