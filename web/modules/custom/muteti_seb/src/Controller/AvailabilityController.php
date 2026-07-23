<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\muteti_seb\Service\UserDepartment;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AvailabilityController extends ControllerBase {

  public function __construct(private readonly Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function update(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    $date = (string) ($data['date'] ?? '');
    $status = (string) ($data['status'] ?? '');
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date || !in_array($status, ['work', 'absent'], TRUE)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Érvénytelen dátum vagy állapot.'], 400);
    }
    $user_id = (int) $this->currentUser()->id();
    if ($status === 'work') {
      $this->database->delete('muteti_doctor_availability')
        ->condition('user_id', $user_id)
        ->condition('date', $date)
        ->execute();
    }
    else {
      $this->database->merge('muteti_doctor_availability')
        ->key('user_id', $user_id)
        ->key('date', $date)
        ->fields(['status' => 'absent', 'changed' => time()])
        ->execute();
    }
    return new JsonResponse(['ok' => TRUE, 'date' => $date, 'status' => $status]);
  }

  public function month(Request $request): array {
    $month_value = (string) $request->query->get('month', 'first day of this month');
    try {
      $month = new DrupalDateTime($month_value);
    }
    catch (\Exception) {
      $month = new DrupalDateTime('first day of this month');
    }
    $month->modify('first day of this month');
    $start = $month->format('Y-m-01');
    $end = $month->format('Y-m-t');
    $department = UserDepartment::get($this->currentUser());

    $doctor_rows = $this->database->select('muteti_doctor', 'd')
      ->fields('d', ['user_id', 'name'])
      ->condition('department', $department)
      ->condition('active', 1)
      ->isNotNull('user_id')
      ->orderBy('name')
      ->execute();
    $doctor_names = [];
    foreach ($doctor_rows as $doctor) {
      $doctor_names[(int) $doctor->user_id] ??= (string) $doctor->name;
    }

    $rows = [];
    if ($doctor_names) {
      $absences = $this->database->select('muteti_doctor_availability', 'a')
        ->fields('a', ['user_id', 'date'])
        ->condition('user_id', array_keys($doctor_names), 'IN')
        ->condition('date', [$start, $end], 'BETWEEN')
        ->condition('status', 'absent')
        ->orderBy('date')
        ->execute();
      foreach ($absences as $absence) {
        $day = new DrupalDateTime($absence->date);
        $rows[] = [
          Html::escape($absence->date),
          Html::escape((string) $this->t($day->format('l'))),
          Html::escape($doctor_names[(int) $absence->user_id] ?? '—'),
          $this->t('Távollét'),
        ];
      }
    }

    $previous = (clone $month)->modify('-1 month')->format('Y-m-01');
    $next = (clone $month)->modify('+1 month')->format('Y-m-01');
    return [
      '#attached' => ['library' => ['muteti_seb/surgery_board']],
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2 class="muteti-panel-title">'.Html::escape($department).' – '.$month->format('Y. m.').' havi távollétek</h2>'],
      'nav' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-nav']],
        'previous' => Link::fromTextAndUrl('← Előző hónap', Url::fromRoute('muteti_seb.availability_month', [], ['query' => ['month' => $previous]]))->toRenderable(),
        'current' => Link::fromTextAndUrl('Aktuális hónap', Url::fromRoute('muteti_seb.availability_month'))->toRenderable(),
        'next' => Link::fromTextAndUrl('Következő hónap →', Url::fromRoute('muteti_seb.availability_month', [], ['query' => ['month' => $next]]))->toRenderable(),
      ],
      'frame' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-table-frame']],
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Dátum'), $this->t('Nap'), $this->t('Orvos'), $this->t('Állapot')],
          '#rows' => $rows,
          '#empty' => $this->t('Ebben a hónapban nincs rögzített távollét.'),
        ],
      ],
    ];
  }

}
