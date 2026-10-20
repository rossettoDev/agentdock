<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

use Drupal\ab_event\MaintenancePassInterface;

/**
 * Releases expired leases from the worker loop.
 *
 * A second pass finds nothing to record, so a restart does not
 * interrupt the same execution twice.
 */
final class LeaseMaintenancePass implements MaintenancePassInterface {

  public function __construct(
    private readonly ExecutionMaintenance $maintenance,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function run(): int {
    return $this->maintenance->recover();
  }

}
