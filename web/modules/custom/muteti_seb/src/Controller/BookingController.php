<?php

namespace Drupal\muteti_seb\Controller;

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
    $day_types = [];
    $stored = $this->database->select('muteti_day_type', 'd')->fields('d')->condition('date', [$start, $end], 'BETWEEN')->execute()->fetchAllKeyed();
    foreach ($dates as $d) { $key=$d->format('Y-m-d'); $day_types[$key]=$stored[$key] ?? Schedule::defaultDayType($d); }
    $max = max(array_map(fn($t) => count(Schedule::DAY_TYPES[$t]), $day_types));
    $header = [$this->t('Sorszám')];
    foreach ($dates as $d) { $header[] = $d->format('Y-m-d') . '<br>' . $this->t($d->format('l')); }
    $rows = [];
    for ($r=0; $r<$max; $r++) {
      $row = [$r+1];
      foreach ($dates as $d) {
        $date=$d->format('Y-m-d'); $slot=Schedule::DAY_TYPES[$day_types[$date]][$r] ?? NULL;
        if (!$slot) { $row[]=['data'=>['#markup'=>'—']]; continue; }
        $a=$by_cell[$date][$slot] ?? NULL;
        if (!$a) {
          $row[] = [
            'data' => Link::fromTextAndUrl($slot, Url::fromRoute('muteti_seb.appointment', ['date'=>$date,'slot'=>$slot]))->toRenderable(),
          ];
        }
        else {
          $edit = Link::fromTextAndUrl('M', Url::fromRoute('muteti_seb.appointment', ['date'=>$date,'slot'=>$slot]))->toString();
          $aznm = $a->aznm ? '<span class="muteti-aznm"></span>' : '';
          $row[]=['data'=>['#markup'=>$aznm.$edit.'<div class="muteti-patient"><strong>'.htmlspecialchars($a->patient_name).'</strong><br>TAJ: '.htmlspecialchars($a->taj ?? '').'<br>'.htmlspecialchars($a->operation_name).'</div>']];
        }
      }
      $rows[]=$row;
    }
    $prev=(clone $monday)->modify('-7 days')->format('Y-m-d'); $next=(clone $monday)->modify('+7 days')->format('Y-m-d');
    return [
      '#attached'=>['library'=>['muteti_seb/surgery_board']],
      'nav'=>['#type'=>'container','#attributes'=>['class'=>['muteti-nav']], 'prev'=>Link::fromTextAndUrl('← Előző hét',Url::fromRoute('muteti_seb.booking',[],['query'=>['week'=>$prev]]))->toRenderable(),'today'=>Link::fromTextAndUrl('Aktuális hét',Url::fromRoute('muteti_seb.booking'))->toRenderable(),'next'=>Link::fromTextAndUrl('Következő hét →',Url::fromRoute('muteti_seb.booking',[],['query'=>['week'=>$next]]))->toRenderable()],
      'table'=>['#type'=>'table','#header'=>$header,'#rows'=>$rows,'#attributes'=>['class'=>['muteti-booking-table']]],
    ];
  }
}
