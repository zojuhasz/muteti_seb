<?php

declare(strict_types=1);

use Drupal\Core\Database\Database;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Imports the Drupal 7 surgical scheduler from the named "legacy" database.
 *
 * Run with:
 *   drush php:script web/modules/custom/muteti_seb/scripts/import_legacy.php
 */

$legacy_department_map = [
  23 => 'Urológia',
  24 => 'Sebészet',
  120 => 'Onkoradiológia',
];
$target = Database::getConnection();
$source_key = getenv('MUTETI_SOURCE') ?: 'legacy';

try {
  $source = Database::getConnection('default', $source_key);
}
catch (Throwable $exception) {
  throw new RuntimeException("A settings.php fájlban nincs használható {$source_key} adatbázis-kapcsolat.", 0, $exception);
}
$source_database = (string) ($source->getConnectionOptions()['database'] ?? 'ismeretlen');
if (!$target->schema()->tableExists('muteti_legacy_user')) {
  throw new RuntimeException('Hiányzik a muteti_legacy_user tábla. Előbb futtasd: drush updb -y');
}

$required_tables = [
  '_elojegyzes',
  'node',
  'users',
  'users_roles',
  'role',
  'field_data_field_oszt_ly',
  'field_data_field_usern_v',
  'field_data_field_sz_n',
  'field_data_field_bet_sz_n',
];
foreach ($required_tables as $table) {
  if (!$source->schema()->tableExists($table)) {
    throw new RuntimeException("Hiányzó forrástábla: {$table}");
  }
}

$normalize_name = static function (?string $name): string {
  $name = str_replace(['Dr,', 'dr,'], ['Dr.', 'dr.'], trim((string) $name));
  $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
  return mb_strtolower($name, 'UTF-8');
};
$valid_date = static function (?string $date): ?string {
  if (!$date || in_array($date, ['0000-00-00', '1111-11-11'], TRUE)) {
    return NULL;
  }
  $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
  return $parsed && $parsed->format('Y-m-d') === $date ? $date : NULL;
};
$valid_color = static function (?string $color): ?string {
  $color = trim((string) $color);
  return preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $color) ? $color : NULL;
};

// Department nodes are absent from the partial node dump, so use the original
// field relation identifiers: 23 Urológia, 24 Sebészet, 120 Onkoradiológia.
$department_nids = array_keys($legacy_department_map);

// Users referenced by any appointment or linked to a doctor on any department.
$usernames = $source->select('_elojegyzes', 'e')
  ->distinct()
  ->fields('e', ['user'])
  ->condition('user', '', '<>')
  ->execute()
  ->fetchCol();
$legacy_user_ids = [];
if ($department_nids) {
  $query = $source->select('field_data_field_oszt_ly', 'o');
  $query->join('field_data_field_usern_v', 'u', 'u.entity_id = o.entity_id AND u.deleted = 0');
  $legacy_user_ids = $query->distinct()
    ->fields('u', ['field_usern_v_uid'])
    ->condition('o.deleted', 0)
    ->condition('o.field_oszt_ly_nid', $department_nids, 'IN')
    ->execute()
    ->fetchCol();
}
$application_role_names = [
  'view',
  'orvos1',
  'orvos2',
  'orvos',
  'boss',
  'adminisztrátor',
  'seb',
  'urol',
  'urolview',
  'onkorad',
];
$role_user_query = $source->select('users_roles', 'ur');
$role_user_query->join('role', 'r', 'r.rid = ur.rid');
$application_user_ids = $role_user_query
  ->distinct()
  ->fields('ur', ['uid'])
  ->condition('r.name', $application_role_names, 'IN')
  ->execute()
  ->fetchCol();

$user_query = $source->select('users', 'u')->fields('u');
$or = $user_query->orConditionGroup();
if ($usernames) {
  $or->condition('name', $usernames, 'IN');
}
if ($legacy_user_ids) {
  $or->condition('uid', $legacy_user_ids, 'IN');
}
if ($application_user_ids) {
  $or->condition('uid', $application_user_ids, 'IN');
}
$user_query->condition($or)->condition('uid', 0, '>');
$legacy_users = $user_query->execute()->fetchAll();

$role_map = [
  'view' => ['muteti_view'],
  'orvos1' => ['muteti_orvos1'],
  'orvos2' => ['muteti_orvos2'],
  'orvos' => ['muteti_orvos3'],
  'boss' => ['muteti_boss'],
  'adminisztrátor' => ['muteti_orvos3'],
  'seb' => ['muteti_department_seb'],
  'urol' => ['muteti_department_urol'],
  'urolview' => ['muteti_department_urol', 'muteti_view'],
  'onkorad' => ['muteti_department_onkorad'],
];
$managed_roles = array_values(array_unique(array_merge(...array_values($role_map))));
$available_roles = array_fill_keys(array_keys(Role::loadMultiple()), TRUE);
$new_uid_by_legacy_uid = [];
$new_uid_by_name = [];
$imported_users = 0;
$synchronized_passwords = 0;

