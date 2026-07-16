<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\muteti_seb\Service\Schedule;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class SurgeryController extends ControllerBase {
  public function __construct(private readonly Connection $database, private readonly CsrfTokenGenerator $csrf) {}
  public static function create(ContainerInterface $c): static { return new static($c->get('database'),$c->get('csrf_token')); }

  public function week(Request $request): array {
    $monday=new DrupalDateTime($request->query->get('week','monday this week')); $monday->modify('monday this week');
    $selected=$request->query->get('day'); $days=[];
    for($i=0;$i<7;$i++){ $d=clone $monday;$d->modify("+$i day");$days[]=$d; }
    if (!$selected) {
      $cards=['#type'=>'container','#attributes'=>['class'=>['muteti-week-cards']]];
      foreach($days as $i=>$d){$date=$d->format('Y-m-d');$stored=$this->database->select('muteti_day_type','t')->fields('t',['day_type'])->condition('date',$date)->execute()->fetchField();$type=$stored?:Schedule::defaultDayType($d);$cards['d'.$i]=Link::fromTextAndUrl($date.' '.$d->format('l').' - '.$type,Url::fromRoute('muteti_seb.surgery',[],['query'=>['week'=>$monday->format('Y-m-d'),'day'=>$date]]))->toRenderable();$cards['d'.$i]['#attributes']['class'][]='muteti-day-card';}
      return [
        '#attached' => ['library' => ['muteti_seb/surgery_board']],
        '#cache' => ['max-age' => 0],
        'cards' => $cards,
      ];
    }
    $waiting=$this->database->select('muteti_appointment','a')->fields('a')->condition('admission_date',date('Y-m-d'),'<=')->condition('operated',0)->isNull('surgery_date')->orderBy('admission_date')->execute()->fetchAll();
    $assigned=$this->database->select('muteti_appointment','a')->fields('a')->condition('surgery_date',$selected)->orderBy('operating_room')->orderBy('surgery_order')->execute()->fetchAll();
    $doctor_ids=[];
    foreach(array_merge($waiting,$assigned) as $a) {
      foreach ([$a->doctor_id, $a->assistant1_id, $a->assistant2_id, $a->assistant3_id] as $staff_id) {
        if ($staff_id) $doctor_ids[]=$staff_id;
      }
    }
    $doctors=$doctor_ids?$this->database->select('muteti_doctor','d')->fields('d')->condition('id',array_unique($doctor_ids),'IN')->execute()->fetchAllAssoc('id'):[];
    $card = function ($a) use ($doctors): array {
      $doctor = $doctors[$a->doctor_id] ?? NULL;
      $staff = [];
      foreach ([$a->doctor_id, $a->assistant1_id, $a->assistant2_id, $a->assistant3_id] as $staff_id) {
        if ($staff_id && isset($doctors[$staff_id])) {
          $staff[] = $doctors[$staff_id]->name;
        }
      }
      $staff = array_values(array_unique($staff));
      $attributes = [
        'class' => array_filter(['muteti-drag-card', $a->aznm ? 'is-aznm' : NULL]),
        'draggable' => 'true',
        'data-id' => (string) $a->id,
      ];
      if ($doctor) {
        $attributes['style'] = 'background-color:'.($doctor->background_color ?: '#eef2f6').';color:'.($doctor->text_color ?: '#111111');
      }
      return [
        '#type' => 'container',
        '#attributes' => $attributes,
        'content' => [
          '#markup' => '<strong>'.Html::escape($a->patient_name).'</strong><br>TAJ: '.Html::escape($a->taj ?? '').'<br>'.Html::escape($a->operation_name).($staff ? '<br><span class="muteti-staff">'.implode(', ', array_map([Html::class, 'escape'], $staff)).'</span>' : ''),
        ],
      ];
    };
    $build=['#attached'=>['library'=>['muteti_seb/surgery_board'],'drupalSettings'=>['mutetiSeb'=>['endpoint'=>Url::fromRoute('muteti_seb.assignment',[],['query'=>['token'=>$this->csrf->get('muteti/api/assignment')]])->toString()]]], '#cache'=>['max-age'=>0], '#type'=>'container','#attributes'=>['class'=>['muteti-surgery-board']]];
    $build['top']=['#markup'=>'<div class="muteti-nav">'.Link::fromTextAndUrl('← Heti áttekintés',Url::fromRoute('muteti_seb.surgery',[],['query'=>['week'=>$monday->format('Y-m-d')]]))->toString().' '.Link::fromTextAndUrl('Műtéti program PDF',Url::fromRoute('muteti_seb.program_pdf',['date'=>$selected]))->toString().'</div><h2>'.$selected.'</h2>'];
    $build['layout']=['#type'=>'container','#attributes'=>['class'=>['muteti-board-layout']]];
    $build['layout']['waiting']=['#type'=>'container','#attributes'=>['class'=>['muteti-dropzone','muteti-waiting'],'data-room'=>'','data-date'=>''] ,'title'=>['#markup'=>'<h3>Műtétre váró bentfekvők</h3>']];
    foreach($waiting as $a)$build['layout']['waiting']['p'.$a->id]=$card($a);
    $build['layout']['rooms']=['#type'=>'container','#attributes'=>['class'=>['muteti-rooms']]];
    foreach(Schedule::ROOMS as $room){$build['layout']['rooms']['r'.$room]=['#type'=>'container','#attributes'=>['class'=>['muteti-room','muteti-dropzone'],'data-room'=>$room,'data-date'=>$selected],'title'=>['#markup'=>'<h3>Műtő '.$room.'</h3>']];foreach($assigned as $a)if($a->operating_room===$room)$build['layout']['rooms']['r'.$room]['p'.$a->id]=$card($a);}
    return $build;
  }
}
