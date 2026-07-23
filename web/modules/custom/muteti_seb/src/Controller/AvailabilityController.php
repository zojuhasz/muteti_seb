<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\muteti_seb\Service\Schedule;
use Drupal\muteti_seb\Service\UserDepartment;
use Drupal\user\Entity\User;
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
        ->fields(['status' => 'absent', 'source' => 'manual', 'legacy_id' => NULL, 'changed' => time()])
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
    $stored_day_types = $this->database->select('muteti_day_type', 'd')
      ->fields('d', ['date', 'day_type'])
      ->condition('department', $department)
      ->condition('date', [$start, $end], 'BETWEEN')
      ->execute()
      ->fetchAllKeyed();

    $doctor_rows = $this->database->select('muteti_doctor', 'd')
      ->fields('d', ['user_id', 'name', 'background_color', 'text_color'])
      ->condition('department', $department)
      ->condition('active', 1)
      ->isNotNull('user_id')
      ->orderBy('name')
      ->execute();
    $doctor_names = [];
    $doctor_styles = [];
    foreach ($doctor_rows as $doctor) {
      $user_id = (int) $doctor->user_id;
      $doctor_names[$user_id] ??= (string) $doctor->name;
      if (!isset($doctor_styles[$user_id])) {
        $has_background = trim((string) $doctor->background_color) !== '';
        $background = $has_background ? (string) $doctor->background_color : '#eef2f6';
        $text = $has_background ? ((string) $doctor->text_color ?: '#111111') : '#111111';
        $doctor_styles[$user_id] = 'background-color:'.$background.';color:'.$text.';';
      }
    }

    $rows = [];
    $absences = $this->database->select('muteti_doctor_availability', 'a')
      ->fields('a', ['user_id', 'date'])
      ->condition('date', [$start, $end], 'BETWEEN')
      ->condition('status', 'absent')
      ->orderBy('date')
      ->execute();
    foreach ($absences as $absence) {
      $user_id = (int) $absence->user_id;
      $account = User::load($user_id);
      if (!$account || !$account->isActive() || !$account->hasPermission('manage own doctor availability')) {
        continue;
      }
      if (UserDepartment::get($account) !== $department) {
        continue;
      }
      $day = new DrupalDateTime($absence->date);
      $doctor_name = $doctor_names[$user_id] ?? $account->getDisplayName();
      $day_type = $stored_day_types[$absence->date] ?? Schedule::departmentDayType($department, $day);
      $rows[] = [
        Html::escape($absence->date),
        Html::escape((string) $this->t($day->format('l'))),
        Html::escape($day_type),
        [
          'data' => [
            '#markup' => '<span class="muteti-availability-doctor" style="'.Html::escape($doctor_styles[$user_id] ?? 'background-color:#eef2f6;color:#111111;').'">'.Html::escape($doctor_name).'</span>',
          ],
        ],
      ];
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
          '#header' => [$this->t('Dátum'), $this->t('Nap'), $this->t('Naptípus'), $this->t('Orvos')],
          '#rows' => $rows,
          '#empty' => $this->t('Ebben a hónapban nincs rögzített távollét.'),
        ],
      ],
    ];
  }

}
