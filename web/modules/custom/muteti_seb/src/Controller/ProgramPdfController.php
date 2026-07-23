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
    $department=UserDepartment::get($this->currentUser());
    $rows=$this->database->select('muteti_appointment','a')->fields('a')->condition('department',$department)->condition('surgery_date',$date)->orderBy('operating_room')->orderBy('surgery_order')->execute()->fetchAll();
    $doctor_ids=[];foreach($rows as $a)foreach(['doctor_id','assistant1_id','assistant2_id','assistant3_id'] as $f)if($a->{$f})$doctor_ids[]=$a->{$f};
    $doctors=$doctor_ids?$this->database->select('muteti_doctor','d')->fields('d',['id','name'])->condition('id',array_unique($doctor_ids),'IN')->execute()->fetchAllKeyed():[];
    $html='<meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px}h1{margin:0}.room{font-size:24px;color:#777;margin-top:18px}.patient{padding:6px}.patient:nth-child(even){background:#dce8fa}.diag{float:right;width:38%;font-weight:bold}</style><h1>'.htmlspecialchars($department).'</h1><h2>'.htmlspecialchars($date).'</h2>';
    $current=NULL;foreach($rows as $a){if($current!==$a->operating_room){$current=$a->operating_room;$html.='<div class="room">'.htmlspecialchars($current).'</div><small>MŰTŐ 08:30</small>';}$assist=[];foreach(['assistant1_id','assistant2_id','assistant3_id'] as $f)if($a->{$f}&&isset($doctors[$a->{$f}]))$assist[]=$doctors[$a->{$f}];$html.='<div class="patient"><div class="diag">Dg.: '.htmlspecialchars($a->diagnosis).'</div><strong>('.$a->surgery_order.') '.htmlspecialchars($a->patient_name).'</strong><br>Op.: '.htmlspecialchars($a->operation_name).'<br><strong>'.htmlspecialchars($doctors[$a->doctor_id]??'-').($assist?', '.htmlspecialchars(implode(', ',$assist)):'').'</strong></div>';}
    $pdf=new Dompdf(['isRemoteEnabled'=>FALSE]);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();return new Response($pdf->output(),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'inline; filename="muteti-program-'.$date.'.pdf"']);
  }
}
