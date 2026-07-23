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
use Drupal\muteti_seb\Service\DepartmentMode;
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
    $mode = DepartmentMode::get($department);
    $is_boss = in_array('muteti_boss', $this->currentUser()->getRoles(), TRUE);
    $week = $request->query->get('week', 'now');
    try { $monday = new DrupalDateTime($week === 'now' ? 'monday this week' : $week); }
    catch (\Exception) { $monday = new DrupalDateTime('monday this week'); }
    $monday->modify('monday this week');
    $dates = [];
    for ($i = 0; $i < 7; $i++) { $d = clone $monday; $d->modify("+$i day"); $dates[] = $d; }

    $start = $dates[0]->format('Y-m-d'); $end = $dates[6]->format('Y-m-d');
    $on_call = [];
    if (in_array($mode, ['seb', 'urol'], TRUE)) {
      $on_call_rows = $this->database->select('muteti_on_call', 'u')->fields('u', ['date', 'doctor_name', 'doctor_name_2'])->condition('mode', $mode)->condition('date', [$start, $end], 'BETWEEN')->execute();
      foreach ($on_call_rows as $on_call_row) {
        $on_call[$on_call_row->date] = array_values(array_filter([
          $on_call_row->doctor_name,
          $mode === 'seb' ? $on_call_row->doctor_name_2 : '',
        ]));
      }
    }
    $away_rows = $this->database->select('muteti_doctor_availability', 'a')
      ->fields('a', ['user_id', 'date'])
      ->condition('date', [$start, $end], 'BETWEEN')
      ->condition('status', 'away')
      ->execute()
      ->fetchAll();
    $away_user_ids = array_values(array_unique(array_map(
      static fn(object $row): int => (int) $row->user_id,
      $away_rows
    )));
    $away_doctors = [];
    if ($away_user_ids) {
      $doctor_rows = $this->database->select('muteti_doctor', 'd')
        ->fields('d', ['user_id', 'name', 'background_color'])
        ->condition('user_id', $away_user_ids, 'IN')
        ->condition('department', $department)
        ->condition('active', 1)
        ->orderBy('name')
        ->execute();
      foreach ($doctor_rows as $doctor_row) {
        $user_id = (int) $doctor_row->user_id;
        if (!isset($away_doctors[$user_id]) || trim((string) $away_doctors[$user_id]->background_color) === '') {
          $away_doctors[$user_id] = $doctor_row;
        }
      }
    }
    $away_by_date = [];
    foreach ($away_rows as $away_row) {
      $user_id = (int) $away_row->user_id;
      if (!isset($away_doctors[$user_id])) {
        continue;
      }
      $doctor = $away_doctors[$user_id];
      $background = trim((string) $doctor->background_color);
      if (!preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $background)) {
        $background = '#c62828';
      }
      $away_by_date[(string) $away_row->date][$user_id] = [
        'name' => (string) $doctor->name,
        'background' => $background,
      ];
    }
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
      $day_types[$key] = $stored[$key] ?? Schedule::departmentDayType($department, $d);
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
    $header = [];
    foreach ($dates as $d) {
      $date = $d->format('Y-m-d');
      $occupied = !empty(array_filter($by_cell[$date] ?? [], static fn(object $appointment): bool => trim((string) $appointment->patient_name) !== '' || trim((string) $appointment->operation_name) !== '' || !empty($appointment->doctor_id)));
      $type = $day_types[$date];
      $header[] = [
        'data' => [
          'label' => [
            '#markup' => '<span class="muteti-heading-date">'.Html::escape($date).'</span>',
          ],
          'day_row' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['muteti-heading-day-row']],
            'day' => [
              '#markup' => '<span class="muteti-heading-day">'.Html::escape((string) $this->t($d->format('l'))).'</span>',
            ],
            'pdf' => $mode === 'onko' ? [
              '#type' => 'link',
              '#title' => [
                '#theme' => 'image',
                '#uri' => base_path().'modules/custom/muteti_seb/images/pdf-icon.svg',
                '#alt' => 'PDF',
                '#attributes' => ['class' => ['muteti-day-pdf-icon']],
              ],
              '#url' => Url::fromRoute('muteti_seb.oncology_booking_pdf', ['date' => $date]),
              '#attributes' => [
                'class' => ['muteti-day-pdf-link'],
                'title' => $this->t('@date kezelési listája PDF-ben', ['@date' => $date]),
                'aria-label' => $this->t('@date kezelési listája PDF-ben', ['@date' => $date]),
                'target' => '_blank',
              ],
            ] : [],
          ],
          'on_call' => in_array($mode, ['seb', 'urol'], TRUE) ? [
            '#markup' => '<span class="muteti-heading-on-call" title="Ügyeletes orvos">'.(!empty($on_call[$date]) ? implode('<br>', array_map([Html::class, 'escape'], $on_call[$date])) : '—').'</span>',
          ] : [],
          'day_type' => [
            '#type' => 'select',
            '#title' => $this->t('Napfajta'),
            '#title_display' => 'invisible',
            '#options' => array_combine(Schedule::departmentDayTypes($department), Schedule::departmentDayTypes($department)),
            '#default_value' => $type,
            '#value' => $type,
            '#disabled' => $occupied || !$this->currentUser()->hasPermission('assign operating room'),
            '#attributes' => [
              'class' => ['muteti-day-type-select'],
              'data-date' => $date,
              'data-previous-value' => $type,
              'title' => $occupied ? $this->t('A napfajta már nem módosítható, mert van előjegyzett beteg.') : $this->t('Napfajta módosítása'),
            ],
          ],
          'away_strip' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => array_filter([
                'muteti-away-strip',
                empty($away_by_date[$date]) ? 'is-empty' : NULL,
              ]),
              'aria-label' => $this->t('Idegenben dolgozó orvosok'),
            ],
          ] + array_reduce(
            array_values($away_by_date[$date] ?? []),
            static function (array $segments, array $doctor): array {
              $segments['doctor_'.count($segments)] = [
                '#type' => 'html_tag',
                '#tag' => 'span',
                '#value' => '',
                '#attributes' => [
                  'class' => ['muteti-away-strip-segment'],
                  'style' => 'background-color:'.$doctor['background'].';',
                  'title' => $doctor['name'].' – idegenben',
                ],
              ];
              return $segments;
            },
            []
          ),
        ],
        'class' => array_filter(['muteti-day-heading', $occupied ? 'is-locked' : NULL]),
      ];
    }
    $rows = [];
    for ($r=0; $r<$max; $r++) {
      $row = [];
      foreach ($dates as $d) {
        $date=$d->format('Y-m-d');
        $slot = $slots_by_date[$date][$r] ?? NULL;
        if (!$slot) { $row[]=['data'=>['#markup'=>'—']]; continue; }
        $a=$by_cell[$date][$slot] ?? NULL;
        $placeholder = $a && trim((string) $a->patient_name) === '' && trim((string) $a->operation_name) === '' && empty($a->doctor_id);
        if (!$a || $placeholder) {
          $slot_link = Link::fromTextAndUrl($slot, Url::fromRoute('muteti_seb.appointment', ['date'=>$date,'slot'=>$slot]))->toRenderable();
          $slot_link['#attributes']['class'][] = 'muteti-slot-link';
          $empty_cell = ['slot' => $slot_link];
          if ($is_boss && !$placeholder) {
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
            $has_background = trim((string) $doctor->background_color) !== '';
            $background = $has_background ? $doctor->background_color : '#eef2f6';
            // A legacy record may contain white text without a background
            // color. On the light fallback background that would be invisible.
            $text = $has_background ? ($doctor->text_color ?: '#111111') : '#111111';
            $style = 'background-color:'.$background.';color:'.$text.';';
            if (!empty($doctor->background_gif)) {
              $gif_url = \Drupal::service('file_url_generator')->generateString($doctor->background_gif);
              $style .= 'background-image:url("'.str_replace('"', '%22', $gif_url).'");background-size:cover;background-position:center;background-repeat:no-repeat;';
            }
            $patient_attributes['style'] = $style;
          }
          $cell = [
            'patient' => [
              '#type' => 'container',
              '#attributes' => $patient_attributes,
              'edit' => $edit,
              'slot' => [
                '#markup' => '<div class="muteti-patient-slot">'.Html::escape($slot).'</div>',
              ],
              'actions' => $is_boss ? [
                '#type' => 'container',
                '#attributes' => ['class' => ['muteti-patient-actions']],
                'move' => [
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
                ],
                'separator' => ['#markup' => '<span class="muteti-action-separator">|</span>'],
                'delete' => [
                  '#type' => 'html_tag',
                  '#tag' => 'button',
                  '#value' => '0',
                  '#attributes' => [
                    'type' => 'button',
                    'class' => ['muteti-delete-link'],
                    'data-delete-id' => (string) $a->id,
                    'data-delete-patient' => $a->patient_name,
                    'title' => 'Beteg törlése',
                    'aria-label' => 'Beteg törlése',
                  ],
                ],
              ] : [],
              'content' => [
                '#markup' => '<strong>'.Html::escape($a->patient_name).'</strong><br>TAJ: '.Html::escape($a->taj ?? '').'<br>'.Html::escape($a->operation_name).($doctor ? '<br><span class="muteti-staff">Orvos: '.Html::escape($doctor->name).'</span>' : ''),
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
    // Render each day as an independent, top-aligned vertical list. A taller
    // appointment in one day must not create empty vertical space in the
    // other day columns as regular table rows would do.
    $column_row = [];
    foreach (array_keys($dates) as $column_index) {
      $column = [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-booking-column']],
      ];
      foreach ($rows as $row_index => $booking_row) {
        $column['slot_'.$row_index] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['muteti-booking-column-cell']],
          'content' => $booking_row[$column_index]['data'],
        ];
      }
      $column_row[] = ['data' => $column];
    }
    $rows = [$column_row];
    $prev=(clone $monday)->modify('-7 days')->format('Y-m-d'); $next=(clone $monday)->modify('+7 days')->format('Y-m-d');
    $prev_month=(clone $monday)->modify('first day of previous month')->modify('monday this week')->format('Y-m-d');
    $next_month=(clone $monday)->modify('first day of next month')->modify('monday this week')->format('Y-m-d');
    return [
      '#attached'=>[
        'library'=>['muteti_seb/surgery_board'],
        'drupalSettings'=>['mutetiSeb'=>[
          'appointmentMoveEndpoint'=>Url::fromRoute('muteti_seb.appointment_move',[],['query'=>['token'=>$this->csrf->get('muteti/api/appointment-move')]])->toString(),
          'appointmentDeleteEndpoint'=>Url::fromRoute('muteti_seb.appointment_delete',[],['query'=>['token'=>$this->csrf->get('muteti/api/appointment-delete')]])->toString(),
          'dayTypeEndpoint'=>Url::fromRoute('muteti_seb.day_type',[],['query'=>['token'=>$this->csrf->get('muteti/api/day-type')]])->toString(),
        ]],
      ],
      '#cache'=>['max-age'=>0],
      'department'=>['#markup'=>'<h2 class="muteti-panel-title">'.Html::escape($department).' – előjegyzés</h2>'],
      'nav'=>[
        '#type'=>'container',
        '#attributes'=>['class'=>['muteti-nav','muteti-booking-nav']],
        'prev_month'=>Link::fromTextAndUrl('⇤ Előző hónap',Url::fromRoute('muteti_seb.booking',[],['query'=>['week'=>$prev_month]]))->toRenderable(),
        'prev'=>Link::fromTextAndUrl('← Előző hét',Url::fromRoute('muteti_seb.booking',[],['query'=>['week'=>$prev]]))->toRenderable(),
        'today'=>Link::fromTextAndUrl('Aktuális hét',Url::fromRoute('muteti_seb.booking'))->toRenderable(),
        'next'=>Link::fromTextAndUrl('Következő hét →',Url::fromRoute('muteti_seb.booking',[],['query'=>['week'=>$next]]))->toRenderable(),
        'next_month'=>Link::fromTextAndUrl('Következő hónap ⇥',Url::fromRoute('muteti_seb.booking',[],['query'=>['week'=>$next_month]]))->toRenderable(),
      ],
      'table_wrapper'=>[
        '#type'=>'container',
        '#attributes'=>['class'=>['muteti-table-frame'],'id'=>'muteti-booking-table'],
        'table'=>['#type'=>'table','#header'=>$header,'#rows'=>$rows,'#attributes'=>['class'=>['muteti-booking-table']]],
      ],
    ];
  }
}
