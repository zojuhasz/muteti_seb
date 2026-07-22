<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class DepartmentController extends ControllerBase {

  public function __construct(private readonly Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function adminAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf((int) $account->id() === 1)
      ->addCacheContexts(['user']);
  }

  public function listing(): array {
    $rows = [];
    $departments = $this->database->select('muteti_department_config', 'd')
      ->fields('d')
      ->orderBy('name')
      ->execute();
    foreach ($departments as $department) {
      $rows[] = [
        $department->name,
        $department->machine_name,
        $department->mode,
        $department->role_id,
        Link::fromTextAndUrl('Módosítás', Url::fromRoute('muteti_seb.department_edit', ['department' => $department->id]))->toRenderable(),
      ];
    }
    return [
      '#cache' => ['max-age' => 0],
      'intro' => ['#markup' => '<p>Az osztály működési módja határozza meg a naptár, a cellatípusok, a műtők és a PDF működését.</p>'],
      'add' => Link::fromTextAndUrl('Új osztály', Url::fromRoute('muteti_seb.department_add'))->toRenderable(),
      'table' => [
        '#type' => 'table',
        '#header' => ['Osztály', 'Gépi név', 'Működési mód', 'Felhasználói szerepkör', 'Művelet'],
        '#rows' => $rows,
        '#empty' => 'Nincs beállított osztály.',
        '#attributes' => ['class' => ['muteti-doctor-table']],
      ],
    ];
  }

}
