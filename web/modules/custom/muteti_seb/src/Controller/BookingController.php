<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\muteti_seb\Service\Schedule;
use Drupal\muteti_seb\Service\UserDepartment;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class BookingController extends ControllerBase {
  public function __construct(private readonly Connection $database, private readonly CsrfTokenGenerator $csrf) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'), $container->get('csrf_token'));
  }

  public function week(Request $request): array {
    $department = UserDepartment::get($this->currentUser());
    $is_boss = in_array('muteti_boss', $this->currentUser()->getRoles(), TRUE);
    $week = $request->query->get('week', 'now');
    try { $monday = new DrupalDateTime($week === 'now' ? 'monday this week' : $week); }
    catch (\Exception) { $monday = new DrupalDateTime('monday this week'); }
    $monday->modify('monday this week');
    $dates = [];
    for ($i = 0; $i < 7; $i++) { $d = clone $monday; $d->modify("+$i day"); $dates[] = $d; }

    $start = $dates[0]->format('Y-m-d'); $end = $dates[6]->format('Y-m-d');
    $appointments = $this->database->select('muteti_appointment', 'a')->fields('a')->condition('department', $department)->condition('admission_date', [$start, $end], 'BETWEEN')->execute()->fetchAllAssoc('id');
    $by_cell = [];
    foreach ($appointments as $a) { $by_cell[$a->admission_date][$a->slot_type] = $a; }
    $doctor_ids = array_values(array_unique(array_filter(array_map(static fn($a) => $a->doctor_id, $appointments))));
    $doctors = $doctor_ids
      ? $this->database->select('muteti_doctor', 'd')->fields('d')->condition('id', $doctor_ids, 'IN')->execute()->fetchAllAssoc('id')
      : [];
    $day_types = [];
    $stored = $this->database->select('muteti_day_type', 'd')->fields('d', ['date', 'day_type'])->condition('department', $department)->condition('date', [$start, $end], 'BETWEEN')->execute()->fetchAllKeyed();
    $slots_by_date = [];
    foreach ($dates as $d) {
      $key=$d->format('Y-m-d');
      $day_types[$key] = $department === 'Sebészet'
        ? ($stored[$key] ?? Schedule::defaultDayType($d))
        : Schedule::departmentDayType($department, $d);
      $slots_by_date[$key] = Schedule::departmentSlots($department, $d, $day_types[$key]);
      // Never hide an imported legacy appointment whose historical slot name
      // is not part of the currently configured day template.
      foreach (array_keys($by_cell[$key] ?? []) as $existing_slot) {
        if (!in_array($existing_slot, $slots_by_date[$key], TRUE)) {
          $slots_by_date[$key][] = $existing_slot;
        }
      }
    }
    $max = max(array_map('count', $slots_by_date));
    $header = [
      ['data' => $this->t('Sorszám'), 'class' => ['muteti-index-heading']],
    ];
    foreach ($dates as $d) {
      $header[] = [
        'data' => [
          '#markup' => '<span class="muteti-heading-date">'.Html::escape($d->format('Y-m-d')).'</span><span class="muteti-heading-day">'.Html::escape((string) $this->t($d->format('l'))).'</span><span class="muteti-heading-type">'.Html::escape($day_types[$d->format('Y-m-d')]).'</span>',
        ],
        'class' => ['muteti-day-heading'],
      ];
    }
    $rows = [];
    for ($r=0; $r<$max; $r++) {
      $row = [$r+1];
      foreach ($dates as $d) {
        $date=$d->format('Y-m-d');
        $slot = $slots_by_date[$date][$r] ?? NULL;
        if (!$slot) { $row[]=['data'=>['#markup'=>'—']]; continue; }
        $a=$by_cell[$date][$slot] ?? NULL;
        if (!$a) {
          $slot_link = Link::fromTextAndUrl($slot, Url::fromRoute('muteti_seb.appointment', ['date'=>$date,'slot'=>$slot]))->toRenderable();
          $slot_link['#attributes']['class'][] = 'muteti-slot-link';
          $empty_cell = ['slot' => $slot_link];
          if ($is_boss) {
            $empty_cell['move'] = [
              '#type' => 'html_tag',
              '#tag' => 'button',
              '#value' => 'áth',
              '#attributes' => [
                'type' => 'button',
                'class' => ['muteti-move-link', 'is-target'],
                'data-move-mode' => 'move',
                'data-move-date' => $date,
                'data-move-slot' => $slot,
                'title' => 'Áthelyezés ide',
              ],
            ];
            $empty_cell['duplicate'] = [
                '#type' => 'html_tag',
                '#tag' => 'button',
                '#value' => 'dup',
                '#attributes' => [
                  'type' => 'button',
                  'class' => ['muteti-move-link', 'is-target', 'is-duplicate'],
                  'data-move-mode' => 'duplicate',
                  'data-move-date' => $date,
                  'data-move-slot' => $slot,
                  'title' => 'Duplikálás ide',
                ],
            ];
          }
          $row[] = ['data' => $empty_cell];
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
              'slot' => [
                '#markup' => '<div class="muteti-patient-slot">'.Html::escape($slot).'</div>',
              ],
              'move' => $is_boss ? [
                '#type' => 'html_tag',
                '#tag' => 'button',
                '#value' => 'áth',
                '#attributes' => [
                  'type' => 'button',
                  'class' => ['muteti-move-link', 'is-source'],
                  'data-move-id' => (string) $a->id,
                  'data-move-patient' => $a->patient_name,
                  'title' => 'Áthelyezés',
                ],
              ] : [],
              'delete' => $is_boss ? [
                '#type' => 'html_tag',
                '#tag' => 'button',
                '#value' => 'DEL',
                '#attributes' => [
                  'type' => 'button',
                  'class' => ['muteti-delete-link'],
                  'data-delete-id' => (string) $a->id,
                  'data-delete-patient' => $a->patient_name,
                  'title' => 'Beteg törlése',
                  'aria-label' => 'Beteg törlése',
                ],
              ] : [],
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
      '#attached'=>[
        'library'=>['muteti_seb/surgery_board'],
        'drupalSettings'=>['mutetiSeb'=>[
          'appointmentMoveEndpoint'=>Url::fromRoute('muteti_seb.appointment_move',[],['query'=>['token'=>$this->csrf->get('muteti/api/appointment-move')]])->toString(),
          'appointmentDeleteEndpoint'=>Url::fromRoute('muteti_seb.appointment_delete',[],['query'=>['token'=>$this->csrf->get('muteti/api/appointment-delete')]])->toString(),
        ]],
      ],
      '#cache'=>['max-age'=>0],
      'department'=>['#markup'=>'<h2 class="muteti-panel-title">'.Html::escape($department).' – előjegyzés</h2>'],
      'nav'=>['#type'=>'container','#attributes'=>['class'=>['muteti-nav','muteti-booking-nav']], 'prev'=>Link::fromTextAndUrl('← Előző hét',Url::fromRoute('muteti_seb.booking',[],['query'=>['week'=>$prev]]))->toRenderable(),'today'=>Link::fromTextAndUrl('Aktuális hét',Url::fromRoute('muteti_seb.booking'))->toRenderable(),'next'=>Link::fromTextAndUrl('Következő hét →',Url::fromRoute('muteti_seb.booking',[],['query'=>['week'=>$next]]))->toRenderable()],
      'table_wrapper'=>[
        '#type'=>'container',
        '#attributes'=>['class'=>['muteti-table-frame'],'id'=>'muteti-booking-table'],
        'table'=>['#type'=>'table','#header'=>$header,'#rows'=>$rows,'#attributes'=>['class'=>['muteti-booking-table']]],
      ],
    ];
  }
}