foreach ($legacy_users as $legacy_user) {
  $mapped_uid = $target->select('muteti_legacy_user', 'm')
    ->fields('m', ['user_id'])
    ->condition('legacy_uid', (int) $legacy_user->uid)
    ->execute()
    ->fetchField();
  $account = $mapped_uid ? User::load((int) $mapped_uid) : NULL;
  if (!$account) {
    $matches = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $legacy_user->name]);
    $account = $matches ? reset($matches) : NULL;
  }
  $is_new = !$account;
  $account = $account ?: User::create([
    'name' => $legacy_user->name,
    'status' => (int) $legacy_user->status,
    'created' => max(1, (int) $legacy_user->created),
  ]);
  $is_protected_admin = !$is_new && (int) $account->id() === 1;
  $password_hash_to_sync = ($is_new || ($source_key === 'd7_live' && !$is_protected_admin)) && !empty($legacy_user->pass)
    ? (string) $legacy_user->pass
    : NULL;
  if (!empty($legacy_user->mail) && filter_var($legacy_user->mail, FILTER_VALIDATE_EMAIL)) {
    $account->setEmail($legacy_user->mail);
  }
  if ((int) $legacy_user->status === 1) {
    $account->activate();
  }
  else {
    $account->block();
  }

  $role_query = $source->select('users_roles', 'ur');
  $role_query->join('role', 'r', 'r.rid = ur.rid');
  $legacy_roles = $role_query->fields('r', ['name'])
    ->condition('ur.uid', $legacy_user->uid)
    ->execute()
    ->fetchCol();
  // The live D7 assignments are authoritative for imported application roles.
  foreach ($managed_roles as $managed_role) {
    if ($account->hasRole($managed_role)) {
      $account->removeRole($managed_role);
    }
  }
  foreach ($legacy_roles as $legacy_role) {
    $new_roles = $role_map[mb_strtolower($legacy_role, 'UTF-8')] ?? [];
    foreach ($new_roles as $new_role) {
      if (isset($available_roles[$new_role])) {
        $account->addRole($new_role);
      }
    }
  }
  // Every imported scheduler user must at least be able to view schedules.
  if (isset($available_roles['muteti_view'])) {
    $account->addRole('muteti_view');
  }
  $account->save();
  if ($password_hash_to_sync !== NULL) {
    // setPassword() would hash the already hashed D7 value again. Store the
    // trusted read-only source hash verbatim; Drupal upgrades it after login.
    $target->update('users_field_data')
      ->fields(['pass' => $password_hash_to_sync])
      ->condition('uid', (int) $account->id())
      ->execute();
    \Drupal::entityTypeManager()->getStorage('user')->resetCache([(int) $account->id()]);
    $synchronized_passwords++;
  }
  $target->merge('muteti_legacy_user')
    ->key('legacy_uid', (int) $legacy_user->uid)
    ->fields([
      'user_id' => (int) $account->id(),
      'legacy_name' => (string) $legacy_user->name,
      'synced' => time(),
    ])
    ->execute();
  $new_uid_by_legacy_uid[(int) $legacy_user->uid] = (int) $account->id();
  $new_uid_by_name[mb_strtolower($legacy_user->name, 'UTF-8')] = (int) $account->id();
  $imported_users++;
}

// Import doctors from every known department with user links and colors.
$doctor_query = $source->select('node', 'n');
$doctor_query->leftJoin('field_data_field_oszt_ly', 'o', 'o.entity_id = n.nid AND o.deleted = 0');
$doctor_query->leftJoin('field_data_field_usern_v', 'u', 'u.entity_id = n.nid AND u.deleted = 0');
$doctor_query->leftJoin('field_data_field_sz_n', 'bg', 'bg.entity_id = n.nid AND bg.deleted = 0');
$doctor_query->leftJoin('field_data_field_bet_sz_n', 'fg', 'fg.entity_id = n.nid AND fg.deleted = 0');
$doctor_query->fields('n', ['nid', 'title', 'status']);
$doctor_query->addField('u', 'field_usern_v_uid', 'legacy_uid');
$doctor_query->addField('bg', 'field_sz_n_value', 'background_color');
$doctor_query->addField('fg', 'field_bet_sz_n_value', 'text_color');
$doctor_query->addField('o', 'field_oszt_ly_nid', 'department_nid');
$doctor_query->condition('n.type', 'orvosok');
if ($department_nids) {
  $doctor_query->condition('o.field_oszt_ly_nid', $department_nids, 'IN');
}
$legacy_doctors = $doctor_query->execute()->fetchAll();

