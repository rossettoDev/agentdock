<?php

declare(strict_types=1);

namespace Drupal\ab_event;

/**
 * Sleeps on the system clock between worker passes.
 */
final class ClockSleeper implements WorkerSleeperInterface {

  /**
   * {@inheritdoc}
   */
  public function wait(int $seconds, callable $stopped): void {
    $left = $seconds;
    while ($left > 0 && !$stopped()) {
      sleep(1);
      $left--;
    }
  }

}
