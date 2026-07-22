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

// The duty roster is stored in another database on the same live D7 server.
// The d7_live database user therefore needs SELECT permission on
// intra_main.ugyelet as well.
$mode_by_legacy_department = [
  'sebeszet' => 'seb',
  'urologia' => 'urol',
];
$on_call_rows = $source->query(
  "SELECT osztaly, ugynap, u1, u2 FROM intra_main.ugyelet WHERE osztaly IN ('sebeszet', 'urologia') ORDER BY ugynap"
);
$target->delete('muteti_on_call')->condition('mode', array_values($mode_by_legacy_department), 'IN')->execute();
$imported_on_call = 0;
foreach ($on_call_rows as $on_call) {
  $mode = $mode_by_legacy_department[mb_strtolower(trim((string) $on_call->osztaly), 'UTF-8')] ?? NULL;
  $date = trim((string) $on_call->ugynap);
  if (!$mode || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    continue;
  }
  $target->merge('muteti_on_call')
    ->key('mode', $mode)
    ->key('date', $date)
    ->fields([
      'doctor_name' => trim((string) $on_call->u1),
      'doctor_name_2' => $mode === 'seb' ? trim((string) $on_call->u2) : '',
    ])
    ->execute();
  $imported_on_call++;
}
print "Ügyeleti U1 adatok: {$imported_on_call}\n";
