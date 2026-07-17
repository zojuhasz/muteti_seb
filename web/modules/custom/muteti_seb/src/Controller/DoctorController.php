<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class DoctorController extends ControllerBase {

  public function __construct(private readonly Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function listing(): array {
    $doctors = $this->database->select('muteti_doctor', 'd')
      ->fields('d')
      ->condition('department', 'Sebészet')
      ->orderBy('active', 'DESC')
      ->orderBy('name')
      ->execute()
      ->fetchAll();

    $rows = [];
    foreach ($doctors as $doctor) {
      $account = $doctor->user_id ? User::load($doctor->user_id) : NULL;
      $preview_attributes = [
        'class' => ['muteti-doctor-color-preview'],
        'style' => 'background-color:'.($doctor->background_color ?: '#eef2f6').';color:'.($doctor->text_color ?: '#111111'),
      ];
      $rows[] = [
        'name' => Html::escape($doctor->name),
        'color' => [
          'data' => [
            '#type' => 'container',
            '#attributes' => $preview_attributes,
            'text' => ['#markup' => Html::escape($doctor->name)],
          ],
        ],
        'username' => $account ? Html::escape($account->getAccountName()) : '—',
        'department' => Html::escape($doctor->department ?: 'Sebészet'),
        'status' => $doctor->active ? $this->t('Aktív') : $this->t('Inaktív'),
        'operations' => [
          'data' => Link::fromTextAndUrl($this->t('Módosítás'), Url::fromRoute('muteti_seb.doctor_edit', ['doctor' => $doctor->id]))->toRenderable(),
        ],
      ];
    }

    return [
      '#attached' => ['library' => ['muteti_seb/surgery_board']],
      '#cache' => ['max-age' => 0],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-nav']],
        'add' => Link::fromTextAndUrl($this->t('+ Új orvos felvitele'), Url::fromRoute('muteti_seb.doctor_add'))->toRenderable(),
      ],
      'frame' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-table-frame']],
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Orvos neve'),
            $this->t('Színminta'),
            $this->t('Felhasználónév'),
            $this->t('Osztály'),
            $this->t('Állapot'),
            $this->t('Művelet'),
          ],
          '#rows' => $rows,
          '#empty' => $this->t('Még nincs felvett orvos.'),
          '#attributes' => ['class' => ['muteti-doctor-table']],
        ],
      ],
    ];
  }

}
