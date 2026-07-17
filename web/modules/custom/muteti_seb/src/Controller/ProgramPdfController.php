<?php

namespace Drupal\muteti_seb\Controller;

use Dompdf\Dompdf;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\muteti_seb\Service\UserDepartment;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

final class ProgramPdfController extends ControllerBase {
  public function __construct(private readonly Connection $database) {}
  public static function create(ContainerInterface $c): static { return new static($c->get('database')); }
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
