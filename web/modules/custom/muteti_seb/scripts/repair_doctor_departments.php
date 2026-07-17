<?php

declare(strict_types=1);

use Drupal\Core\Database\Database;

/**
 * Restores original doctor departments from the Drupal 7 field relation.
 *
 * Run with:
 *   drush php:script web/modules/custom/muteti_seb/scripts/repair_doctor_departments.php
 */

$department_map = [
  23 => 'Urológia',
  24 => 'Sebészet',
  120 => 'Onkoradiológia',
];

$target = Database::getConnection();
try {
  $source = Database::getConnection('default', 'legacy');
}
catch (Throwable $exception) {
  throw new RuntimeException('A settings.php fájlban nincs használható legacy adatbázis-kapcsolat.', 0, $exception);
}

if (!$source->schema()->tableExists('field_data_field_oszt_ly')) {
  throw new RuntimeException('Hiányzó forrástábla: field_data_field_oszt_ly');
}
if (!$target->schema()->fieldExists('muteti_doctor', 'department')) {
  throw new RuntimeException('A department mező hiányzik. Előbb futtasd: drush updb -y');
}

$relations = $source->select('field_data_field_oszt_ly', 'o')
  ->fields('o', ['entity_id', 'field_oszt_ly_nid'])
  ->condition('entity_type', 'node')
  ->condition('bundle', 'orvosok')
  ->condition('deleted', 0)
  ->execute();

$updated = array_fill_keys(array_values($department_map), 0);
$unknown = 0;
while ($relation = $relations->fetchObject()) {
  $department = $department_map[(int) $relation->field_oszt_ly_nid] ?? NULL;
  if (!$department) {
    $unknown++;
    continue;
  }
  $count = $target->update('muteti_doctor')
    ->fields(['department' => $department])
    ->condition('legacy_nid', (int) $relation->entity_id)
    ->execute();
  $updated[$department] += $count;
}

print "Osztály-helyreállítás kész.\n";
foreach ($updated as $department => $count) {
  print "{$department}: {$count}\n";
}
print "Ismeretlen osztálykapcsolatok: {$unknown}\n";

