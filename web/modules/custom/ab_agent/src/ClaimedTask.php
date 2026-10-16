<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * The only copy of a successful claim.
 *
 * The lease token is not stored and has no string cast.
 */
final class ClaimedTask {

  public function __construct(
    public readonly string $executionUuid,
    public readonly string $taskUuid,
    public readonly string $leaseToken,
    public readonly int $leaseExpiresAt,
    public readonly int $taskVersion,
    public readonly int $generation = 1,
  ) {}

}
