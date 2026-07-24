<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

final class OncologyTreatmentController extends ControllerBase {

  public function listing(): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'muteti_oncology_treatment')
      ->sort('title')
      ->execute();

    $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $rows[] = [
        $node->label(),
        $node->isPublished() ? 'Igen' : 'Nem',
        Link::fromTextAndUrl('Módosítás', Url::fromRoute('entity.node.edit_form', [
          'node' => $node->id(),
        ]))->toRenderable(),
      ];
    }

    return [
      'actions' => [
        '#type' => 'actions',
        'add' => [
          '#type' => 'link',
          '#title' => 'Új onkológiai kezelés',
          '#url' => Url::fromRoute('node.add', [
            'node_type' => 'muteti_oncology_treatment',
          ]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => ['Kezelés/gyógyszer', 'Közzétéve', 'Művelet'],
        '#rows' => $rows,
        '#empty' => 'Még nincs onkológiai kezelés felvéve.',
        '#cache' => ['tags' => ['node_list:muteti_oncology_treatment']],
      ],
    ];
  }

}
