<?php

declare(strict_types=1);

namespace Drupal\ab_communication;

/**
 * One notification the current person is still allowed to see.
 *
 * The message body is not part of this value.
 */
final class NotificationItem {

  public function __construct(
    public readonly string $uuid,
    public readonly bool $read,
    public readonly string $workspaceLabel,
    public readonly string $workspaceUuid,
  ) {}

}
