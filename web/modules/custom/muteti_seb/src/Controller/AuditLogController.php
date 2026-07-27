<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
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
    $query = $this->database->select('muteti_audit_log', 'l');
    $entries = $query
      ->extend(PagerSelectExtender::class)
      ->fields('l')
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC')
      ->limit(1000)
      ->execute()
      ->fetchAll();
    $days = [];
    foreach ($entries as $entry) {
      $day = date('Y-m-d', (int) $entry->created);
      $days[$day]['entries'][] = $entry;
      $department = mb_strtolower(trim((string) $entry->department), 'UTF-8');
      $department_key = match ($department) {
        'sebészet', 'sebeszet' => 'seb',
        'urológia', 'urologia' => 'urol',
        'onkoradiológia', 'onkoradiologia', 'onkorad' => 'onko',
        default => NULL,
      };
      if ($department_key) {
        $days[$day]['counts'][$department_key] = ($days[$day]['counts'][$department_key] ?? 0) + 1;
      }
    }
    $items = [];
    foreach ($days as $day => $group) {
      foreach ($group['entries'] as $entry) {
        $parts = array_filter([
          $entry->username,
          $entry->department,
          $entry->appointment_date,
          $entry->slot_type,
          $entry->patient_name,
          $entry->patient_reference,
          $entry->action,
          date('Y-m-d H:i:s', (int) $entry->created),
          '('.$entry->id.')',
        ], static fn($value): bool => (string) $value !== '');
        $items[] = ['#markup' => Html::escape(implode(' ', $parts))];
      }
      $counts = $group['counts'] ?? [];
      $summary = sprintf(
        'Napi záróösszesítés: Sebészet: %d, Urológia: %d, Onkorad: %d',
        $counts['seb'] ?? 0,
        $counts['urol'] ?? 0,
        $counts['onko'] ?? 0,
      );
      $items[] = [
        '#markup' => '<span class="muteti-audit-daily-summary">'.Html::escape($summary).'</span>',
      ];
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
      'pager' => ['#type' => 'pager'],
    ];
  }

}
