<?php

declare(strict_types=1);

namespace Drupal\ab_communication;

/**
 * One stored message, oldest to newest inside a page.
 */
final class StoredMessage {

  public function __construct(
    public readonly string $uuid,
    public readonly string $actorType,
    public readonly string $actorId,
    public readonly string $kind,
    public readonly string $body,
    public readonly ?string $replyTo,
    public readonly int $created,
    public readonly int $id,
  ) {}

}
