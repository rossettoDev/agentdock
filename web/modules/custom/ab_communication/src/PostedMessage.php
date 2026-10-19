<?php

declare(strict_types=1);

namespace Drupal\ab_communication;

/**
 * Identifiers returned after a message is stored.
 *
 * The body stays in the table. This value has no string cast.
 */
final class PostedMessage {

  public function __construct(
    public readonly string $messageUuid,
    public readonly string $threadUuid,
    public readonly string $kind,
  ) {}

}
