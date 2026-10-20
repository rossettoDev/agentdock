<?php

declare(strict_types=1);

namespace Drupal\ab_event;

/**
 * Publishes one committed event to a single destination.
 */
interface OutboxDestinationInterface {

  /**
   * Returns the destination name stored on the outbox row.
   */
  public function id(): string;

  /**
   * Stores the publication. A second call for the same event is a no-op.
   *
   * @param array<string, string> $event
   *   Event identity. The payload is not included.
   */
  public function deliver(array $event): void;

}
