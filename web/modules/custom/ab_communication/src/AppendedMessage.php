<?php

declare(strict_types=1);

namespace Drupal\ab_communication;

/**
 * A message inserted inside the caller's transaction.
 *
 * The body stays in the table. This value does not carry it.
 */
final class AppendedMessage {

  public function __construct(
    public readonly int $messageId,
    public readonly string $messageUuid,
    public readonly string $threadUuid,
    public readonly string $kind,
  ) {}

}
