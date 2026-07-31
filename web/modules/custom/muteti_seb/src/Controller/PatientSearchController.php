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
    $account = $this->currentUser();
    $department = UserDepartment::get($account);
    $global_search = mb_strtolower($account->getAccountName(), 'UTF-8') === 'user1';
    $is_oncology = DepartmentMode::get($department) === 'onko';
    $term = trim((string) $request->query->get('q', $request->query->get('query', '')));
    $build = [
      '#attached' => ['library' => ['muteti_seb/surgery_board']],
      '#cache' => ['max-age' => 0],
      'title' => [
        '#markup' => '<h2 class="muteti-panel-title">'.Html::escape($global_search ? 'Minden osztály' : $department).' – betegkereső</h2>',
      ],
      'intro' => [
        '#markup' => '<p>'.($global_search
          ? 'A keresés minden osztály múltbeli és jövőbeli előjegyzésében történik.'
          : 'A keresés az osztály összes múltbeli és jövőbeli előjegyzésében történik.').'</p>',
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
      'taj',
      'department',
    ]);
    $query->addField('d', 'name', 'doctor_name');
    if (!$global_search) {
      $query->condition('a.department', $department);
    }
    $query->condition('a.patient_name', '', '<>');
    $fragments = preg_split('/\s+/u', mb_strtolower($term, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($fragments as $index => $fragment) {
      $name_placeholder = ':muteti_search_name_'.$index;
      $identifier_placeholder = ':muteti_search_identifier_'.$index;
      $match = '%'.$this->database->escapeLike($fragment).'%';
      $query->where(
        '(LOWER(a.patient_name) LIKE '.$name_placeholder.' OR LOWER(a.taj) LIKE '.$identifier_placeholder.')',
        [
          $name_placeholder => $match,
          $identifier_placeholder => $match,
        ]
      );
    }
    $results = $query
      ->orderBy('a.admission_date', 'DESC')
      ->orderBy('a.patient_name')
      ->range(0, 200)
      ->execute()
      ->fetchAll();

    $rows = [];
    foreach ($results as $result) {
      $week = (new \DateTimeImmutable($result->admission_date))
        ->modify('monday this week')
        ->format('Y-m-d');
      if ($global_search) {
        $patient = ['#markup' => Html::escape($result->patient_name)];
      }
      else {
        $patient = Link::fromTextAndUrl(
          $result->patient_name,
          Url::fromRoute('muteti_seb.booking', [], [
            'query' => ['week' => $week],
            'fragment' => 'muteti-appointment-'.$result->id,
          ])
        )->toRenderable();
        $patient['#attributes']['title'] = 'Megmutatás az előjegyzési táblában';
      }
      $row = [
        ['data' => Html::escape($result->admission_date), 'class' => ['muteti-search-date']],
      ];
      if ($global_search) {
        $row[] = ['data' => Html::escape($result->department), 'class' => ['muteti-search-department']];
      }
      $row[] = ['data' => $patient, 'class' => ['muteti-search-patient']];
      $row[] = ['data' => Html::escape($result->taj ?? ''), 'class' => ['muteti-search-identifier']];
      $row[] = ['data' => Html::escape($result->slot_type), 'class' => ['muteti-search-slot']];
      $row[] = ['data' => Html::escape($result->doctor_name ?? ''), 'class' => ['muteti-search-doctor']];
      $rows[] = $row;
    }

    $build['summary'] = [
      '#markup' => '<p class="muteti-search-summary"><strong>'.count($results).'</strong> találat a következőre: <strong>'.Html::escape($term).'</strong>'
        .(count($results) === 200 ? ' (legfeljebb 200 találat jelenik meg)' : '').'</p>',
    ];
    $header = ['Dátum'];
    if ($global_search) {
      $header[] = 'Osztály';
    }
    $header = array_merge($header, [
      'Beteg neve',
      $global_search ? 'Kórlap / TAJ' : ($is_oncology ? 'Kórlap' : 'TAJ'),
      'Cellatípus',
      'Orvos neve',
    ]);
    $build['frame'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['muteti-table-frame']],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $global_search
          ? 'Nincs találat az osztályok előjegyzéseiben.'
          : 'Nincs találat a saját osztály előjegyzéseiben.',
        '#attributes' => ['class' => ['muteti-patient-search-table']],
      ],
    ];
    return $build;
  }

}
