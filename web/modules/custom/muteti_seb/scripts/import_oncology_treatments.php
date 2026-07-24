<?php

/**
 * @file
 * Imports and refreshes D7 `kezel_s` nodes as Drupal 11 treatments.
 *
 * Run:
 * vendor/bin/drush php:script \
 *   web/modules/custom/muteti_seb/scripts/import_oncology_treatments.php
 */

use Drupal\Core\Database\Database;

$source = Database::getConnection('default', 'd7_live');
$storage = \Drupal::entityTypeManager()->getStorage('node');
$rows = $source->select('node', 'n')
  ->fields('n', ['nid', 'title', 'status'])
  ->condition('type', 'kezel_s')
  ->orderBy('nid')
  ->execute()
  ->fetchAll();

$source_ids = [];
$created = 0;
$updated = 0;
foreach ($rows as $row) {
  $legacy_nid = (int) $row->nid;
  $title = trim((string) $row->title);
  if ($legacy_nid <= 0 || $title === '') {
    continue;
  }
  $source_ids[] = $legacy_nid;
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'muteti_oncology_treatment')
    ->condition('field_muteti_treat_legacy_nid', $legacy_nid)
    ->range(0, 1)
    ->execute();
  $node = $ids ? $storage->load(reset($ids)) : NULL;
  if (!$node) {
    $node = $storage->create([
      'type' => 'muteti_oncology_treatment',
      'uid' => 1,
      'field_muteti_treat_legacy_nid' => $legacy_nid,
    ]);
    $created++;
  }
  else {
    $updated++;
  }
  $node->setTitle($title);
  $node->setPublished((bool) $row->status);
  $node->save();
}

$disabled = 0;
$managed_ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'muteti_oncology_treatment')
  ->exists('field_muteti_treat_legacy_nid')
  ->execute();
foreach ($storage->loadMultiple($managed_ids) as $node) {
  $legacy_nid = (int) $node->get('field_muteti_treat_legacy_nid')->value;
  if ($legacy_nid > 0 && !in_array($legacy_nid, $source_ids, TRUE) && $node->isPublished()) {
    $node->setUnpublished();
    $node->save();
    $disabled++;
  }
}

print "D7 kezelések: ".count($rows).PHP_EOL;
print "Új D11 tartalom: {$created}".PHP_EOL;
print "Frissített D11 tartalom: {$updated}".PHP_EOL;
print "D7-ből törölt, ezért letiltott: {$disabled}".PHP_EOL;
