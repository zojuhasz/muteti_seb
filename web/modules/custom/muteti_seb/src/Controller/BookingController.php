<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\muteti_seb\Service\Schedule;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class BookingController extends ControllerBase {
  public function __construct(private readonly Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function week(Request $request): array {
    $week = $request->query->get('week', 'now');
    try { $monday = new DrupalDateTime($week === 'now' ? 'monday this week' : $week); }
    catch (\Exception) { $monday = new DrupalDateTime('monday this week'); }
    $monday->modify('monday this week');
    $dates = [];
    for ($i = 0; $i < 7; $i++) { $d = clone $monday; $d->modify("+$i day"); $dates[] = $d; }

    $start = $dates[0]->format('Y-m-d'); $end = $dates[6]->format('Y-m-d');
    $appointments = $this->database->select('muteti_appointment', 'a')->fields('a')->condition('admission_date', [$start, $end], 'BETWEEN')->execute()->fetchAllAssoc('id');
    $by_cell = [];
    foreach ($appointments as $a) { $by_cell[$a->admission_date][$a->slot_type] = $a; }
    $doctor_ids = array_values(array_unique(array_filter(array_map(static fn($a) => $a->doctor_id, $appointments))));
    $doctors = $doctor_ids
      ? $this->database->select('muteti_doctor', 'd')->fields('d')->condition('id', $doctor_ids, 'IN')->execute()->fetchAllAssoc('id')
      : [];
    $day_types = [];
    $stored = $this->database->select('muteti_day_type', 'd')->fields('d')->condition('date', [$start, $end], 'BETWEEN')->execute()->fetchAllKeyed();
    foreach ($dates as $d) { $key=$d->format('Y-m-d'); $day_types[$key]=$stored[$key] ?? Schedule::defaultDayType($d); }
    $max = max(array_map(fn($t) => count(Schedule::DAY_TYPES[$t]), $day_types));
    $header = [
      ['data' => $this->t('Sorszám'), 'class' => ['muteti-index-heading']],
    ];
    foreach ($dates as $d) {
      $header[] = [
        'data' => [
          '#markup' => '<span class="muteti-heading-date">'.Html::escape($d->format('Y-m-d')).'</span><span class="muteti-heading-day">'.Html::escape((string) $this->t($d->format('l'))).'</span>',
        ],
        'class' => ['muteti-day-heading'],
      ];
    }
    $rows = [];
    for ($r=0; $r<$max; $r++) {
      $row = [$r+1];
      foreach ($dates as $d) {
        $date=$d->format('Y-m-d'); $slot=Schedule::DAY_TYPES[$day_types[$date]][$r] ?? NULL;
        if (!$slot) { $row[]=['data'=>['#markup'=>'—']]; continue; }
        $a=$by_cell[$date][$slot] ?? NULL;
        if (!$a) {
          $slot_link = Link::fromTextAndUrl($slot, Url::fromRoute('muteti_seb.appointment', ['date'=>$date,'slot'=>$slot]))->toRenderable();
          $slot_link['#attributes']['class'][] = 'muteti-slot-link';
          $row[] = [
            'data' => $slot_link,
          ];
        }
        else {
          $edit = Link::fromTextAndUrl('M', Url::fromRoute('muteti_seb.appointment', ['date'=>$date,'slot'=>$slot]))->toRenderable();
          $edit['#attributes']['class'][] = 'muteti-edit-link';
          $edit['#attributes']['title'] = $this->t('Módosítás');
          $edit['#attributes']['aria-label'] = $this->t('Módosítás');
          $doctor = $doctors[$a->doctor_id] ?? NULL;
          $patient_attributes = ['class' => ['muteti-patient']];
          if ($doctor) {
            $background = $doctor->background_color ?: '#eef2f6';
            $text = $doctor->text_color ?: '#111111';
            $patient_attributes['style'] = 'background-color:'.$background.';color:'.$text;
          }
          $cell = [
            'patient' => [
              '#type' => 'container',
              '#attributes' => $patient_attributes,
              'edit' => $edit,
              'content' => [
                '#markup' => '<strong>'.Html::escape($a->patient_name).'</strong><br>TAJ: '.Html::escape($a->taj ?? '').'<br>'.Html::escape($a->operation_name).($doctor ? '<br><span class="muteti-staff">'.Html::escape($doctor->name).'</span>' : ''),
              ],
            ],
          ];
          if ($a->aznm) {
            $cell['aznm'] = ['#markup' => '<span class="muteti-aznm"></span>', '#weight' => -10];
          }
          $row[] = ['data' => $cell];
        }
      }
      $rows[]=$row;
    }
    $prev=(clone $monday)->modify('-7 days')->format('Y-m-d'); $next=(clone $monday)->modify('+7 days')->format('Y-m-d');
    return [
      '#attached'=>['library'=>['muteti_seb/surgery_board']],
      '#cache'=>['max-age'=>0],
      'nav'=>['#type'=>'container','#attributes'=>['class'=>['muteti-nav']], 'prev'=>Link::fromTextAndUrl('← Előző hét',Url::fromRoute('muteti_seb.booking',[],['query'=>['week'=>$prev]]))->toRenderable(),'today'=>Link::fromTextAndUrl('Aktuális hét',Url::fromRoute('muteti_seb.booking'))->toRenderable(),'next'=>Link::fromTextAndUrl('Következő hét →',Url::fromRoute('muteti_seb.booking',[],['query'=>['week'=>$next]]))->toRenderable()],
      'table_wrapper'=>[
        '#type'=>'container',
        '#attributes'=>['class'=>['muteti-table-frame']],
        'table'=>['#type'=>'table','#header'=>$header,'#rows'=>$rows,'#attributes'=>['class'=>['muteti-booking-table']]],
      ],
    ];
  }
}
