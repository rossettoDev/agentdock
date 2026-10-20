<?php

declare(strict_types=1);

namespace Drupal\ab_event;

/**
 * Waits between worker passes and returns early when asked to stop.
 */
interface WorkerSleeperInterface {

  /**
   * Waits up to the interval, in one-second slices.
   *
   * @param int $seconds
   *   How long to wait.
   * @param callable $stopped
   *   Returns TRUE when the worker should leave the wait.
   */
  public function wait(int $seconds, callable $stopped): void;

}
