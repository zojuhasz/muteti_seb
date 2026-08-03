<?php

declare(strict_types=1);

/**
 * Fully replaces synchronized data from the read-only live Drupal 7 database.
 *
 * Run with:
 *   drush php:script web/modules/custom/muteti_seb/scripts/sync_live.php
 *   drush php:script web/modules/custom/muteti_seb/scripts/sync_live.php -- seb
 *   drush php:script web/modules/custom/muteti_seb/scripts/sync_live.php -- urol
 *   drush php:script web/modules/custom/muteti_seb/scripts/sync_live.php -- onkorad
 */

$scope = mb_strtolower(trim((string) ($argv[1] ?? 'all')), 'UTF-8');
$scope_aliases = [
  'all' => ['seb', 'urol', 'onkorad'],
  'seb' => ['seb'],
  'sebeszet' => ['seb'],
  'sebészet' => ['seb'],
  'urol' => ['urol'],
  'uro' => ['urol'],
  'urologia' => ['urol'],
  'urológia' => ['urol'],
  'onkorad' => ['onkorad'],
  'onkoradiologia' => ['onkorad'],
  'onkoradiológia' => ['onkorad'],
];
if (!isset($scope_aliases[$scope])) {
  throw new InvalidArgumentException(
    "Ismeretlen osztály: {$scope}. Használható: all, seb, urol, onkorad."
  );
}
$muteti_sync_modes = $scope_aliases[$scope];
$muteti_sync_departments = array_values(array_intersect_key([
  'Sebészet' => TRUE,
  'Urológia' => TRUE,
  'Onkoradiológia' => TRUE,
], array_flip(array_map(static fn (string $mode): string => [
  'seb' => 'Sebészet',
  'urol' => 'Urológia',
  'onkorad' => 'Onkoradiológia',
][$mode], $muteti_sync_modes))));
putenv('MUTETI_SOURCE=d7_live');
putenv('MUTETI_SYNC_MODES='.implode(',', $muteti_sync_modes));

print 'Teljes D7 live felülírás: '.implode(', ', $muteti_sync_departments)."\n";
require __DIR__.'/import_legacy.php';

// The duty roster is stored in another database on the same live D7 server.
$mode_by_legacy_department = [
  'sebeszet' => 'seb',
  'urologia' => 'urol',
];
$selected_legacy_on_call_departments = array_keys(array_filter(
  $mode_by_legacy_department,
  static fn (string $mode): bool => in_array($mode, $muteti_sync_modes, TRUE),
));
if ($selected_legacy_on_call_departments) {
  $on_call_rows = $source->query(
    "SELECT osztaly, ugynap, u1, u2
     FROM intra_main.ugyelet
     WHERE osztaly IN ('sebeszet', 'urologia')
     ORDER BY ugynap"
  );
  $target->delete('muteti_on_call')
    ->condition('mode', array_values(array_intersect($mode_by_legacy_department, $muteti_sync_modes)), 'IN')
    ->execute();
  $imported_on_call = 0;
  foreach ($on_call_rows as $on_call) {
    $legacy_department = mb_strtolower(trim((string) $on_call->osztaly), 'UTF-8');
    $mode = $mode_by_legacy_department[$legacy_department] ?? NULL;
    $date = trim((string) $on_call->ugynap);
    if (!$mode
      || !in_array($legacy_department, $selected_legacy_on_call_departments, TRUE)
      || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      continue;
    }
    $target->merge('muteti_on_call')
      ->key('mode', $mode)
      ->key('date', $date)
      ->fields([
        'doctor_name' => trim((string) $on_call->u1),
        'doctor_name_2' => trim((string) $on_call->u2),
      ])
      ->execute();
    $imported_on_call++;
  }
  print "Ügyeleti U1 adatok: {$imported_on_call}\n";
}

putenv('MUTETI_SOURCE=d7_live');
require __DIR__.'/import_daily_info.php';
putenv('MUTETI_SOURCE=d7_live');
require __DIR__.'/import_absences.php';
putenv('MUTETI_SOURCE=d7_live');
require __DIR__.'/import_away.php';
putenv('MUTETI_SOURCE=d7_live');
require __DIR__.'/import_audit_log.php';

if (in_array('onkorad', $muteti_sync_modes, TRUE)) {
  require __DIR__.'/import_oncology_treatments.php';
}
