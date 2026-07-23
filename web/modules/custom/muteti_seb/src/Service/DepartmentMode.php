<?php

namespace Drupal\muteti_seb\Service;

final class DepartmentMode {

  private static array $modes = [];
  private static array $features = [];

  public static function get(string $department): string {
    if (!isset(self::$modes[$department])) {
      $mode = FALSE;
      if (\Drupal::database()->schema()->tableExists('muteti_department_config')) {
        $mode = \Drupal::database()->select('muteti_department_config', 'd')
          ->fields('d', ['mode'])
          ->condition('name', $department)
          ->execute()
          ->fetchField();
      }
      self::$modes[$department] = $mode ?: match ($department) {
        'Urológia' => 'urol',
        'Onkoradiológia' => 'onko',
        default => 'seb',
      };
    }
    return self::$modes[$department];
  }

  public static function featureEnabled(string $department, string $feature): bool {
    if (!in_array($feature, ['availability_enabled', 'away_enabled'], TRUE)) {
      throw new \InvalidArgumentException('Ismeretlen osztályfunkció: '.$feature);
    }
    $cache_key = $department.':'.$feature;
    if (!array_key_exists($cache_key, self::$features)) {
      $enabled = TRUE;
      $schema = \Drupal::database()->schema();
      if ($schema->tableExists('muteti_department_config') && $schema->fieldExists('muteti_department_config', $feature)) {
        $value = \Drupal::database()->select('muteti_department_config', 'd')
          ->fields('d', [$feature])
          ->condition('name', $department)
          ->execute()
          ->fetchField();
        if ($value !== FALSE) {
          $enabled = (bool) $value;
        }
      }
      self::$features[$cache_key] = $enabled;
    }
    return self::$features[$cache_key];
  }

  public static function reset(): void {
    self::$modes = [];
    self::$features = [];
  }

}
