<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\muteti_seb\Service\UserDepartment;

final class ArticleController extends ControllerBase {

  public function listing(): array {
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
