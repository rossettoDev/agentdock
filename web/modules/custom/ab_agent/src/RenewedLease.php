<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * A heartbeat that extended the current lease.
 *
 * The secret is not repeated.
 */
final class RenewedLease {

  public function __construct(
    public readonly string $executionUuid,
    public readonly int $leaseExpiresAt,
    public readonly int $generation,
  ) {}

}
