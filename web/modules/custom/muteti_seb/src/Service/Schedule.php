<?php

namespace Drupal\muteti_seb\Service;

use Drupal\Core\Datetime\DrupalDateTime;

final class Schedule {
  private static array $definitionCache = [];
  public const DAY_TYPES = [
    'HK' => ['B-Zu', 'B-2', 'B-3', 'B-NM', 'EP-1', 'Tu-1', 'Tu-2', 'Tu-3', 'Tu-4', 'S-1', 'S-2', 'Amb-1', 'Amb-2', 'Amb-3', 'Amb-4'],
    'SZCS' => ['B-Zu', 'B-2', 'B-3', 'B-NM', 'EP-1', 'EP-2', 'EP-3', 'Tu-1', 'Tu-2', 'S-1', 'S-2', 'Amb-1', 'Amb-2', 'Amb-3', 'Amb-4'],
    'P' => ['B-Zu', 'B-2', 'B-3', 'B-NM', 'EP-1', 'EP-2', 'Tu-1', 'Tu-2', 'Tu-3', 'S-1', 'S-2', 'Amb-1', 'Amb-2', 'Amb-3', 'Amb-4'],
    'SEMMI' => [],
  ];
  public const ROOMS = ['5', '6', '7', '8', 'A'];

  public static function departmentRooms(string $department): array {
    return match (DepartmentMode::get($department)) {
      'urol' => ['1', '2'],
      default => self::ROOMS,
    };
  }

  public static function defaultDayType(DrupalDateTime|\DateTimeInterface $date): string {
    return match ((int) $date->format('N')) { 1, 2 => 'HK', 3, 4 => 'SZCS', 5 => 'P', default => 'SEMMI' };
  }

  public static function departmentDayType(string $department, DrupalDateTime|\DateTimeInterface $date): string {
    $day = (int) $date->format('N');
    $date_string = $date->format('Y-m-d');
    foreach (self::definitions($department) as $definition) {
      if (in_array($day, $definition['weekdays'], TRUE) && self::definitionApplies($definition, $date_string)) {
        return $definition['code'];
      }
    }
    return match (DepartmentMode::get($department)) {
      'urol' => match (TRUE) {
        $day <= 4 => 'HKSZCS',
        $day === 5 => 'P',
        $day === 6 => 'SZ',
        default => 'V',
      },
      'onko' => $day <= 5 ? 'HKSZCSP' : 'SZV',
      default => self::defaultDayType($date),
    };
  }

