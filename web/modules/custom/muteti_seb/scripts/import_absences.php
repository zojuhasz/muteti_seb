<?php

declare(strict_types=1);

use Drupal\Core\Database\Database;

/**
 * Imports the live Drupal 7 _szabi table into Drupal 11.
 *
 * Run with:
 *   drush php:script web/modules/custom/muteti_seb/scripts/import_absences.php
 */

$source_key = getenv('MUTETI_SOURCE') ?: 'd7_live';
$source = Database::getConnection('default', $source_key);
$target = Database::getConnection();

if (!$target->schema()->tableExists('muteti_doctor_availability')
  || !$target->schema()->fieldExists('muteti_doctor_availability', 'source')) {
  throw new RuntimeException('Előbb futtasd: vendor/bin/drush updatedb -y');
}

$users = $target->select('users_field_data', 'u')
  ->fields('u', ['uid', 'name'])
  ->condition('status', 1)
  ->execute();
$user_id_by_name = [];
foreach ($users as $user) {
  $user_id_by_name[mb_strtolower(trim((string) $user->name), 'UTF-8')] = (int) $user->uid;
}

$selected_departments = $muteti_sync_departments ?? ['Sebészet', 'Urológia', 'Onkoradiológia'];
$legacy_rows = $source->select('_szabi', 's')
  ->fields('s', ['id', 'usernev', 'datum', 'osztaly', 'timestamp'])
  ->condition('osztaly', $selected_departments, 'IN')
  ->orderBy('id')
  ->execute();

$selected_user_ids = $target->select('muteti_doctor', 'd')
  ->fields('d', ['user_id'])
  ->condition('department', $selected_departments, 'IN')
  ->isNotNull('user_id')
  ->execute()
  ->fetchCol();
if ($selected_user_ids) {
  $target->delete('muteti_doctor_availability')
    ->condition('source', 'd7')
    ->condition('user_id', array_unique(array_map('intval', $selected_user_ids)), 'IN')
    ->execute();
}

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
      'status' => 'absent',
      'source' => 'd7',
      'legacy_id' => (int) $legacy->id,
      'changed' => $changed,
    ])
    ->execute();
  $imported++;
}

print "D7 _szabi import kész.\n";
print "Importált távollétek: {$imported}\n";
print "Érvénytelen dátum miatt kihagyva: {$invalid_dates}\n";
print 'Nem talált felhasználónevek: '.count($missing_users)."\n";
foreach ($missing_users as $username => $count) {
  print "  {$username}: {$count} sor\n";
}
