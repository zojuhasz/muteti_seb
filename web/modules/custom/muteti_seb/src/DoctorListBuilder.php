<?php

namespace Drupal\muteti_seb;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

final class DoctorListBuilder extends EntityListBuilder {

  public function buildHeader(): array {
    return [
      'name' => $this->t('Orvos neve'),
      'color' => $this->t('Színminta'),
      'username' => $this->t('Felhasználónév'),
      'department' => $this->t('Osztály'),
      'status' => $this->t('Állapot'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    $background = (string) $entity->get('background_color')->value ?: '#eef2f6';
    $text = (string) $entity->get('text_color')->value ?: '#111111';
    if (!$entity->get('background_color')->value) {
      $text = '#111111';
    }
    $account = $entity->get('user_id')->entity;
    return [
      'name' => Html::escape($entity->label()),
      'color' => [
        'data' => [
          '#markup' => '<span class="muteti-doctor-color-preview" style="background-color:'.Html::escape($background).';color:'.Html::escape($text).'">'.Html::escape($entity->label()).'</span>',
        ],
      ],
      'username' => $account ? Html::escape($account->getAccountName()) : '—',
      'department' => Html::escape((string) $entity->get('department')->value),
      'status' => $entity->get('active')->value ? $this->t('Aktív') : $this->t('Inaktív'),
    ] + parent::buildRow($entity);
  }

  public function render(): array {
    $build = parent::render();
    $build['#attached']['library'][] = 'muteti_seb/surgery_board';
    return $build;
  }

}
