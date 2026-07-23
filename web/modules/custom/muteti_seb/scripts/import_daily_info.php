<?php

declare(strict_types=1);

use Drupal\Core\Database\Database;

/**
 * Imports the live Drupal 7 _muteti daily information table.
 *
 * Run with:
 *   drush php:script web/modules/custom/muteti_seb/scripts/import_daily_info.php
 */

$source_key = getenv('MUTETI_SOURCE') ?: 'd7_live';
$source = Database::getConnection('default', $source_key);
$target = Database::getConnection();
if (!$target->schema()->tableExists('muteti_daily_info')) {
  throw new RuntimeException('Előbb futtasd: vendor/bin/drush updatedb -y');
}

$clean = static function (mixed $value): string {
  $value = trim((string) $value);
  return $value === '-' ? '' : $value;
};
$rows = $source->select('_muteti', 'm')->fields('m', [
  'mut_dat', 'aznm1', 'aznm2', 'akut1', 'akut2',
  'ambulancia', 'tavol', 'osztaly', 'kezdido',
])->orderBy('mut_dat')->execute();
$imported = 0;
$invalid = 0;
foreach ($rows as $row) {
  $date = trim((string) $row->mut_dat);
  $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
  if (!$parsed || $parsed->format('Y-m-d') !== $date) {
    $invalid++;
    continue;
  }
  $responsible = array_filter([$clean($row->aznm1), $clean($row->aznm2)]);
  $start = $clean($row->kezdido);
  $target->merge('muteti_daily_info')
    ->key('department', trim((string) $row->osztaly))
    ->key('date', $date)
    ->fields([
      'responsible' => implode(', ', $responsible),
      'acute_1' => $clean($row->akut1),
      'acute_2' => $clean($row->akut2),
      'ambulance' => $clean($row->ambulancia),
      'other_absent' => $clean($row->tavol),
      'start_time' => preg_match('/^\d{2}:\d{2}$/', $start) ? $start : '08:30',
      'changed' => time(),
    ])->execute();
  $imported++;
}
print "D7 _muteti import kész.\n";
print "Importált napi adatok: {$imported}\n";
print "Érvénytelen dátum miatt kihagyva: {$invalid}\n";
