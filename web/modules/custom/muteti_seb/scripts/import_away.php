<?php

declare(strict_types=1);

use Drupal\Core\Database\Database;

/**
 * Imports the live Drupal 7 _flor table as "Idegenben" days.
 *
 * Run with:
 *   drush php:script web/modules/custom/muteti_seb/scripts/import_away.php
 */

$source_key = getenv('MUTETI_SOURCE') ?: 'd7_live';
$source = Database::getConnection('default', $source_key);
$target = Database::getConnection();

if (!$target->schema()->tableExists('muteti_doctor_availability')
  || !$target->schema()->fieldExists('muteti_doctor_availability', 'source')) {
  throw new RuntimeException('Előbb futtasd: vendor/bin/drush updatedb -y');
}

$required_columns = ['id', 'usernev', 'datum', 'osztaly', 'timestamp'];
foreach ($required_columns as $column) {
  if (!$source->schema()->fieldExists('_flor', $column)) {
    throw new RuntimeException("A D7 _flor táblából hiányzik a(z) {$column} mező.");
  }
}

$users = $target->select('users_field_data', 'u')
  ->fields('u', ['uid', 'name'])
  ->condition('status', 1)
  ->execute();
$user_id_by_name = [];
foreach ($users as $user) {
  $user_id_by_name[mb_strtolower(trim((string) $user->name), 'UTF-8')] = (int) $user->uid;
}

$legacy_rows = $source->select('_flor', 'f')
  ->fields('f', $required_columns)
  ->orderBy('id')
  ->execute();

$target->delete('muteti_doctor_availability')
  ->condition('source', 'd7_flor')
  ->execute();

$imported = 0;
$missing_users = [];
$invalid_dates = 0;
foreach ($legacy_rows as $legacy) {
  $username = trim((string) $legacy->usernev);
  $user_id = $user_id_by_name[mb_strtolower($username, 'UTF-8')] ?? 0;
  if (!$user_id) {
    $missing_users[$username] = ($missing_users[$username] ?? 0) + 1;
    continue;
  }
  $date = trim((string) $legacy->datum);
  $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
  if (!$parsed || $parsed->format('Y-m-d') !== $date) {
    $invalid_dates++;
    continue;
  }
  $changed = strtotime((string) $legacy->timestamp) ?: time();
  $target->merge('muteti_doctor_availability')
    ->key('user_id', $user_id)
    ->key('date', $date)
    ->fields([
      'status' => 'away',
      'source' => 'd7_flor',
      'legacy_id' => (int) $legacy->id,
      'changed' => $changed,
    ])
    ->execute();
  $imported++;
}

print "D7 _flor import kész.\n";
print "Importált idegenben napok: {$imported}\n";
print "Érvénytelen dátum miatt kihagyva: {$invalid_dates}\n";
print 'Nem talált felhasználónevek: '.count($missing_users)."\n";
foreach ($missing_users as $username => $count) {
  print "  {$username}: {$count} sor\n";
}
