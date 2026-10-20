<?php

declare(strict_types=1);

namespace Drupal\ab_api;

use Drupal\ab_event\MaintenancePassInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Drops idempotency replays whose retention has ended.
 *
 * A row still inside its window stays, including one the request has
 * not finished. The audit of the domain change is the event, not this row.
 */
final class ReplayCleaner implements MaintenancePassInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function run(): int {
    return (int) $this->database->delete('ab_idempotency')
      ->condition('expires_at', $this->time->getRequestTime(), '<=')
      ->execute();
  }

}
