<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

final class DayTypeNodeController extends ControllerBase {

  public function listing(): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'muteti_day_type_definition')
      ->sort('field_muteti_daytype_department')
      ->sort('field_muteti_daytype_code')
      ->execute();

    $day_names = [
      1 => 'Hétfő',
      2 => 'Kedd',
      3 => 'Szerda',
      4 => 'Csütörtök',
      5 => 'Péntek',
      6 => 'Szombat',
      7 => 'Vasárnap',
    ];
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $weekdays = [];
      foreach ($node->get('field_muteti_daytype_weekdays')->getValue() as $item) {
        $day = (int) ($item['value'] ?? 0);
        if (isset($day_names[$day])) {
          $weekdays[] = $day_names[$day];
        }
      }
      $from = trim((string) $node->get('field_muteti_daytype_from')->value);
      $until = trim((string) $node->get('field_muteti_daytype_until')->value);
      $validity = ($from === '' && $until === '')
        ? 'Folyamatos'
        : ($from ?: 'kezdet') . ' – ' . ($until ?: 'folyamatos');

      $rows[] = [
        (string) $node->get('field_muteti_daytype_department')->value,
        (string) $node->get('field_muteti_daytype_code')->value,
        $weekdays ? implode(', ', $weekdays) : '–',
        [
          'data' => [
            '#markup' => nl2br(htmlspecialchars(
              (string) $node->get('field_muteti_daytype_slots')->value,
              ENT_QUOTES | ENT_SUBSTITUTE,
              'UTF-8'
            )),
          ],
          'style' => 'max-width: 650px; overflow-wrap: anywhere;',
        ],
        $validity,
        Link::fromTextAndUrl('Módosítás', Url::fromRoute('entity.node.edit_form', [
          'node' => $node->id(),
        ]))->toRenderable(),
      ];
    }

    return [
      'description' => [
        '#markup' => '<p>A cellatípusokat a <strong>%</strong> jel választja el. Az itt elmentett napirendeket használja az előjegyzési naptár.</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          'Osztály',
          'Napfajta',
          'A hét napjai',
          'Napirend / cellatípusok',
          'Érvényesség',
          'Művelet',
        ],
        '#rows' => $rows,
        '#empty' => 'Még nincs napfajta beállítva.',
        '#cache' => [
          'tags' => ['node_list:muteti_day_type_definition'],
        ],
      ],
    ];
  }

}
