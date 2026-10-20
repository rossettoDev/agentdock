<?php

declare(strict_types=1);

namespace Drupal\ab_event;

/**
 * How much of the transient feed and the expired audit a pass removed.
 */
final class PurgeResult {

  public function __construct(
    public readonly int $events,
    public readonly int $outbox,
  ) {}

}
