<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\muteti_seb\Service\UserDepartment;

final class ArticleController extends ControllerBase {

  public function title(): string {
    return $this->currentUser()->isAnonymous()
      ? 'Ez az oldal autentikációt igényel...'
      : 'Hírek';
  }

  public function listing(): array {
    if ($this->currentUser()->isAnonymous()) {
      $login = Link::fromTextAndUrl('Belépés', Url::fromRoute('user.login'))->toRenderable();
      $login['#attributes']['class'][] = 'button';
      $login['#attributes']['class'][] = 'button--primary';
      return [
        '#cache' => ['contexts' => ['user.roles']],
        'message' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['muteti-login-message']],
          'text' => ['#markup' => '<p><strong>Kérjük lépjen be felhasználói nevével!</strong></p>'],
          'login' => $login,
        ],
      ];
    }
    $department = UserDepartment::get($this->currentUser());
    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'muteti_article')
      ->condition('status', 1);
    $query->condition($query->orConditionGroup()
      ->condition('field_muteti_article_department', 'all')
      ->condition('field_muteti_article_department', $department));
    $ids = $query->sort('created', 'DESC')->execute();
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($ids);
    $build = ['#cache' => ['contexts' => ['user.roles'], 'tags' => ['node_list:muteti_article']]];
    if (!$nodes) {
      $build['empty'] = ['#markup' => '<p>Jelenleg nincs megjeleníthető cikk.</p>'];
      return $build;
    }
    $view_builder = $this->entityTypeManager()->getViewBuilder('node');
    foreach ($nodes as $node) {
      $build['article_'.$node->id()] = $view_builder->view($node, 'teaser');
    }
    return $build;
  }
}
