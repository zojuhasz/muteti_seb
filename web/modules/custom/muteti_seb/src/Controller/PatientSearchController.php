<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\muteti_seb\Form\PatientSearchForm;
use Drupal\muteti_seb\Service\DepartmentMode;
use Drupal\muteti_seb\Service\UserDepartment;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class PatientSearchController extends ControllerBase {

  public function __construct(private readonly Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function search(Request $request): array {
    $department = UserDepartment::get($this->currentUser());
    $is_oncology = DepartmentMode::get($department) === 'onko';
    $term = trim((string) $request->query->get('q', ''));
    $build = [
      '#attached' => ['library' => ['muteti_seb/surgery_board']],
      '#cache' => ['max-age' => 0],
      'title' => [
        '#markup' => '<h2 class="muteti-panel-title">'.Html::escape($department).' – betegkereső</h2>',
      ],
      'intro' => [
        '#markup' => '<p>A keresés az osztály összes múltbeli és jövőbeli előjegyzésében történik.</p>',
      ],
      'form' => $this->formBuilder()->getForm(PatientSearchForm::class),
    ];

    if ($term === '' || mb_strlen($term, 'UTF-8') < 2) {
      return $build;
    }

    $query = $this->database->select('muteti_appointment', 'a');
    $query->leftJoin('muteti_doctor', 'd', 'd.id = a.doctor_id');
    $query->fields('a', [
      'id',
      'admission_date',
      'slot_type',
      'patient_name',
      'birth_date',
      'taj',
      'ward_room',
      'operation_name',
      'surgery_date',
      'operating_room',
    ]);
    $query->addField('d', 'name', 'doctor_name');
    $match = '%'.$this->database->escapeLike($term).'%';
    $query->condition('a.department', $department);
    $query->condition('a.patient_name', '', '<>');
    $query->condition(
      $query->orConditionGroup()
        ->condition('a.patient_name', $match, 'LIKE')
        ->condition($is_oncology ? 'a.ward_room' : 'a.taj', $match, 'LIKE')
    );
    $results = $query
      ->orderBy('a.admission_date', 'DESC')
      ->orderBy('a.patient_name')
      ->range(0, 200)
      ->execute()
      ->fetchAll();

    $today = date('Y-m-d');
    $can_edit = $this->currentUser()->hasPermission('create surgery appointment');
    $rows = [];
    foreach ($results as $result) {
      $period = $result->admission_date < $today
        ? 'Múlt'
        : ($result->admission_date > $today ? 'Jövő' : 'Ma');
      $patient = ['#markup' => '<strong>'.Html::escape($result->patient_name).'</strong>'];
      if ($can_edit) {
        $patient = Link::fromTextAndUrl(
          $result->patient_name,
          Url::fromRoute('muteti_seb.appointment', [
            'date' => $result->admission_date,
            'slot' => $result->slot_type,
          ])
        )->toRenderable();
        $patient['#attributes']['title'] = 'Előjegyzés megnyitása';
      }
      $rows[] = [
        ['data' => Html::escape($result->admission_date), 'class' => ['muteti-search-date']],
        [
          'data' => Html::escape($period),
          'class' => ['muteti-search-period', 'is-'.strtolower($period === 'Jövő' ? 'future' : ($period === 'Múlt' ? 'past' : 'today'))],
        ],
        ['data' => $patient],
        Html::escape($result->birth_date ?? ''),
        Html::escape($is_oncology ? ($result->ward_room ?? '') : ($result->taj ?? '')),
        Html::escape($result->slot_type),
        Html::escape($result->operation_name),
        Html::escape($result->doctor_name ?? ''),
        Html::escape($result->surgery_date ?? ''),
        Html::escape($result->operating_room ?? ''),
      ];
    }

    $build['summary'] = [
      '#markup' => '<p class="muteti-search-summary"><strong>'.count($results).'</strong> találat a következőre: <strong>'.Html::escape($term).'</strong>'
        .(count($results) === 200 ? ' (legfeljebb 200 találat jelenik meg)' : '').'</p>',
    ];
    $build['frame'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['muteti-table-frame']],
      'table' => [
        '#type' => 'table',
        '#header' => [
          'Befekvés',
          'Időszak',
          'Beteg neve',
          'Születési dátum',
          $is_oncology ? 'Kórlapszám' : 'TAJ',
          'Cellatípus',
          'Műtét',
          'Orvos',
          'Műtéti dátum',
          'Műtő',
        ],
        '#rows' => $rows,
        '#empty' => 'Nincs találat a saját osztály előjegyzéseiben.',
        '#attributes' => ['class' => ['muteti-patient-search-table']],
      ],
    ];
    return $build;
  }

}
