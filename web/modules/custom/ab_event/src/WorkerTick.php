<?php

declare(strict_types=1);

namespace Drupal\ab_event;

/**
 * Counts from one worker pass. No identifiers and no secrets.
 */
final class WorkerTick {

  public function __construct(
    public readonly int $delivered,
    public readonly int $failed,
    public readonly int $dead,
    public readonly int $maintenance,
    public readonly int $purgedEvents,
    public readonly int $purgedOutbox,
    public readonly ?int $outboxAge,
  ) {}

}
