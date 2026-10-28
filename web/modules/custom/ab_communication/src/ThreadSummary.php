<?php

declare(strict_types=1);

namespace Drupal\ab_communication;

/**
 * A conversation the caller is allowed to open.
 */
final class ThreadSummary {

  public function __construct(
    public readonly string $threadUuid,
    public readonly string $boardUuid,
    public readonly string $boardLabel,
    public readonly ?string $taskUuid,
    public readonly string $taskLabel,
    public readonly string $excerpt,
    public readonly int $created,
  ) {}

}
