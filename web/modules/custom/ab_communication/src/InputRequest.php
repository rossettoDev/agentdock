<?php

declare(strict_types=1);

namespace Drupal\ab_communication;

/**
 * The public result of asking a human for information.
 *
 * The question text and the lease secret are not on this value.
 */
final class InputRequest {

  public function __construct(
    public readonly string $requestUuid,
    public readonly string $messageUuid,
    public readonly string $executionUuid,
  ) {}

}
