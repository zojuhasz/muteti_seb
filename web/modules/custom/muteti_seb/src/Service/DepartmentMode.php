<?php

namespace Drupal\muteti_seb\Service;

final class DepartmentMode {

  private static array $modes = [];

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

  public static function reset(): void {
    self::$modes = [];
  }

}
