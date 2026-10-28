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
    $end = $this->endOfDay($timezone, $now);
    return $end !== NULL && $due <= $end;
  }

  /**
   * Returns the first second of the workspace day, or NULL.
   *
   * @param string $timezone
   *   Workspace timezone.
   * @param int $now
   *   Instant being evaluated, as a UTC timestamp.
   */
  public function startOfDay(string $timezone, int $now): ?int {
    return $this->boundary($timezone, $now, 0, 0, 0);
  }

  /**
   * Returns the last second of the workspace day, or NULL.
   *
   * @param string $timezone
   *   Workspace timezone.
   * @param int $now
   *   Instant being evaluated, as a UTC timestamp.
   */
  public function endOfDay(string $timezone, int $now): ?int {
    return $this->boundary($timezone, $now, 23, 59, 59);
  }

  /**
   * Returns one boundary of the workspace day, or NULL.
   *
   * @param string $timezone
   *   Workspace timezone.
   * @param int $now
   *   Instant being evaluated, as a UTC timestamp.
   * @param int $hour
   *   Hour of the boundary.
   * @param int $minute
   *   Minute of the boundary.
   * @param int $second
   *   Second of the boundary.
   */
  private function boundary(string $timezone, int $now, int $hour, int $minute, int $second): ?int {
    try {
      $zone = new \DateTimeZone($timezone);
    }
    catch (\Exception) {
      return NULL;
    }
    $moment = (new \DateTimeImmutable('@' . $now))->setTimezone($zone);
    return $moment->setTime($hour, $minute, $second)->getTimestamp();
  }

}
