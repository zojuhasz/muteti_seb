<?php

namespace Drupal\muteti_seb\Service;

use Drupal\Core\Datetime\DrupalDateTime;

final class Schedule {
  public const DAY_TYPES = [
    'HK' => ['B-Zu', 'B-2', 'B-3', 'B-NM', 'EP-1', 'Tu-1', 'Tu-2', 'Tu-3', 'Tu-4', 'S-1', 'S-2', 'Amb-1', 'Amb-2', 'Amb-3', 'Amb-4'],
    'SZCS' => ['B-Zu', 'B-2', 'B-3', 'B-NM', 'EP-1', 'EP-2', 'EP-3', 'Tu-1', 'Tu-2', 'S-1', 'S-2', 'Amb-1', 'Amb-2', 'Amb-3', 'Amb-4'],
    'P' => ['B-Zu', 'B-2', 'B-3', 'B-NM', 'EP-1', 'EP-2', 'Tu-1', 'Tu-2', 'Tu-3', 'S-1', 'S-2', 'Amb-1', 'Amb-2', 'Amb-3', 'Amb-4'],
    'SEMMI' => [],
  ];
  public const ROOMS = ['5', '6', '7', '8', 'A'];

  public static function defaultDayType(DrupalDateTime|\DateTimeInterface $date): string {
    return match ((int) $date->format('N')) { 1, 2 => 'HK', 3, 4 => 'SZCS', 5 => 'P', default => 'SEMMI' };
  }

  public static function departmentDayType(string $department, DrupalDateTime|\DateTimeInterface $date): string {
    $day = (int) $date->format('N');
    return match ($department) {
      'Urológia' => match (TRUE) {
        $day <= 4 => 'HKSZCS',
        $day === 5 => 'P',
        $day === 6 => 'SZ',
        default => 'V',
      },
      'Onkoradiológia' => $day <= 5 ? 'HKSZCSP' : 'SZV',
      default => self::defaultDayType($date),
    };
  }

  public static function departmentSlots(string $department, DrupalDateTime|\DateTimeInterface $date, ?string $day_type = NULL): array {
    $day_type ??= self::departmentDayType($department, $date);
    if ($department === 'Sebészet') {
      return self::DAY_TYPES[$day_type] ?? [];
    }
    if ($department === 'Urológia') {
      return match ($day_type) {
        'HKSZCS' => ['Ffi-1', 'Ffi-2', 'Ffi-3', 'N1', 'N2', 'TRUS-1', 'TRUS-2', 'Amb-Egyn-1', 'Amb-Egyn-2', 'Plusz-1', 'Plusz-2'],
        'P' => [NULL, NULL, NULL, NULL, NULL, 'Amb-Egyn-1', 'Amb-Egyn-2', 'Amb-3', 'Amb-4', 'Plusz-1', 'Plusz-2'],
        'SZ' => self::numberedSlots('ESWL', 1, 15, ''),
        'V' => ['Ffi-1', 'Ffi-2', 'Ffi-3', 'N1', 'N2', 'TRUS-1', 'TRUS-2', 'Plusz-1', 'Plusz-2'],
        default => [],
      };
    }
    if ($department === 'Onkoradiológia') {
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

  private static function numberedSlots(string $prefix, int $from, int $to, string $separator = ' - '): array {
    $slots = [];
    for ($number = $from; $number <= $to; $number++) {
      $slots[] = $prefix.$separator.$number;
    }
    return $slots;
  }
}
