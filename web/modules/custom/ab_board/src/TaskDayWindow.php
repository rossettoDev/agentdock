<?php

declare(strict_types=1);

namespace Drupal\ab_board;

/**
 * Decides which tasks belong on the Hoje filter for one instant.
 *
 * The result is derived. Calling it does not store a day or move a task.
 */
final class TaskDayWindow {

  /**
   * Reports whether the task is overdue, due today, or in progress.
   *
   * @param int|null $due
   *   Stored UTC instant, or NULL when the task has no due date.
   * @param string $status
   *   Stored status.
   * @param string $timezone
   *   Workspace timezone used to find the end of the day.
   * @param int $now
   *   Instant being evaluated, as a UTC timestamp.
   */
  public function includes(?int $due, string $status, string $timezone, int $now): bool {
    if ($status === 'in_progress') {
      return TRUE;
    }
    if ($status === 'cancelled' || $due === NULL) {
      return FALSE;
    }
    try {
      $zone = new \DateTimeZone($timezone);
    }
    catch (\Exception) {
      return FALSE;
    }
    $moment = (new \DateTimeImmutable('@' . $now))->setTimezone($zone);
    $end = $moment->setTime(23, 59, 59);
    return $due <= $end->getTimestamp();
  }

}
