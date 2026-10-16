<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * The one copy of a newly issued bearer token.
 *
 * Callers show this value once. It is not stored and has no string cast.
 */
final class IssuedAgentToken {

  public function __construct(
    public readonly string $publicId,
    public readonly string $token,
    public readonly int $expiresAt,
  ) {}

}
