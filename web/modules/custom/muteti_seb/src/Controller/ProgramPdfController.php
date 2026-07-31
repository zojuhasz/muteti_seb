<?php

namespace Drupal\muteti_seb\Controller;

use Dompdf\Dompdf;
use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\muteti_seb\Service\Schedule;
use Drupal\muteti_seb\Service\DepartmentMode;
use Drupal\muteti_seb\Service\UserDepartment;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

final class ProgramPdfController extends ControllerBase {
  public function __construct(private readonly Connection $database) {}
  public static function create(ContainerInterface $c): static { return new static($c->get('database')); }

  public function oncologyAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf(DepartmentMode::get(UserDepartment::get($account)) === 'onko')
      ->addCacheContexts(['user.roles']);
  }

  public function oncologyBookingPdf(string $date): Response {
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
      return new Response('Érvénytelen dátum.', 400);
    }

    $department = UserDepartment::get($this->currentUser());
    $rows = $this->database->select('muteti_appointment', 'a')
      ->fields('a')
      ->condition('department', $department)
      ->condition('admission_date', $date)
      ->execute()
      ->fetchAll();
    $doctor_ids = array_values(array_unique(array_filter(array_map(static fn(object $row): ?int => $row->doctor_id ? (int) $row->doctor_id : NULL, $rows))));
    $doctors = $doctor_ids
      ? $this->database->select('muteti_doctor', 'd')->fields('d', ['id', 'name'])->condition('id', $doctor_ids, 'IN')->execute()->fetchAllKeyed()
      : [];

    $stored_type = $this->database->select('muteti_day_type', 'd')
      ->fields('d', ['day_type'])
      ->condition('department', $department)
      ->condition('date', $date)
      ->execute()
      ->fetchField();
    $day_type = $stored_type ?: Schedule::departmentDayType($department, $parsed);
    $ordered_slots = Schedule::departmentSlots($department, $parsed, $day_type);
    $by_slot = [];
    foreach ($rows as $row) {
      $by_slot[$row->slot_type] = $row;
      if (!in_array($row->slot_type, $ordered_slots, TRUE)) {
        $ordered_slots[] = $row->slot_type;
      }
    }
    $groups = [];
    foreach ($ordered_slots as $slot) {
      $group = preg_replace('/\s+-\s+\d+$/u', '', (string) $slot) ?: (string) $slot;
      $groups[$group][] = $by_slot[$slot] ?? NULL;
    }

    $escape = static fn(?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $days = [1 => 'HÉTFŐ', 'KEDD', 'SZERDA', 'CSÜTÖRTÖK', 'PÉNTEK', 'SZOMBAT', 'VASÁRNAP'];
    $html = '<meta charset="utf-8"><style>
      @page{margin:24mm 14mm 18mm}body{font-family:DejaVu Sans,sans-serif;color:#111;font-size:9px;margin:0}
      .brand{border-bottom:1.5px solid #111;padding:0 0 8px;font-size:16px;font-weight:700;letter-spacing:.5px}.brand small{display:block;font-size:11px;font-weight:400}
      h1{margin:30px 0 22px;font-size:18px}.group{margin:14px 0 2px;color:#c7c7c7;font-size:27px;font-weight:700;line-height:1;page-break-after:avoid}
      table{width:100%;border-collapse:collapse;table-layout:fixed}tr{page-break-inside:avoid}tr:nth-child(odd){background:#dce8fa}td{padding:2px 4px;vertical-align:top;line-height:1.15}
      .patient{width:36%;font-weight:700}.treatment{width:46%;font-style:italic}.notes{width:18%;font-weight:700}.footer{margin-top:24px;text-align:right;font-size:8px;color:#c2c8ce}
    </style><div class="brand">Uzsoki Utcai Kórház<small>Onkotherápia</small></div>';
    $html .= '<h1>'.$escape($date).', '.$days[(int) $parsed->format('N')].'</h1>';
    foreach ($groups as $group => $group_rows) {
      $html .= '<div class="group">'.$escape($group).'</div><table>';
      foreach (array_filter($group_rows) as $row) {
        $identifier = trim((string) $row->taj);
        $patient = trim((string) $row->patient_name).($identifier !== '' ? ' /'.$identifier.'/' : '');
        $doctor = $doctors[$row->doctor_id] ?? '';
        $treatment = trim((string) $row->operation_name).($doctor !== '' ? ' /'.$doctor.'/' : '');
        $html .= '<tr><td class="patient">'.$escape($patient).'</td><td class="treatment">'.$escape($treatment).'</td><td class="notes">'.$escape($row->notes).'</td></tr>';
      }
      $html .= '</table>';
    }
    $html .= '<div class="footer">Nyomtatva: '.$escape($this->currentUser()->getAccountName()).' '.date('Y.m.d H:i').'</div>';

    $pdf = new Dompdf(['isRemoteEnabled' => FALSE]);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    return new Response($pdf->output(), 200, [
      'Content-Type' => 'application/pdf',
      'Content-Disposition' => 'inline; filename="onkologia-kezeles-'.$date.'.pdf"',
    ]);
  }

  public function oncologyReportMenu(): array {
    $groups = [
      [
        ['next_week', 'Következő hét'],
        ['this_week', 'E hét'],
        ['past_week', 'Múlt hét'],
      ],
      [
        ['next_month', 'Következő hónap'],
        ['this_month', 'E hónap'],
        ['past_month', 'Múlt hónap'],
      ],
      [
        ['this_year', 'Ezév'],
        ['past_year', 'Múlt év'],
      ],
    ];
    $build = [
      '#attached' => ['library' => ['muteti_seb/surgery_board']],
      '#cache' => ['max-age' => 0],
      'title' => [
        '#markup' => '<h2 class="muteti-panel-title">Lekérdezés</h2>',
      ],
      'groups' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-oncology-report-groups']],
      ],
    ];
    foreach ($groups as $group_index => $items) {
      $group = [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-oncology-report-group']],
      ];
      foreach ($items as [$period, $label]) {
        $report_url = Url::fromRoute('muteti_seb.oncology_report_pdf', ['period' => $period]);
        $link = Link::fromTextAndUrl($label, $report_url)->toRenderable();
        $link['#attributes'] = [
          'class' => ['muteti-oncology-report-link'],
          'target' => '_blank',
          'rel' => 'noopener',
          'title' => $label.' PDF',
        ];
        $group[$period] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['muteti-oncology-report-item']],
          'link' => $link,
          'icon' => [
            '#type' => 'link',
            '#title' => [
              '#theme' => 'image',
              '#uri' => base_path().'modules/custom/muteti_seb/images/pdf-icon.svg',
              '#alt' => 'PDF',
              '#attributes' => ['class' => ['muteti-oncology-report-icon']],
            ],
            '#url' => $report_url,
            '#attributes' => [
              'class' => ['muteti-oncology-report-icon-link'],
              'target' => '_blank',
              'rel' => 'noopener',
              'title' => $label.' PDF',
              'aria-label' => $label.' PDF',
            ],
          ],
        ];
      }
      $build['groups']['group_'.$group_index] = $group;
    }
    return $build;
  }

  public function oncologyReportPdf(string $period): Response {
    $range = $this->oncologyReportPeriod($period);
    if (!$range) {
      return new Response('Érvénytelen lekérdezési időszak.', 400);
    }
    [$label, $start, $end] = $range;
    $account = $this->currentUser();
    $department = UserDepartment::get($account);
    $is_boss = in_array('muteti_boss', $account->getRoles(), TRUE);

    $query = $this->database->select('muteti_appointment', 'a');
    $query->addField('a', 'operation_name', 'treatment_name');
    $query->addExpression('COUNT(a.id)', 'treatment_count');
    $query->condition('a.department', $department);
    $query->condition('a.admission_date', [$start, $end], 'BETWEEN');
    $query->condition('a.operation_name', '', '<>');
    $query->isNotNull('a.operation_name');
    if (!$is_boss) {
      $doctor_ids = $this->database->select('muteti_doctor', 'd')
        ->fields('d', ['id'])
        ->condition('user_id', (int) $account->id())
        ->condition('department', $department)
        ->execute()
        ->fetchCol();
      if ($doctor_ids) {
        $query->condition('a.doctor_id', array_map('intval', $doctor_ids), 'IN');
      }
      else {
        $query->condition('a.id', 0);
      }
    }
    $rows = $query
      ->groupBy('a.operation_name')
      ->orderBy('a.operation_name', 'ASC')
      ->execute()
      ->fetchAll();

    $escape = static fn(?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<meta charset="utf-8"><style>
      @page{margin:15mm 16mm 16mm}
      body{font-family:DejaVu Sans,sans-serif;color:#111;font-size:11px;margin:0}
      h1{font-size:18px;margin:0 0 2px}
      h2{font-size:13px;margin:0 0 2px}
      .range{margin:0 0 10px;color:#475569}
      table{width:100%;border-collapse:collapse;table-layout:fixed}
      thead{display:table-header-group}
      tr{page-break-inside:avoid}
      th,td{border:1px solid #9aa8b6;padding:5px 7px;line-height:1.2}
      th{background:#dce8f2;text-align:left}
      tbody tr:nth-child(even){background:#f4f7fa}
      .treatment{width:82%}.count{width:18%;text-align:right}
      .empty{text-align:center;padding:18px}
      .created{margin-top:7px;text-align:right;font-size:8px;color:#c2c8ce}
    </style>';
    $html .= '<h1>Kemoterápiás kezelések</h1>';
    $html .= '<div class="range">'.$escape((new \DateTimeImmutable($start))->format('Y.m.d')).' - '.$escape((new \DateTimeImmutable($end))->format('Y.m.d')).'</div>';
    $html .= '<table><thead><tr><th class="treatment">Kezelés</th><th class="count">Darabszám</th></tr></thead><tbody>';
    if (!$rows) {
      $html .= '<tr><td class="empty" colspan="2">Ebben az időszakban nincs megjeleníthető kezelés.</td></tr>';
    }
    else {
      foreach ($rows as $row) {
        $html .= '<tr>';
        $html .= '<td>'.$escape($row->treatment_name).'</td>';
        $html .= '<td class="count"><strong>'.(int) $row->treatment_count.'</strong></td>';
        $html .= '</tr>';
      }
    }
    $html .= '</tbody></table>';
    $html .= '<div class="created">Készült: <strong>'.$escape(date('Y.m.d H:i')).'</strong></div>';

    $pdf = new Dompdf(['isRemoteEnabled' => FALSE]);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    return new Response($pdf->output(), 200, [
      'Content-Type' => 'application/pdf',
      'Content-Disposition' => 'inline; filename="onkologia-lekerdezes-'.$period.'.pdf"',
      'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
      'Pragma' => 'no-cache',
      'Expires' => '0',
    ]);
  }

  /**
   * @return array{0: string, 1: string, 2: string}|null
   */
  private function oncologyReportPeriod(string $period): ?array {
    $today = new \DateTimeImmutable('today');
    return match ($period) {
      'next_week' => [
        'Következő hét',
        $today->modify('monday next week')->format('Y-m-d'),
        $today->modify('sunday next week')->format('Y-m-d'),
      ],
      'this_week' => [
        'E hét',
        $today->modify('monday this week')->format('Y-m-d'),
        $today->modify('sunday this week')->format('Y-m-d'),
      ],
      'past_week' => [
        'Múlt hét',
        $today->modify('monday last week')->format('Y-m-d'),
        $today->modify('sunday last week')->format('Y-m-d'),
      ],
      'next_month' => [
        'Következő hónap',
        $today->modify('first day of next month')->format('Y-m-d'),
        $today->modify('last day of next month')->format('Y-m-d'),
      ],
      'this_month' => [
        'E hónap',
        $today->modify('first day of this month')->format('Y-m-d'),
        $today->modify('last day of this month')->format('Y-m-d'),
      ],
      'past_month' => [
        'Múlt hónap',
        $today->modify('first day of last month')->format('Y-m-d'),
        $today->modify('last day of last month')->format('Y-m-d'),
      ],
      'this_year' => [
        'Ezév',
        $today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d'),
        $today->setDate((int) $today->format('Y'), 12, 31)->format('Y-m-d'),
      ],
      'past_year' => [
        'Múlt év',
        $today->setDate((int) $today->format('Y') - 1, 1, 1)->format('Y-m-d'),
        $today->setDate((int) $today->format('Y') - 1, 12, 31)->format('Y-m-d'),
      ],
      default => NULL,
    };
  }

  public function pdf(string $date): Response {
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
      return new Response('Érvénytelen dátum.', 400);
    }

    $department = UserDepartment::get($this->currentUser());
    $mode = DepartmentMode::get($department);
    $rows = $this->database->select('muteti_appointment', 'a')
      ->fields('a')
      ->condition('department', $department)
      ->condition('surgery_date', $date)
      ->orderBy('operating_room')
      ->orderBy('surgery_order')
      ->execute()
      ->fetchAll();
    $doctor_ids = [];
    foreach ($rows as $appointment) {
      foreach (['doctor_id', 'assistant1_id', 'assistant2_id', 'assistant3_id'] as $field) {
        if ($appointment->{$field}) {
          $doctor_ids[] = (int) $appointment->{$field};
        }
      }
    }
    $doctors = $doctor_ids
      ? $this->database->select('muteti_doctor', 'd')
        ->fields('d', ['id', 'name'])
        ->condition('id', array_values(array_unique($doctor_ids)), 'IN')
        ->execute()
        ->fetchAllKeyed()
      : [];

    if ($mode === 'urol') {
      $html = $this->urologyProgramHtml($department, $date, $parsed, $rows, $doctors);
    }
    else {
      $escape = static fn(?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $on_call = $this->database->select('muteti_on_call', 'u')
        ->fields('u', ['doctor_name', 'doctor_name_2'])
        ->condition('mode', $mode)
        ->condition('date', $date)
        ->execute()
        ->fetchObject();
      $on_call_names = array_values(array_filter(array_unique([
        trim((string) ($on_call->doctor_name ?? ''), " \t\n\r\0\x0B,;"),
        trim((string) ($on_call->doctor_name_2 ?? ''), " \t\n\r\0\x0B,;"),
      ])));
      $weekdays = [1 => 'Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek', 'Szombat', 'Vasárnap'];
      $html = '<meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px}h1{margin:0;font-size:14px;line-height:1.05;font-weight:700}h2{margin:1px 0 2px;color:#aaa;font-size:24px;line-height:1.05;font-weight:700}.on-call{margin:0;font-size:10px;font-weight:700}.room{font-size:24px;color:#777;margin-top:7px}.patient{padding:6px}.patient:nth-child(even){background:#dce8fa}.diag{float:right;width:38%;font-weight:bold}.daily-summary{margin-top:18px;padding-top:8px;border-top:1px solid #777;page-break-inside:avoid}.daily-summary div{margin:2px 0}.daily-summary-start{margin-top:5px!important}.created{margin-top:2px;text-align:right;white-space:nowrap;color:#c2c8ce}.created strong{font-size:12px}</style><h1>'.$escape($department).'</h1>';
      $html .= '<h2>'.$escape($parsed->format('Y.m.d')).' '.$escape($weekdays[(int) $parsed->format('N')]).'</h2>';
      $html .= '<div class="on-call">Ügyelet: '.$escape($on_call_names ? implode(', ', $on_call_names) : '-').'</div>';
      $current = NULL;
      foreach ($rows as $appointment) {
        if ($current !== $appointment->operating_room) {
          $current = $appointment->operating_room;
          $html .= '<div class="room">'.$escape($current).'</div><small>MŰTŐ 08:30</small>';
        }
        $assistants = [];
        foreach (['assistant1_id', 'assistant2_id', 'assistant3_id'] as $field) {
          if ($appointment->{$field} && isset($doctors[$appointment->{$field}])) {
            $assistants[] = $doctors[$appointment->{$field}];
          }
        }
        $html .= '<div class="patient"><div class="diag">Dg.: '.$escape($appointment->diagnosis).'</div><strong>('.$appointment->surgery_order.') '.$escape($appointment->patient_name).'</strong><br>Op.: '.$escape($appointment->operation_name).'<br><strong>'.$escape($doctors[$appointment->doctor_id] ?? '-').($assistants ? ', '.$escape(implode(', ', $assistants)) : '').'</strong></div>';
      }

      $daily_info = $this->database->select('muteti_daily_info', 'i')
        ->fields('i')
        ->condition('department', $department)
        ->condition('date', $date)
        ->execute()
        ->fetchObject();
      $previous_date = $parsed->modify('-1 day')->format('Y-m-d');
      $previous_on_call = $this->database->select('muteti_on_call', 'u')
        ->fields('u', ['doctor_name', 'doctor_name_2'])
        ->condition('mode', $mode)
        ->condition('date', $previous_date)
        ->execute()
        ->fetchObject();
      $status_names = function (string $status) use ($date, $department): string {
        $query = $this->database->select('muteti_doctor_availability', 'a');
        $query->join('muteti_doctor', 'd', 'd.user_id = a.user_id');
        return implode(', ', $query->fields('d', ['name'])
          ->condition('a.date', $date)
          ->condition('a.status', $status)
          ->condition('d.department', $department)
          ->condition('d.active', 1)
          ->orderBy('d.name')
          ->execute()
          ->fetchCol());
      };
      $absent = DepartmentMode::featureEnabled($department, 'availability_enabled')
        ? $status_names('absent')
        : trim((string) ($daily_info->other_absent ?? ''));
      $free = array_filter([
        trim((string) ($previous_on_call->doctor_name ?? '')),
        trim((string) ($previous_on_call->doctor_name_2 ?? '')),
      ]);
      $acute = array_filter([
        trim((string) ($daily_info->acute_1 ?? '')),
        trim((string) ($daily_info->acute_2 ?? '')),
      ]);
      $summary = [
        'Aznapi műtét felelős' => trim((string) ($daily_info->responsible ?? '')),
        'Akut felelős' => implode(', ', $acute),
        'Ambulancia' => trim((string) ($daily_info->ambulance ?? '')),
        'Szabadnap' => implode(', ', $free),
        'Egyéb távollevők' => $absent,
      ];
      $start_time = trim((string) ($daily_info->start_time ?? '')) ?: '08:30';
      $html .= '<div class="daily-summary">';
      foreach ($summary as $label => $value) {
        $html .= '<div><strong>'.$escape($label).':</strong> '.($value !== '' ? $escape($value) : '&ndash;').'</div>';
      }
      $html .= '<div class="daily-summary-start"><strong>Műtétek kezdete:</strong> '.$escape($start_time).'</div>';
      $html .= '<div class="created">Készült: &nbsp;<strong>'.$escape(date('Y.m.d H:i')).'</strong></div></div>';
    }

    $pdf = new Dompdf(['isRemoteEnabled' => FALSE]);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    return new Response($pdf->output(), 200, [
      'Content-Type' => 'application/pdf',
      'Content-Disposition' => 'inline; filename="muteti-program-'.$date.'.pdf"',
      'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
      'Pragma' => 'no-cache',
      'Expires' => '0',
    ]);
  }

  /**
   * Builds the compact, operating-room based Urology program.
   *
   * @param array<int, object> $rows
   * @param array<int, string> $doctors
   */
  private function urologyProgramHtml(string $department, string $date, \DateTimeImmutable $parsed, array $rows, array $doctors): string {
    $escape = static fn(?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $weekdays = [1 => 'Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek', 'Szombat', 'Vasárnap'];
    $on_call = $this->database->select('muteti_on_call', 'u')
      ->fields('u', ['doctor_name', 'doctor_name_2'])
      ->condition('mode', 'urol')
      ->condition('date', $date)
      ->execute()
      ->fetchObject();
    $daily_info = $this->database->select('muteti_daily_info', 'i')
      ->fields('i')
      ->condition('department', $department)
      ->condition('date', $date)
      ->execute()
      ->fetchObject();
    $start_time = trim((string) ($daily_info->start_time ?? '')) ?: '08:00';
    $previous_date = $parsed->modify('-1 day')->format('Y-m-d');
    $previous_on_call = $this->database->select('muteti_on_call', 'u')
      ->fields('u', ['doctor_name'])
      ->condition('mode', 'urol')
      ->condition('date', $previous_date)
      ->execute()
      ->fetchObject();
    $status_names = function (string $status) use ($date, $department): string {
      $query = $this->database->select('muteti_doctor_availability', 'a');
      $query->join('muteti_doctor', 'd', 'd.user_id = a.user_id');
      return implode(', ', $query->fields('d', ['name'])
        ->condition('a.date', $date)
        ->condition('a.status', $status)
        ->condition('d.department', $department)
        ->condition('d.active', 1)
        ->orderBy('d.name')
        ->execute()
        ->fetchCol());
    };
    $absent = DepartmentMode::featureEnabled($department, 'availability_enabled')
      ? $status_names('absent')
      : trim((string) ($daily_info->other_absent ?? ''));
    $away = DepartmentMode::featureEnabled($department, 'away_enabled')
      ? $status_names('away')
      : '';
    $summary = [
      'Akut betegek' => trim((string) ($daily_info->acute_1 ?? '')) ?: trim((string) ($on_call->doctor_name ?? '')),
      'Szabadnap' => trim((string) ($previous_on_call->doctor_name ?? '')),
      'Egyéb távollévők' => $absent,
      'Telefonos' => trim((string) ($on_call->doctor_name_2 ?? '')),
      'Idegen intézményben' => $away,
    ];

    $rooms = [];
    foreach ($rows as $appointment) {
      $room = trim((string) $appointment->operating_room);
      if ($room !== '' && $room !== '0') {
        $rooms[$room][] = $appointment;
      }
    }
    uksort($rooms, static fn(string $left, string $right): int => strnatcasecmp($left, $right));

    $on_call_names = array_values(array_filter(array_unique([
      trim((string) ($on_call->doctor_name ?? ''), " \t\n\r\0\x0B,;"),
      trim((string) ($on_call->doctor_name_2 ?? ''), " \t\n\r\0\x0B,;"),
    ])));
    $html = '<meta charset="utf-8"><style>
      @page{margin:11mm 9mm 12mm}
      body{font-family:DejaVu Sans,sans-serif;color:#000;font-size:11px;margin:0}
      h1{font-size:14px;line-height:1.05;margin:0;font-weight:700}
      h2{font-size:24px;line-height:1.05;margin:1px 0 2px;color:#aaa;font-weight:700}
      .on-call{font-size:10px;font-weight:700;margin:0 0 8px}
      .room{margin:0 0 10mm;page-break-inside:avoid}
      .room-title{border:1px solid #111;border-bottom:0;font-size:15px;padding:3px 5px;font-weight:400}
      table{border-collapse:collapse;width:100%;table-layout:fixed}
      thead{display:table-header-group}
      tr{page-break-inside:avoid}
      th,td{border:1px solid #111;padding:2px 3px;vertical-align:top;line-height:1.18;overflow-wrap:anywhere}
      th{text-align:left;font-weight:700}
      .order{width:3%;text-align:center;padding-left:1px;padding-right:1px}
      .patient{width:17%}.diagnosis{width:17%}.operation{width:17%}
      .anaesth{width:11%}.operator{width:17%}.assistants{width:18%}
      .empty{padding:8px;text-align:center}
      .summary{margin-top:6mm;background:#fff;font-size:11px;line-height:1.2;page-break-inside:avoid}
      .summary-row{display:block;white-space:nowrap}
      .summary-label{display:inline-block;width:21%;font-weight:700}
      .summary-value{display:inline-block;width:57%;white-space:normal;vertical-align:top}
      .created{margin-top:2px;text-align:right;white-space:nowrap;color:#c2c8ce}
      .created strong{font-size:12px}
    </style>';
    $html .= '<h1>'.$escape($department).'</h1>';
    $html .= '<h2>'.$escape($parsed->format('Y.m.d')).' '.$weekdays[(int) $parsed->format('N')].'</h2>';
    $html .= '<div class="on-call">Ügyelet: '.$escape($on_call_names ? implode(', ', $on_call_names) : '-').'</div>';
    if (!$rooms) {
      $html .= '<div class="empty">Erre a napra nincs műtőbe beosztott beteg.</div>';
    }
    else {
      foreach ($rooms as $room => $appointments) {
        $html .= '<section class="room">';
        $html .= '<div class="room-title">'.$escape($room).'. MŰTŐ - kezdés '.$escape($start_time).'</div>';
        $html .= '<table><thead><tr>';
        $html .= '<th class="order"></th><th class="patient">Beteg</th><th class="diagnosis">Dg.</th><th class="operation">Műtét</th><th class="anaesth">Anaesth.</th><th class="operator">Operál</th><th class="assistants">Asszisztál</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($appointments as $index => $appointment) {
          $assistants = [];
          foreach (['assistant1_id', 'assistant2_id', 'assistant3_id'] as $field) {
            if ($appointment->{$field} && isset($doctors[$appointment->{$field}])) {
              $assistant = trim((string) $doctors[$appointment->{$field}]);
              if ($assistant !== '' && $assistant !== '-') {
                $assistants[] = $assistant;
              }
            }
          }
          $assistants = array_values(array_unique($assistants));
          $order = (int) $appointment->surgery_order > 0 ? (int) $appointment->surgery_order : $index + 1;
          $patient = $escape($appointment->patient_name);
          if (trim((string) $appointment->taj) !== '') {
            $patient .= '<br>TAJ:'.$escape($appointment->taj);
          }
          $html .= '<tr>';
          $html .= '<td class="order">'.$order.'</td>';
          $html .= '<td class="patient">'.$patient.'</td>';
          $html .= '<td class="diagnosis">'.$escape($appointment->diagnosis).'</td>';
          $html .= '<td class="operation">'.$escape($appointment->operation_name).'</td>';
          $html .= '<td class="anaesth">'.$escape($appointment->anaesth).'</td>';
          $html .= '<td class="operator">'.$escape($doctors[$appointment->doctor_id] ?? '-').'</td>';
          $html .= '<td class="assistants">'.$escape(implode(', ', $assistants)).'</td>';
          $html .= '</tr>';
        }
        $html .= '</tbody></table></section>';
      }
    }
    $html .= '<div class="summary">';
    foreach ($summary as $label => $value) {
      $html .= '<div class="summary-row"><span class="summary-label">'.$escape($label).':</span><span class="summary-value">'.$escape($value ?: '-').'</span></div>';
    }
    $html .= '<div class="created">Készült: &nbsp;<strong>'.$escape(date('Y.m.d H:i')).'</strong></div></div>';
    return $html;
  }
}
