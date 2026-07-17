<?php

declare(strict_types=1);

/**
 * Synchronizes all relevant data from the read-only live Drupal 7 database.
 *
 * Run with:
 *   drush php:script web/modules/custom/muteti_seb/scripts/sync_live.php
 */

putenv('MUTETI_SOURCE=d7_live');
require __DIR__.'/import_legacy.php';
