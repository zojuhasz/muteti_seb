<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class AuditLogController extends ControllerBase {

  public function __construct(private readonly Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function access(AccountInterface $account): AccessResult {
    $allowed = (int) $account->id() === 1 || mb_strtolower($account->getAccountName(), 'UTF-8') === 'jz';
    return AccessResult::allowedIf($allowed)->addCacheContexts(['user']);
  }

  public function listing(): array {
    $entries = $this->database->select('muteti_audit_log', 'l')
      ->fields('l')
      ->orderBy('id', 'DESC')
      ->range(0, 1000)
      ->execute();
    $items = [];
    foreach ($entries as $entry) {
      $parts = array_filter([
        $entry->username,
        $entry->department,
        $entry->appointment_date,
        $entry->slot_type,
        $entry->patient_reference,
        $entry->action,
        date('Y-m-d H:i:s', (int) $entry->created),
        '('.$entry->id.')',
      ], static fn($value): bool => (string) $value !== '');
      $items[] = ['#markup' => Html::escape(implode(' ', $parts))];
    }
    return [
      '#attached' => ['library' => ['muteti_seb/surgery_board']],
      '#cache' => ['max-age' => 0],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-nav', 'muteti-log-actions']],
        'print' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => 'Napló nyomtatása',
          '#attributes' => ['type' => 'button', 'onclick' => 'window.print()'],
        ],
      ],
      'log' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#empty' => 'A napló még üres.',
        '#attributes' => ['class' => ['muteti-audit-log']],
      ],
    ];
  }

}
