<?php

declare(strict_types=1);

namespace Drupal\ab_core\Authorization;

/**
 * Authenticated actor inside one workspace.
 *
 * The actor id comes from authentication. Callers do not supply it.
 */
final class ActorContext {

  public const TYPE_HUMAN = 'human';

  public const TYPE_AGENT = 'agent';

  public const TYPE_SYSTEM = 'system';

  /**
   * Creates an authenticated actor context.
   *
   * @param string $actorType
   *   One of human, agent or system.
   * @param string $actorId
   *   Opaque identifier taken from authentication.
   * @param string $workspaceId
   *   Lowercase workspace UUID.
   * @param string[] $permissions
   *   Effective permissions for this workspace.
   * @param string|null $tokenId
   *   Agent token identifier. Humans do not carry one.
   */
  public function __construct(
    public readonly string $actorType,
    public readonly string $actorId,
    public readonly string $workspaceId,
    private readonly array $permissions,
    public readonly ?string $tokenId = NULL,
  ) {
    if (!in_array($actorType, [self::TYPE_HUMAN, self::TYPE_AGENT, self::TYPE_SYSTEM], TRUE)) {
      throw new \InvalidArgumentException('Actor type must be human, agent or system.');
    }
    if ($actorId === '') {
      throw new \InvalidArgumentException('Actor id is required.');
    }
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $workspaceId) !== 1) {
      throw new \InvalidArgumentException('Workspace id must be a lowercase UUID.');
    }
    if ($actorType === self::TYPE_HUMAN && $tokenId !== NULL) {
      throw new \InvalidArgumentException('A human actor has no token.');
    }
    $unknown = array_diff($permissions, WorkspacePermission::all());
    if ($unknown !== []) {
      throw new \InvalidArgumentException('Unknown workspace permission.');
    }
  }

  /**
   * Reports whether this actor holds a workspace permission.
   */
  public function allows(string $permission): bool {
    return in_array($permission, $this->permissions, TRUE);
  }

}
