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
}