$doctor_id_by_name = [];
$imported_doctors = 0;
foreach ($legacy_doctors as $doctor) {
  $fields = [
    'legacy_nid' => (int) $doctor->nid,
    'user_id' => $new_uid_by_legacy_uid[(int) $doctor->legacy_uid] ?? NULL,
    'name' => trim($doctor->title),
    'background_color' => $valid_color($doctor->background_color),
    'text_color' => $valid_color($doctor->text_color),
    'department' => $legacy_department_map[(int) $doctor->department_nid] ?? 'Ismeretlen',
    'active' => (int) $doctor->status,
  ];
  $target->merge('muteti_doctor')
    ->key('legacy_nid', (int) $doctor->nid)
    ->fields($fields)
    ->execute();
  $id = $target->select('muteti_doctor', 'd')->fields('d', ['id'])->condition('legacy_nid', $doctor->nid)->execute()->fetchField();
  $doctor_id_by_name[$normalize_name($doctor->title)] = (int) $id;
  $imported_doctors++;
}

// Add doctors/assistants referenced by appointments but absent in the node list.
$name_query = $source->select('_elojegyzes', 'e');
$name_query->addExpression('DISTINCT orvos', 'name');
$referenced_names = $name_query->execute()->fetchCol();
foreach (['assz1', 'assz2', 'assz3'] as $assistant_field) {
  $query = $source->select('_elojegyzes', 'e');
  $query->addExpression("DISTINCT {$assistant_field}", 'name');
  $referenced_names = array_merge($referenced_names, $query->execute()->fetchCol());
}
foreach (array_unique($referenced_names) as $name) {
  $normalized = $normalize_name($name);
  if ($normalized === '' || $normalized === '-' || isset($doctor_id_by_name[$normalized])) {
    continue;
  }
  $id = $target->select('muteti_doctor', 'd')->fields('d', ['id'])->condition('name', trim($name))->execute()->fetchField();
  if (!$id) {
    $id = $target->insert('muteti_doctor')->fields([
      'name' => trim($name),
      'department' => 'Ismeretlen',
      'active' => 1,
    ])->execute();
  }
  $doctor_id_by_name[$normalized] = (int) $id;
  $imported_doctors++;
}

// Deliberately import the complete history of every department. No date range:
// past, current and future appointments are all relevant migration data.
$appointments = $source->select('_elojegyzes', 'e')
  ->fields('e')
  ->orderBy('edatum')
  ->orderBy('fajta')
  ->execute();
$today = date('Y-m-d');
$imported_appointments = 0;
$skipped_appointments = 0;
$removed_appointments = 0;
$earliest_admission = NULL;
$latest_admission = NULL;
$stale_imported_ids = [];
$target_legacy_by_slot = [];
if ($source_key === 'd7_live') {
  $imported_rows = $target->select('muteti_appointment', 'a')
    ->fields('a', ['id', 'legacy_id', 'department', 'admission_date', 'slot_type'])
    ->condition('legacy_id', 'elojegyzes:%', 'LIKE')
    ->execute();
  while ($imported_row = $imported_rows->fetchObject()) {
    $stale_imported_ids[(string) $imported_row->legacy_id] = (int) $imported_row->id;
    $target_legacy_by_slot[$imported_row->department."\0".$imported_row->admission_date."\0".$imported_row->slot_type] = (string) $imported_row->legacy_id;
  }
}

