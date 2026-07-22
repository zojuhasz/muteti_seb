<?php

namespace Drupal\muteti_seb\Service;

final class AuditLog {

  public static function write(
    string $action,
    string $department,
    string $date,
    string $slot,
    ?string $patientReference = NULL,
  ): void {
    $account = \Drupal::currentUser();
    \Drupal::database()->insert('muteti_audit_log')->fields([
      'user_id' => (int) $account->id(),
      'username' => $account->getAccountName(),
      'department' => $department,
      'appointment_date' => $date,
      'slot_type' => $slot,
      'patient_reference' => trim((string) $patientReference),
      'action' => $action,
      'created' => \Drupal::time()->getRequestTime(),
    ])->execute();
  }

}
