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
