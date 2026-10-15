<?php

declare(strict_types=1);

namespace Drupal\ab_event;

/**
 * Event persisted inside the caller transaction.
 */
final class StoredEvent {

  public function __construct(
    public readonly string $eventId,
    public readonly string $workspaceId,
    public readonly int $sequence,
    public readonly int $created,
  ) {}

}
