<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\muteti_seb\Service\Schedule;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class DayTypeController extends ControllerBase {

  public function __construct(private readonly Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function update(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    $date = (string) ($data['date'] ?? '');
    $day_type = (string) ($data['day_type'] ?? '');

    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Érvénytelen dátum.'], 400);
    }
    if (!array_key_exists($day_type, Schedule::DAY_TYPES)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Érvénytelen napfajta.'], 400);
    }

    $occupied = (bool) $this->database->select('muteti_appointment', 'a')
      ->condition('surgery_date', $date)
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($occupied) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'A napfajta már nem módosítható, mert van műtőbe beosztott beteg.'], 409);
    }

    $this->database->merge('muteti_day_type')
      ->key('date', $date)
      ->fields(['day_type' => $day_type])
      ->execute();

    return new JsonResponse(['ok' => TRUE, 'day_type' => $day_type]);
  }

}
