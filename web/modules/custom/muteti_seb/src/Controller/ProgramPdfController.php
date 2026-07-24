<?php

namespace Drupal\muteti_seb\Controller;

use Dompdf\Dompdf;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
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
      .patient{width:36%;font-weight:700}.treatment{width:46%;font-style:italic}.notes{width:18%;font-weight:700}.footer{margin-top:24px;text-align:right;font-size:8px;color:#555}
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
      $html = '<meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px}h1{margin:0}.room{font-size:24px;color:#777;margin-top:18px}.patient{padding:6px}.patient:nth-child(even){background:#dce8fa}.diag{float:right;width:38%;font-weight:bold}</style><h1>'.$escape($department).'</h1><h2>'.$escape($date).'</h2>';
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
    $weekdays = [1 => 'HÉTFŐ', 'KEDD', 'SZERDA', 'CSÜTÖRTÖK', 'PÉNTEK', 'SZOMBAT', 'VASÁRNAP'];
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
      trim((string) ($on_call->doctor_name ?? '')),
      trim((string) ($on_call->doctor_name_2 ?? '')),
    ])));
    $html = '<meta charset="utf-8"><style>
      @page{margin:11mm 9mm 12mm}
      body{font-family:DejaVu Sans,sans-serif;color:#000;font-size:8px;margin:0}
      h1{font-size:14px;line-height:1.05;margin:0;font-weight:700}
      h2{font-size:14px;line-height:1.05;margin:1px 0 2px;font-weight:700}
      .on-call{font-size:7px;font-weight:700;margin:0 0 8px}
      .room{margin:0 0 10mm;page-break-inside:avoid}
      .room-title{border:1px solid #111;border-bottom:0;font-size:12px;padding:3px 5px;font-weight:400}
      table{border-collapse:collapse;width:100%;table-layout:fixed}
      thead{display:table-header-group}
      tr{page-break-inside:avoid}
      th,td{border:1px solid #111;padding:2px 3px;vertical-align:top;line-height:1.18;overflow-wrap:anywhere}
      th{text-align:left;font-weight:700}
      .order{width:3%;text-align:center;padding-left:1px;padding-right:1px}
      .patient{width:17%}.diagnosis{width:17%}.operation{width:17%}
      .anaesth{width:11%}.operator{width:17%}.assistants{width:18%}
      .empty{padding:8px;text-align:center}
      .summary{margin-top:6mm;background:#fff;font-size:8px;line-height:1.2;page-break-inside:avoid}
      .summary-row{display:block;white-space:nowrap}
      .summary-label{display:inline-block;width:21%;font-weight:700}
      .summary-value{display:inline-block;width:57%;white-space:normal;vertical-align:top}
      .created{margin-top:2px;text-align:right;white-space:nowrap}
      .created strong{font-size:9px}
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
