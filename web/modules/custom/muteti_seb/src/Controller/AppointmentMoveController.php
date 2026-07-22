<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\muteti_seb\Service\UserDepartment;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AppointmentMoveController extends ControllerBase {

  public function __construct(private readonly Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function move(Request $request): JsonResponse {
    if (!in_array('muteti_boss', $this->currentUser()->getRoles(), TRUE)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Az áthelyezéshez Boss jogosultság szükséges.'], 403);
    }
    $data = json_decode($request->getContent(), TRUE);
    $appointment_id = (int) ($data['appointment_id'] ?? 0);
    $date = (string) ($data['date'] ?? '');
    $slot = trim((string) ($data['slot'] ?? ''));
    $mode = (string) ($data['mode'] ?? 'move');
    $department = UserDepartment::get($this->currentUser());

    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$appointment_id || !$parsed || $parsed->format('Y-m-d') !== $date || $slot === '' || mb_strlen($slot) > 20 || !in_array($mode, ['move', 'duplicate'], TRUE)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Érvénytelen célhely.'], 400);
    }

    $source = $this->database->select('muteti_appointment', 'a')
      ->fields('a')
      ->condition('id', $appointment_id)
      ->condition('department', $department)
      ->execute()
      ->fetchObject();
    if (!$source) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'A beteg nem található ezen az osztályon.'], 404);
    }
    if ($source->admission_date === $date && $source->slot_type === $slot) {
      return new JsonResponse(['ok' => TRUE]);
    }

    $occupied = (bool) $this->database->select('muteti_appointment', 'a')
      ->condition('department', $department)
      ->condition('admission_date', $date)
      ->condition('slot_type', $slot)
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($occupied) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'A kiválasztott célhely időközben foglalttá vált.'], 409);
    }

    try {
      $destination_fields = [
          'admission_date' => $date,
          'slot_type' => $slot,
          'surgery_date' => NULL,
          'operating_room' => NULL,
          'surgery_order' => 0,
          'operated' => 0,
          'changed' => \Drupal::time()->getRequestTime(),
      ];
      if ($mode === 'duplicate') {
        $duplicate = get_object_vars($source);
        unset($duplicate['id']);
        $duplicate['legacy_id'] = NULL;
        $duplicate['created_by'] = (int) $this->currentUser()->id();
        $duplicate['created'] = \Drupal::time()->getRequestTime();
        $duplicate = array_merge($duplicate, $destination_fields);
        $this->database->insert('muteti_appointment')->fields($duplicate)->execute();
      }
      else {
        $this->database->update('muteti_appointment')
          ->fields($destination_fields)
          ->condition('id', $appointment_id)
          ->condition('department', $department)
          ->execute();
      }
    }
    catch (\Throwable) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'A célhely már foglalt, az áthelyezés nem történt meg.'], 409);
    }

    return new JsonResponse(['ok' => TRUE, 'mode' => $mode, 'date' => $date, 'slot' => $slot]);
  }

  public function delete(Request $request): JsonResponse {
    if (!in_array('muteti_boss', $this->currentUser()->getRoles(), TRUE)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'A törléshez Boss jogosultság szükséges.'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);
    $appointment_id = (int) ($data['appointment_id'] ?? 0);
    if (!$appointment_id) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Érvénytelen betegazonosító.'], 400);
    }

    $deleted = $this->database->delete('muteti_appointment')
      ->condition('id', $appointment_id)
      ->condition('department', UserDepartment::get($this->currentUser()))
      ->execute();
    if (!$deleted) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'A beteg nem található ezen az osztályon.'], 404);
    }

    return new JsonResponse(['ok' => TRUE]);
  }

}
