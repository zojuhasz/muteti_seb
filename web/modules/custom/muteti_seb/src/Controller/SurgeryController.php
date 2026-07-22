<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Session\AccountInterface;
use Drupal\muteti_seb\Service\Schedule;
use Drupal\muteti_seb\Service\DepartmentMode;
use Drupal\muteti_seb\Service\UserDepartment;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class SurgeryController extends ControllerBase {
  public function __construct(private readonly Connection $database, private readonly CsrfTokenGenerator $csrf) {}
  public static function create(ContainerInterface $c): static { return new static($c->get('database'),$c->get('csrf_token')); }

  public function access(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf(DepartmentMode::get(UserDepartment::get($account)) !== 'onko')
      ->addCacheContexts(['user.roles']);
  }

  public function week(Request $request): array {
    $department = UserDepartment::get($this->currentUser());
    try {
      $monday = new DrupalDateTime($request->query->get('week', 'monday this week'));
    }
    catch (\Exception) {
      $monday = new DrupalDateTime('monday this week');
    }
    $monday->modify('monday this week');
    $days = [];
    $day_dates = [];
    for ($i = 0; $i < 7; $i++) {
      $day = clone $monday;
      $day->modify("+$i day");
      $days[] = $day;
      $day_dates[] = $day->format('Y-m-d');
    }
    $selected = (string) $request->query->get('day', '');
    if (!in_array($selected, $day_dates, TRUE)) {
      $today = date('Y-m-d');
      $selected = in_array($today, $day_dates, TRUE) ? $today : $day_dates[0];
    }

    $prev = (clone $monday)->modify('-7 days')->format('Y-m-d');
    $next = (clone $monday)->modify('+7 days')->format('Y-m-d');
    $prev_month_day = (clone $monday)->modify('first day of previous month');
    $next_month_day = (clone $monday)->modify('first day of next month');
    $prev_month = (clone $prev_month_day)->modify('monday this week')->format('Y-m-d');
    $next_month = (clone $next_month_day)->modify('monday this week')->format('Y-m-d');
    $cards = ['#type' => 'container', '#attributes' => ['class' => ['muteti-week-cards']]];
    foreach ($days as $i => $day) {
      $date = $day->format('Y-m-d');
      $classes = ['muteti-day-card'];
      if ($date === $selected) {
        $classes[] = 'is-selected';
      }
      $cards['d'.$i] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-day-card-shell']],
        'link' => [
          '#type' => 'link',
          '#title' => [
            '#markup' => '<span class="muteti-day-card-date">'.Html::escape($date).'</span><span class="muteti-day-card-name">'.Html::escape((string) $this->t($day->format('l'))).'</span>',
          ],
          '#url' => Url::fromRoute('muteti_seb.surgery', [], ['query' => ['week' => $monday->format('Y-m-d'), 'day' => $date]]),
          '#attributes' => ['class' => $classes],
        ],
      ];
    }

    $waiting_query=$this->database->select('muteti_appointment','a')->fields('a')->condition('department',$department)->condition('admission_date',date('Y-m-d'),'<=')->condition('operation_name','','<>')->condition('operated',0);
    $waiting_status=$waiting_query->orConditionGroup()->isNull('surgery_date');
    $selected_unassigned=$waiting_query->andConditionGroup()->condition('surgery_date',$selected)->isNull('operating_room');
    $waiting_query->condition($waiting_query->orConditionGroup()->condition($waiting_status)->condition($selected_unassigned));
    $waiting=$waiting_query->orderBy('admission_date')->execute()->fetchAll();
    $assigned=$this->database->select('muteti_appointment','a')->fields('a')->condition('department',$department)->condition('surgery_date',$selected)->orderBy('operating_room')->orderBy('surgery_order')->execute()->fetchAll();
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
    $build = [
      '#attached' => [
        'library' => ['muteti_seb/surgery_board'],
        'drupalSettings' => ['mutetiSeb' => [
          'endpoint' => Url::fromRoute('muteti_seb.assignment', [], ['query' => ['token' => $this->csrf->get('muteti/api/assignment')]])->toString(),
        ]],
      ],
      '#cache' => ['max-age' => 0],
      '#type' => 'container',
      '#attributes' => ['class' => ['muteti-surgery-page']],
    ];
    $build['week_frame'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['muteti-surgery-week-frame'], 'id' => 'muteti-surgery-week'],
      'heading' => ['#markup' => '<h2 class="muteti-panel-title">'.Html::escape($department).' – heti műtéti beosztás</h2>'],
      'nav' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-nav']],
        'prev_month' => Link::fromTextAndUrl('⇤ Előző hónap', Url::fromRoute('muteti_seb.surgery', [], ['query' => ['week' => $prev_month, 'day' => $prev_month_day->format('Y-m-d')]]))->toRenderable(),
        'prev' => Link::fromTextAndUrl('← Előző hét', Url::fromRoute('muteti_seb.surgery', [], ['query' => ['week' => $prev]]))->toRenderable(),
        'today' => Link::fromTextAndUrl('Aktuális hét', Url::fromRoute('muteti_seb.surgery'))->toRenderable(),
        'next' => Link::fromTextAndUrl('Következő hét →', Url::fromRoute('muteti_seb.surgery', [], ['query' => ['week' => $next]]))->toRenderable(),
        'next_month' => Link::fromTextAndUrl('Következő hónap ⇥', Url::fromRoute('muteti_seb.surgery', [], ['query' => ['week' => $next_month, 'day' => $next_month_day->format('Y-m-d')]]))->toRenderable(),
      ],
      'cards' => $cards,
    ];
    $build['daily'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['muteti-surgery-board']],
      'top' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-daily-heading']],
        'title' => ['#markup' => '<h2 class="muteti-panel-title">'.Html::escape($selected).' – műtéti beosztás</h2>'],
        'pdf' => [
          '#type' => 'link',
          '#title' => [
            '#theme' => 'image',
            '#uri' => base_path().'modules/custom/muteti_seb/images/pdf-icon.svg',
            '#alt' => 'PDF',
            '#attributes' => ['class' => ['muteti-program-pdf-icon']],
          ],
          '#url' => Url::fromRoute('muteti_seb.program_pdf', ['date' => $selected]),
          '#attributes' => [
            'class' => ['muteti-program-pdf-link'],
            'title' => 'Műtéti program PDF',
            'aria-label' => 'Műtéti program PDF',
            'target' => '_blank',
          ],
        ],
      ],
      'layout' => ['#type' => 'container', '#attributes' => ['class' => ['muteti-board-layout']]],
    ];
    $build['daily']['layout']['waiting']=['#type'=>'container','#attributes'=>['class'=>['muteti-dropzone','muteti-waiting'],'data-room'=>'','data-date'=>''] ,'title'=>['#markup'=>'<h3 class="muteti-zone-title">Műtétre váró bentfekvők</h3>']];
    foreach($waiting as $a)$build['daily']['layout']['waiting']['p'.$a->id]=$card($a);
    $build['daily']['layout']['rooms']=['#type'=>'container','#attributes'=>['class'=>['muteti-rooms']]];
    $rooms=Schedule::departmentRooms($department);
    foreach($assigned as $a)if($a->operating_room&&!in_array($a->operating_room,$rooms,TRUE))$rooms[]=$a->operating_room;
    foreach($rooms as $room){$build['daily']['layout']['rooms']['r'.$room]=['#type'=>'container','#attributes'=>['class'=>['muteti-room','muteti-dropzone'],'data-room'=>$room,'data-date'=>$selected],'title'=>['#markup'=>'<h3 class="muteti-zone-title">Műtő '.$room.'</h3>']];foreach($assigned as $a)if($a->operating_room===$room)$build['daily']['layout']['rooms']['r'.$room]['p'.$a->id]=$card($a);}
    return $build;
  }
}
