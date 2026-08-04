<?php

declare(strict_types=1);

/**
 * @file
 * Imports D7 `_naplo` entries created from 2026-01-01 onward.
 *
 * Only legacy rows are replaced. Audit entries created natively in Drupal 11
 * have no legacy_key and are deliberately preserved.
 */

use Drupal\Core\Database\Database;

$source_key = getenv('MUTETI_SOURCE') ?: 'd7_live';
$source = Database::getConnection('default', $source_key);
$target = \Drupal::database();
$from = '2026-01-01 00:00:00';
$selected_departments = $muteti_sync_departments ?? ['Sebészet', 'Urológia', 'Onkoradiológia'];

$normalize_department = static function (?string $department): ?string {
  $normalized = mb_strtolower(trim((string) $department), 'UTF-8');
  $normalized = strtr($normalized, [
    'á' => 'a',
    'é' => 'e',
    'í' => 'i',
    'ó' => 'o',
    'ö' => 'o',
    'ő' => 'o',
    'ú' => 'u',
    'ü' => 'u',
    'ű' => 'u',
  ]);
  $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized) ?? '';

  return [
    'seb' => 'Sebészet',
    'sebeszet' => 'Sebészet',
    'urol' => 'Urológia',
    'urologia' => 'Urológia',
    'onko' => 'Onkoradiológia',
    'onkorad' => 'Onkoradiológia',
    'onkologia' => 'Onkoradiológia',
    'onkoradiologia' => 'Onkoradiológia',
  ][$normalized] ?? NULL;
};

// Replace only rows previously imported from D7. Native D11 audit entries
// have no legacy_key and must survive every live synchronization.
$deleted = $target->delete('muteti_audit_log')
  ->condition('department', $selected_departments, 'IN')
  ->isNotNull('legacy_key')
  ->condition('legacy_key', '', '<>')
  ->execute();

$users = [];
$user_rows = $target->select('users_field_data', 'u')
  ->fields('u', ['uid', 'name'])
  ->execute();
foreach ($user_rows as $user) {
  $users[mb_strtolower(trim((string) $user->name), 'UTF-8')] = (int) $user->uid;
}

// Do not filter by the raw D7 department text in SQL: historical rows use
// multiple spellings. Normalize first, then apply the selected D11 scope.
$rows = $source->select('_naplo', 'n')
  ->fields('n', [
    'user',
    'osztaly',
    'edatum',
    'nf',
    'betegnev',
    'betegazon',
    'muvelet',
    'idopont',
    'timestamp',
  ])
  ->condition('idopont', $from, '>=')
  ->orderBy('idopont')
  ->execute();

$seen = $target->select('muteti_audit_log', 'l')
  ->fields('l', ['legacy_key'])
  ->isNotNull('legacy_key')
  ->execute()
  ->fetchCol();
$seen = array_fill_keys(array_filter($seen), TRUE);

$imported_by_department = array_fill_keys($selected_departments, 0);
$skipped = 0;
$unknown_departments = [];
foreach ($rows as $row) {
  $department = $normalize_department((string) $row->osztaly);
  if ($department === NULL) {
    $raw_department = trim((string) $row->osztaly);
    $unknown_departments[$raw_department !== '' ? $raw_department : '(üres)'] = TRUE;
    $skipped++;
    continue;
  }
  if (!in_array($department, $selected_departments, TRUE)) {
    continue;
  }

  $raw = [
    (string) $row->user,
    (string) $row->osztaly,
    (string) $row->edatum,
    (string) $row->nf,
    (string) $row->betegnev,
    (string) ($row->betegazon ?? ''),
    (string) $row->muvelet,
    (string) $row->idopont,
    (string) $row->timestamp,
  ];
  $legacy_key = hash('sha256', implode("\x1F", $raw));
  if (isset($seen[$legacy_key])) {
    $skipped++;
    continue;
  }

  $username = trim((string) $row->user);
  $appointment_date = trim((string) $row->edatum);
  try {
    $parsed_date = new DateTimeImmutable($appointment_date);
    $appointment_date = $parsed_date->format('Y-m-d');
  }
  catch (Throwable) {
    $appointment_date = mb_substr($appointment_date, 0, 10);
  }
  try {
    $created_at = new DateTimeImmutable(
      trim((string) $row->idopont),
      new DateTimeZone('Europe/Budapest'),
    );
    $created = $created_at->getTimestamp();
  }
  catch (Throwable) {
    $created = 0;
  }

  $target->insert('muteti_audit_log')
    ->fields([
      'user_id' => $users[mb_strtolower($username, 'UTF-8')] ?? 0,
      'username' => mb_substr($username, 0, 60),
      'department' => $department,
      'appointment_date' => mb_substr($appointment_date, 0, 10),
      'slot_type' => mb_substr(trim((string) $row->nf), 0, 30),
      'patient_name' => mb_substr(trim((string) $row->betegnev), 0, 100),
      'patient_reference' => mb_substr(trim((string) ($row->betegazon ?? '')), 0, 50),
      'action' => mb_substr(trim((string) $row->muvelet), 0, 100),
      'created' => $created,
      'legacy_key' => $legacy_key,
    ])
    ->execute();
  $seen[$legacy_key] = TRUE;
  $imported_by_department[$department]++;
}

print "D7 _naplo import kész (2026-01-01-től).\n";
print "Törölt korábbi D7-naplóbejegyzések: {$deleted}\n";
foreach ($imported_by_department as $department => $count) {
  print "Importált naplóbejegyzések – {$department}: {$count}\n";
}
print "Kihagyott naplóbejegyzések: {$skipped}\n";
if ($unknown_departments) {
  print 'Ismeretlen D7 osztálynevek: '.implode(', ', array_keys($unknown_departments))."\n";
}
