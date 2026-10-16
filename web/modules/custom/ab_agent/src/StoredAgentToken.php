<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * A stored credential without its secret.
 */
final class StoredAgentToken {

  public function __construct(
    public readonly string $publicId,
    public readonly string $agentUuid,
    public readonly string $workspaceUuid,
    public readonly string $scopes,
    public readonly int $expiresAt,
    public readonly ?int $revokedAt,
    public readonly ?int $lastUsedAt,
    public readonly bool $agentEnabled,
    private readonly string $secretHash,
  ) {}

  /**
   * Checks the presented secret against the stored hash.
   */
  public function matches(string $secret): bool {
    return hash_equals($this->secretHash, hash('sha256', $secret));
  }

  /**
   * Reports whether the scope was granted to this token.
   */
  public function hasScope(string $scope): bool {
    return in_array($scope, explode(',', $this->scopes), TRUE);
  }

}