  public static function departmentSlots(string $department, DrupalDateTime|\DateTimeInterface $date, ?string $day_type = NULL): array {
    $day_type ??= self::departmentDayType($department, $date);
    $date_string = $date->format('Y-m-d');
    foreach (self::definitions($department) as $definition) {
      if ($definition['code'] === $day_type && self::definitionApplies($definition, $date_string)) {
        if (trim($definition['slots']) === '') {
          return [];
        }
        return array_map(
          static fn(string $slot): ?string => trim($slot) === '' ? NULL : trim($slot),
          explode('%', $definition['slots'])
        );
      }
    }
    $mode = DepartmentMode::get($department);
    if ($mode === 'seb') {
      return self::DAY_TYPES[$day_type] ?? [];
    }
    if ($mode === 'urol') {
      return match ($day_type) {
        'HKSZCS' => ['Ffi-1', 'Ffi-2', 'Ffi-3', 'N1', 'N2', 'TRUS-1', 'TRUS-2', 'Amb-Egyn-1', 'Amb-Egyn-2', 'Plusz-1', 'Plusz-2'],
        'P' => [NULL, NULL, NULL, NULL, NULL, 'Amb-Egyn-1', 'Amb-Egyn-2', 'Amb-3', 'Amb-4', 'Plusz-1', 'Plusz-2'],
        'SZ' => self::numberedSlots('ESWL', 1, 15, ''),
        'V' => ['Ffi-1', 'Ffi-2', 'Ffi-3', 'N1', 'N2', 'TRUS-1', 'TRUS-2', 'Plusz-1', 'Plusz-2'],
        default => [],
      };
    }
    if ($mode === 'onko') {
      if ($day_type === 'SZV') {
        return self::numberedSlots('Fekv.', 1, 15);
      }
      if ($day_type !== 'HKSZCSP') {
        return [];
      }
      $date_string = $date->format('Y-m-d');
      if ($date_string >= '2025-03-04') {
        return array_merge(
          self::numberedSlots('4-6h', 1, 10),
          self::numberedSlots('2-3h', 1, 15),
          self::numberedSlots('1h', 1, 15),
          self::numberedSlots('Fejh.', 1, 4),
          self::numberedSlots('sc Herc.', 1, 15),
          self::numberedSlots('Fekv.', 1, 15),
        );
      }
      if ($date_string >= '2025-02-03') {
        return array_merge(
          self::numberedSlots('4-6h', 1, 13),
          self::numberedSlots('2-3h', 1, 18),
          self::numberedSlots('1h', 1, 18),
          self::numberedSlots('Fejh.', 1, 4),
          self::numberedSlots('Fekv.', 1, 15),
        );
      }
      return array_merge(
        self::numberedSlots('4-6h', 1, 10),
        self::numberedSlots('2-3h', 1, 15),
        self::numberedSlots('1h', 1, 15),
        self::numberedSlots('Fejh.', 1, 4),
        self::numberedSlots('sc Herc.', 1, 15),
        self::numberedSlots('Fekv.', 1, 15),
      );
    }
    return [];
  }

  public static function departmentDayTypes(string $department): array {
    $definitions = self::definitions($department);
    if ($definitions) {
      $codes = array_values(array_unique(array_column($definitions, 'code')));
      if (!in_array('SEMMI', $codes, TRUE)) {
        $codes[] = 'SEMMI';
      }
      return $codes;
    }
    return match (DepartmentMode::get($department)) {
      'urol' => ['HKSZCS', 'P', 'SZ', 'V', 'SEMMI'],
      'onko' => ['HKSZCSP', 'SZV', 'SEMMI'],
      default => array_keys(self::DAY_TYPES),
    };
  }

  private static function numberedSlots(string $prefix, int $from, int $to, string $separator = ' - '): array {
    $slots = [];
    for ($number = $from; $number <= $to; $number++) {
      $slots[] = $prefix.$separator.$number;
    }
    return $slots;
  }

  private static function definitions(string $department): array {
    if (array_key_exists($department, self::$definitionCache)) {
      return self::$definitionCache[$department];
    }
    if (!\Drupal::moduleHandler()->moduleExists('node')) {
      return self::$definitionCache[$department] = [];
    }
    if (!\Drupal\node\Entity\NodeType::load('muteti_day_type_definition')) {
      return self::$definitionCache[$department] = [];
    }
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'muteti_day_type_definition')
      ->condition('status', 1)
      ->condition('field_muteti_daytype_department', $department)
      ->execute();
    $definitions = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $definitions[] = [
        'code' => trim((string) $node->get('field_muteti_daytype_code')->value),
        'weekdays' => array_map('intval', array_column($node->get('field_muteti_daytype_weekdays')->getValue(), 'value')),
        'slots' => (string) $node->get('field_muteti_daytype_slots')->value,
        'from' => trim((string) $node->get('field_muteti_daytype_from')->value),
        'until' => trim((string) $node->get('field_muteti_daytype_until')->value),
      ];
    }
    usort($definitions, static fn(array $a, array $b): int => strcmp($b['from'], $a['from']));
    return self::$definitionCache[$department] = $definitions;
  }

  private static function definitionApplies(array $definition, string $date): bool {
    return ($definition['from'] === '' || $date >= $definition['from'])
      && ($definition['until'] === '' || $date <= $definition['until']);
  }
}