while ($appointment = $appointments->fetchObject()) {
  $admission_date = $valid_date($appointment->edatum);
  if (!$admission_date || trim($appointment->fajta) === '') {
    $skipped_appointments++;
    continue;
  }
  $legacy_id = 'elojegyzes:' . sha1($appointment->edatum . '|' . $appointment->fajta . '|' . $appointment->osztaly);
  $department = trim((string) $appointment->osztaly) ?: 'Ismeretlen';
  $slot_type = trim((string) $appointment->fajta);
  $slot_key = $department."\0".$admission_date."\0".$slot_type;
  unset($stale_imported_ids[$legacy_id]);
  if (isset($target_legacy_by_slot[$slot_key])) {
    // The same slot may have been reused in D7 for another patient.
    unset($stale_imported_ids[$target_legacy_by_slot[$slot_key]]);
  }
  $earliest_admission = $earliest_admission === NULL || $admission_date < $earliest_admission ? $admission_date : $earliest_admission;
  $latest_admission = $latest_admission === NULL || $admission_date > $latest_admission ? $admission_date : $latest_admission;
  $surgery_date = $valid_date($appointment->mut_dat);
  $legacy_room = trim((string) $appointment->muto);
  $operating_room = $legacy_room !== '' && $legacy_room !== '0' ? $legacy_room : NULL;
  $notes = trim((string) $appointment->egyeb);
  $anaesth = trim((string) $appointment->anaesth);
  $allowed_anaesth = [
    'Local', 'i.v. narc.', 'i.v. Laryng', 'Spinal',
    'ITN', 'ITN+EDA', 'I.v. + N. obt blokad',
  ];
  if (!in_array($anaesth, $allowed_anaesth, TRUE)) {
    $anaesth = '';
  }
  $legacy_care_type = mb_strtolower(trim((string) $appointment->egynapos), 'UTF-8');
  $care_type = str_contains($legacy_care_type, 'egynap')
    ? 'one_day'
    : (str_contains($legacy_care_type, 'aznap') ? 'same_day' : 'normal');
  $created = strtotime((string) $appointment->stamp) ?: time();
  $fields = [
    'legacy_id' => $legacy_id,
    'department' => $department,
    'admission_date' => $admission_date,
    'slot_type' => $slot_type,
    'aznm' => $department === 'Urológia' ? 0 : (int) !empty($appointment->egynapos),
    'care_type' => $department === 'Urológia' ? $care_type : 'normal',
    'patient_name' => trim($appointment->nev),
    'birth_date' => $valid_date($appointment->szuldat),
    'taj' => trim($appointment->taj),
    'contact' => trim($appointment->elerhetoseg),
    'ward_room' => trim($appointment->korterem),
    'diagnosis' => trim($appointment->diag),
    'operation_name' => trim($appointment->mutet),
    'laparoscope' => trim($appointment->laparoscope),
    'mesh' => trim($appointment->halo),
    'laterality' => trim($appointment->oldalisag),
    'blood_type' => trim($appointment->vercsop),
    'anaesth' => $anaesth ?: NULL,
    'notes' => $notes,
    'doctor_id' => $doctor_id_by_name[$normalize_name($appointment->orvos)] ?? NULL,
    'assistant1_id' => $doctor_id_by_name[$normalize_name($appointment->assz1)] ?? NULL,
    'assistant2_id' => $doctor_id_by_name[$normalize_name($appointment->assz2)] ?? NULL,
    'assistant3_id' => $doctor_id_by_name[$normalize_name($appointment->assz3)] ?? NULL,
    'surgery_date' => $surgery_date,
    'operating_room' => $operating_room,
    'surgery_order' => max(0, (int) $appointment->mut_sorrend),
    // Historical rows stay available but cannot flood the active waiting list.
    'operated' => (int) ($admission_date < $today),
    'created_by' => $new_uid_by_name[mb_strtolower(trim($appointment->user), 'UTF-8')] ?? 0,
    'created' => $created,
    'changed' => $created,
  ];
  $target->merge('muteti_appointment')
    ->key('department', $department)
    ->key('admission_date', $admission_date)
    ->key('slot_type', $slot_type)
    ->fields($fields)
    ->execute();
  $imported_appointments++;
}

// A live synchronization is authoritative. Remove only rows that originally
// came from D7 and no longer exist there. Native Drupal 11 rows have no such
// legacy_id and are therefore never deleted here.
if ($source_key === 'd7_live' && $stale_imported_ids) {
  foreach (array_chunk(array_values($stale_imported_ids), 500) as $ids) {
    $removed_appointments += $target->delete('muteti_appointment')
      ->condition('id', $ids, 'IN')
      ->execute();
  }
}

print "Import kész.\n";
print "Forráskapcsolat: {$source_key}\n";
print "Forrásadatbázis: {$source_database}\n";
print "Felhasználók: {$imported_users}\n";
print "D7 jelszó-hash frissítve: {$synchronized_passwords}\n";
print "Orvosok és asszisztensek: {$imported_doctors}\n";
print "Összes előjegyzés: {$imported_appointments}\n";
print "Az éles D7-ben már nem létező importált előjegyzések törölve: {$removed_appointments}\n";
print "Átvett dátumtartomány: ".($earliest_admission ?? 'nincs')." – ".($latest_admission ?? 'nincs')."\n";
print "Érvénytelen dátum vagy üres műtéttípus miatt kihagyva: {$skipped_appointments}\n";
