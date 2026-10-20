<?php

declare(strict_types=1);

namespace Drupal\ab_event;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Records that a committed event is published for synchronization.
 *
 * The row is the projection. The unique key keeps a second worker from
 * storing another one.
 */
final class SyncDestination implements OutboxDestinationInterface {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return EventStoreInterface::DESTINATION_SYNC;
  }

  /**
   * {@inheritdoc}
   */
  public function deliver(array $event): void {
    try {
      $this->database->insert('ab_outbox_delivery')
        ->fields([
          'event_id' => $event['event_id'],
          'destination' => $this->id(),
          'delivered_at' => (int) $event['created'],
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      // The publication is already stored.
    }
  }

}
