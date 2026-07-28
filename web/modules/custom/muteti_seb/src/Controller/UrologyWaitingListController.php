<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\muteti_seb\Service\AuditLog;
use Drupal\muteti_seb\Service\DepartmentMode;
use Drupal\muteti_seb\Service\UserDepartment;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class UrologyWaitingListController extends ControllerBase {

  public function __construct(private readonly Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function update(Request $request): JsonResponse {
    $department = UserDepartment::get($this->currentUser());
    if (DepartmentMode::get($department) !== 'urol') {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Ez a funkció csak urológiai működési módban használható.'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);
    $appointment_id = (int) ($data['appointment_id'] ?? 0);
    $action = (string) ($data['action'] ?? '');
    $date = (string) ($data['date'] ?? '');
    if (!$appointment_id || !in_array($action, ['schedule', 'revoke'], TRUE)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Érvénytelen várólista-művelet.'], 400);
    }

    $appointment = $this->database->select('muteti_appointment', 'a')
      ->fields('a', ['id', 'admission_date', 'slot_type', 'patient_name', 'ward_room', 'taj', 'surgery_date', 'operating_room', 'operated'])
      ->condition('id', $appointment_id)
      ->condition('department', $department)
      ->execute()
      ->fetchObject();
    if (!$appointment) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'A beteg nem található ezen az osztályon.'], 404);
    }
    if ((int) $appointment->operated === 1) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'A már megoperált beteg várólistája nem módosítható.'], 409);
    }
    if (trim((string) $appointment->operating_room) !== '') {
      return new JsonResponse(['ok' => FALSE, 'error' => 'A beteg már műtőbe van osztva. Előbb helyezd vissza a várólistára.'], 409);
    }

    $patient_reference = (string) ($appointment->ward_room ?: $appointment->taj);
    if ($action === 'schedule') {
      $allowed_dates = [];
      try {
        $waiting_list_start = new \DateTimeImmutable((string) $appointment->admission_date);
      }
      catch (\Exception) {
        return new JsonResponse(['ok' => FALSE, 'error' => 'A beteg befekvési dátuma érvénytelen.'], 400);
      }
      for ($offset = 0; $offset < 4; $offset++) {
        $allowed_dates[] = $waiting_list_start->modify('+'.$offset.' day')->format('Y-m-d');
      }
      if (!in_array($date, $allowed_dates, TRUE)) {
        return new JsonResponse(['ok' => FALSE, 'error' => 'Csak a befekvés napja vagy az azt követő három nap választható.'], 400);
      }
      $this->database->update('muteti_appointment')
        ->fields([
          'surgery_date' => $date,
          'operating_room' => NULL,
          'surgery_order' => 0,
          'changed' => \Drupal::time()->getRequestTime(),
        ])
        ->condition('id', $appointment_id)
        ->condition('department', $department)
        ->execute();
      AuditLog::write('műtéti várólista felvesz '.$date, $department, $appointment->admission_date, $appointment->slot_type, $patient_reference);
      return new JsonResponse(['ok' => TRUE, 'action' => 'schedule', 'date' => $date]);
    }

    $old_date = trim((string) $appointment->surgery_date);
    $this->database->update('muteti_appointment')
      ->fields([
        'surgery_date' => NULL,
        'operating_room' => NULL,
        'surgery_order' => 0,
        'changed' => \Drupal::time()->getRequestTime(),
      ])
      ->condition('id', $appointment_id)
      ->condition('department', $department)
      ->execute();
    AuditLog::write('műtéti várólista visszavonás'.($old_date !== '' ? ' '.$old_date : ''), $department, $appointment->admission_date, $appointment->slot_type, $patient_reference);

    return new JsonResponse(['ok' => TRUE, 'action' => 'revoke']);
  }

}
