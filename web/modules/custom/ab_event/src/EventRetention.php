<?php

declare(strict_types=1);

namespace Drupal\ab_event;

use Drupal\Core\Site\Settings;

/**
 * How long a synchronization event stays readable.
 */
final class EventRetention {

  /**
   * Default window, in seconds. Thirty days.
   */
  public const SECONDS = 2592000;

  /**
   * Reads the retention window. A setting overrides the default.
   */
  public static function seconds(): int {
    $value = Settings::get('ab_event_retention', self::SECONDS);
    if (is_int($value) && $value >= 0) {
      return $value;
    }
    if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
      return (int) $value;
    }
    return self::SECONDS;
  }

}
