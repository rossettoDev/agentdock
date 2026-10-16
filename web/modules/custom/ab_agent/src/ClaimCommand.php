<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * Identifiers the claim writer locks and checks.
 */
final class ClaimCommand {

  public function __construct(
    public readonly int $workspaceId,
    public readonly string $workspaceUuid,
    public readonly int $agentId,
    public readonly string $agentUuid,
    public readonly int $taskId,
    public readonly string $taskUuid,
    public readonly int $boardId,
    public readonly int $concurrencyLimit,
    public readonly string $tokenId,
  ) {}

}
