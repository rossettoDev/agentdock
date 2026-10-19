<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * Identifiers a lease mutation locks and checks.
 *
 * The lease secret is already hashed. The plaintext is not here.
 */
final class LeaseCommand {

  public function __construct(
    public readonly int $workspaceId,
    public readonly string $workspaceUuid,
    public readonly int $agentId,
    public readonly string $agentUuid,
    public readonly int $executionId,
    public readonly string $executionUuid,
    public readonly int $taskId,
    public readonly string $taskUuid,
    public readonly int $boardId,
    public readonly string $tokenId,
    public readonly string $secretHash,
    public readonly int $generation,
    public readonly string $failureCode,
    public readonly string $note = '',
    public readonly string $summary = '',
    public readonly array $links = [],
  ) {}

}
