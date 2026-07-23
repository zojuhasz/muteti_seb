<?php

namespace Drupal\muteti_seb\Controller;

use Dompdf\Dompdf;
use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\muteti_seb\Service\Schedule;
use Drupal\muteti_seb\Service\DepartmentMode;
use Drupal\muteti_seb\Service\UserDepartment;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AvailabilityController extends ControllerBase {

  public function __construct(private readonly Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function availabilityAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf(
      DepartmentMode::featureEnabled(UserDepartment::get($account), 'availability_enabled')
    )->addCacheContexts(['user']);
  }

  public function awayAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf(
      DepartmentMode::featureEnabled(UserDepartment::get($account), 'away_enabled')
    )->addCacheContexts(['user']);
  }

  public function update(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    $date = (string) ($data['date'] ?? '');
    $status = (string) ($data['status'] ?? '');
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date || !in_array($status, ['work', 'absent', 'away'], TRUE)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Érvénytelen dátum vagy állapot.'], 400);
    }
    $department = UserDepartment::get($this->currentUser());
    if (($status === 'absent' && !DepartmentMode::featureEnabled($department, 'availability_enabled'))
      || ($status === 'away' && !DepartmentMode::featureEnabled($department, 'away_enabled'))) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Ez a funkció ennél az osztálynál nincs engedélyezve.'], 403);
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
        ->fields(['status' => $status, 'source' => 'manual', 'legacy_id' => NULL, 'changed' => time()])
        ->execute();
    }
    return new JsonResponse(['ok' => TRUE, 'date' => $date, 'status' => $status]);
  }

  public function month(Request $request): array {
    return $this->monthReport($request, 'absent');
  }

  public function awayMonth(Request $request): array {
    return $this->monthReport($request, 'away');
  }

  private function monthReport(Request $request, string $status): array {
    $is_away = $status === 'away';
    $month_route = $is_away ? 'muteti_seb.away_month' : 'muteti_seb.availability_month';
    $pdf_route = $is_away ? 'muteti_seb.away_pdf' : 'muteti_seb.availability_pdf';
    $page_label = $is_away ? 'idegenben' : 'távollétek';
    $empty_label = $is_away
      ? 'Ebben a hónapban nincs rögzített idegenben végzett munka.'
      : 'Ebben a hónapban nincs rögzített távollét.';
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

    $grouped_days = [];
    $absences = $this->database->select('muteti_doctor_availability', 'a')
      ->fields('a', ['user_id', 'date'])
      ->condition('date', [$start, $end], 'BETWEEN')
      ->condition('status', $status)
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
      $day_number = (int) $day->format('N');
      $grouped_days[$absence->date] ??= [
        'day' => [1 => 'H', 'K', 'S', 'C', 'P', 'S', 'V'][$day_number],
        'weekend' => $day_number >= 6,
        'day_type' => $day_type,
        'doctors' => [],
      ];
      $style = $doctor_styles[$user_id] ?? 'background-color:#eef2f6;color:#111111;';
      $grouped_days[$absence->date]['doctors'][$doctor_name] = [
        'name' => $doctor_name,
        'style' => $style,
      ];
    }
    $rows = [];
    foreach ($grouped_days as $date => $group) {
      ksort($group['doctors'], SORT_NATURAL | SORT_FLAG_CASE);
      $doctor_list = [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-availability-doctors']],
      ];
      foreach (array_values($group['doctors']) as $index => $doctor) {
        $doctor_list['doctor_'.$index] = [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => Html::escape($doctor['name']),
          '#attributes' => [
            'class' => ['muteti-availability-doctor-tag'],
            'style' => $doctor['style'],
          ],
        ];
      }
      $rows[] = [
        [
          'data' => Html::escape(substr($date, 5)),
          'class' => ['muteti-availability-date'],
        ],
        [
          'data' => Html::escape($group['day']),
          'class' => array_filter([
            'muteti-availability-day',
            $group['weekend'] ? 'is-weekend' : NULL,
          ]),
        ],
        [
          'data' => Html::escape($group['day_type']),
          'class' => ['muteti-availability-day-type'],
        ],
        ['data' => $doctor_list],
      ];
    }

    $previous = (clone $month)->modify('-1 month')->format('Y-m-01');
    $next = (clone $month)->modify('+1 month')->format('Y-m-01');
    return [
      '#attached' => ['library' => ['muteti_seb/surgery_board']],
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2 class="muteti-panel-title">'.Html::escape($department).' – '.$month->format('Y. m.').' havi '.Html::escape($page_label).'</h2>'],
      'nav' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-nav']],
        'previous' => Link::fromTextAndUrl('← Előző hónap', Url::fromRoute($month_route, [], ['query' => ['month' => $previous]]))->toRenderable(),
        'current' => Link::fromTextAndUrl('Aktuális hónap', Url::fromRoute($month_route))->toRenderable(),
        'next' => Link::fromTextAndUrl('Következő hónap →', Url::fromRoute($month_route, [], ['query' => ['month' => $next]]))->toRenderable(),
        'pdf' => [
          '#type' => 'link',
          '#title' => [
            '#theme' => 'image',
            '#uri' => base_path().'modules/custom/muteti_seb/images/pdf-icon.svg',
            '#alt' => 'PDF',
            '#attributes' => ['class' => ['muteti-program-pdf-icon']],
          ],
          '#url' => Url::fromRoute($pdf_route, ['month' => $month->format('Y-m')]),
          '#attributes' => [
            'class' => ['muteti-availability-pdf-link'],
            'title' => $is_away ? 'Havi idegenben lista PDF' : 'Havi szabadságlista PDF',
            'aria-label' => $is_away ? 'Havi idegenben lista PDF' : 'Havi szabadságlista PDF',
            'target' => '_blank',
          ],
        ],
      ],
      'frame' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-table-frame']],
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Dátum'), $this->t('Nap'), $this->t('Naptípus'), $this->t('Orvos')],
          '#rows' => $rows,
          '#empty' => $this->t($empty_label),
        ],
      ],
    ];
  }

  public function pdf(string $month): Response {
    return $this->pdfReport($month, 'absent');
  }

  public function awayPdf(string $month): Response {
    return $this->pdfReport($month, 'away');
  }

  private function pdfReport(string $month, string $status): Response {
    $is_away = $status === 'away';
    $report_label = $is_away ? 'idegenben' : 'szabadságok';
    $filename = $is_away ? 'idegenben' : 'szabadsagok';
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m', $month);
    if (!$parsed || $parsed->format('Y-m') !== $month) {
      return new Response('Érvénytelen hónap.', 400);
    }
    $start = $parsed->format('Y-m-01');
    $end = $parsed->format('Y-m-t');
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
    $doctors = [];
    foreach ($doctor_rows as $doctor) {
      $user_id = (int) $doctor->user_id;
      if (!isset($doctors[$user_id]) || trim((string) $doctor->background_color) !== '') {
        $doctors[$user_id] = $doctor;
      }
    }

    $escape = static fn(?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $days = [1 => 'H', 'K', 'S', 'C', 'P', 'S', 'V'];
    $html = '<meta charset="utf-8"><style>
      @page{margin:14mm}body{font-family:DejaVu Sans,sans-serif;color:#172b3a;font-size:10px}
      h1{margin:0 0 4px;font-size:18px}h2{margin:0 0 16px;color:#536b7d;font-size:13px}
      table{width:100%;border-collapse:collapse}th{padding:7px;background:#dce8f2;text-align:left}
      td{padding:6px;border-bottom:1px solid #d5dde5}.date{font-size:8px}.day{font-size:9px;font-weight:700}.weekend{color:#c62828}.day-type{font-size:8px;font-weight:700}.doctor{display:inline-block;margin:1px 2px;padding:3px 5px;border:1px solid #bbc5ce;border-radius:3px;font-size:8px;font-weight:700;line-height:1.1}.footer{margin-top:18px;text-align:right;color:#667;font-size:8px}
    </style><h1>'.$escape($department).' – '.$escape($report_label).'</h1><h2>'.$escape($month).'</h2><table><thead><tr><th>Dátum</th><th>Nap</th><th>Naptípus</th><th>Orvos</th></tr></thead><tbody>';

    $absences = $this->database->select('muteti_doctor_availability', 'a')
      ->fields('a', ['user_id', 'date'])
      ->condition('date', [$start, $end], 'BETWEEN')
      ->condition('status', $status)
      ->orderBy('date')
      ->execute();
    $pdf_days = [];
    foreach ($absences as $absence) {
      $user_id = (int) $absence->user_id;
      $account = User::load($user_id);
      if (!$account || !$account->isActive() || !$account->hasPermission('manage own doctor availability') || UserDepartment::get($account) !== $department) {
        continue;
      }
      $date = new DrupalDateTime($absence->date);
      $doctor = $doctors[$user_id] ?? NULL;
      $name = $doctor ? (string) $doctor->name : $account->getDisplayName();
      $background = $doctor && preg_match('/^#[0-9a-f]{3,6}$/i', (string) $doctor->background_color) ? (string) $doctor->background_color : '#eef2f6';
      $text = $doctor && preg_match('/^#[0-9a-f]{3,6}$/i', (string) $doctor->text_color) ? (string) $doctor->text_color : '#111111';
      $day_type = $stored_day_types[$absence->date] ?? Schedule::departmentDayType($department, $date);
      $pdf_days[$absence->date] ??= [
        'day' => $days[(int) $date->format('N')],
        'weekend' => (int) $date->format('N') >= 6,
        'day_type' => $day_type,
        'doctors' => [],
      ];
      $pdf_days[$absence->date]['doctors'][$name] = '<span class="doctor" style="background:'.$escape($background).';color:'.$escape($text).'">'.$escape($name).'</span>';
    }
    foreach ($pdf_days as $date => $group) {
      ksort($group['doctors'], SORT_NATURAL | SORT_FLAG_CASE);
      $day_class = $group['weekend'] ? 'day weekend' : 'day';
      $html .= '<tr><td class="date">'.$escape(substr($date, 5)).'</td><td class="'.$day_class.'">'.$escape($group['day']).'</td><td class="day-type">'.$escape($group['day_type']).'</td><td>'.implode('', $group['doctors']).'</td></tr>';
    }
    $html .= '</tbody></table><div class="footer">Nyomtatva: '.$escape($this->currentUser()->getAccountName()).' '.date('Y.m.d H:i').'</div>';

    $pdf = new Dompdf(['isRemoteEnabled' => FALSE]);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    return new Response($pdf->output(), 200, [
      'Content-Type' => 'application/pdf',
      'Content-Disposition' => 'inline; filename="'.$filename.'-'.$month.'.pdf"',
    ]);
  }

}
