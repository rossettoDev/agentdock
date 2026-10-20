<?php

declare(strict_types=1);

namespace Drupal\ab_event;

/**
 * One maintenance step the worker runs after the outbox pass.
 */
interface MaintenancePassInterface {

  /**
   * Runs the step and returns how many items it handled.
   */
  public function run(): int;

}
