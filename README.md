# Műtéti előjegyzési rendszer

Drupal 11 alapú belső alkalmazás heti előjegyzéshez és műtőbeosztáshoz.

## Telepítés

```bash
composer install
vendor/bin/drush site:install --db-url=mysql://USER:PASSWORD@localhost/DATABASE
vendor/bin/drush en muteti_seb -y
vendor/bin/drush cr
```

A dokumentumgyökér a `web` könyvtár. Éles betegadatot, SQL-mentést és jelszót tilos a tárolóba feltölteni.

## Régi sebészeti adatok importálása

Az importáló külön `legacy` adatbázis-kapcsolatból olvas, és kizárólag a
`Sebészet` osztály előjegyzéseit tölti be. Ismételten is futtatható, nem
duplázza a dátum–napfajta párokat. A régi SQL-mentéseknek a webes
dokumentumgyökéren kívül kell maradniuk.

Futtatás:

```bash
vendor/bin/drush php:script web/modules/custom/muteti_seb/scripts/import_legacy.php
vendor/bin/drush cr
```
