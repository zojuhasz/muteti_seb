<?php

declare(strict_types=1);

/**
 * @file
 * Imports D7 `_naplo` entries created from 2026-01-01 onward.
 *
 * The script is rerunnable: a stable hash prevents duplicate imports.
 *
 * Run:
 *   vendor/bin/drush php:script \
 *     web/modules/custom/muteti_seb/scripts/import_audit_log.php
 */

use Drupal\Core\Database\Database;

$source_key = getenv('MUTETI_SOURCE') ?: 'd7_live';
$source = Database::getConnection('default', $source_key);
$target = \Drupal::database();
$from = '2026-01-01 00:00:00';

$users = [];
$user_rows = $target->select('users_field_data', 'u')
  ->fields('u', ['uid', 'name'])
  ->execute();
foreach ($user_rows as $user) {
  $users[mb_strtolower(trim((string) $user->name), 'UTF-8')] = (int) $user->uid;
}

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

$imported = 0;
$skipped = 0;
foreach ($rows as $row) {
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
      'department' => mb_substr(trim((string) $row->osztaly), 0, 100),
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
  $imported++;
}

print "D7 _naplo import kész (2026-01-01-től).\n";
print "Új naplóbejegyzések: {$imported}\n";
print "Már korábban importálva, kihagyva: {$skipped}\n";
