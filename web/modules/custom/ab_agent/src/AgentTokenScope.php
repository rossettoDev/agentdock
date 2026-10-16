<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

use Drupal\ab_core\Authorization\WorkspacePermission;

/**
 * Scopes a bearer token may carry.
 *
 * A scope is not a workspace permission. The operation gate intersects
 * it with the board grant.
 */
final class AgentTokenScope {

  public const BOARDS_READ = 'boards:read';

  public const TASKS_READ = 'tasks:read';

  public const TASKS_WRITE = 'tasks:write';

  public const TASKS_CLAIM = 'tasks:claim';

  public const EXECUTIONS_WRITE = 'executions:write';

  public const MESSAGES_READ = 'messages:read';

  public const MESSAGES_WRITE = 'messages:write';

  public const EVENTS_READ = 'events:read';

  /**
   * Returns every scope a token may store.
   *
   * @return string[]
   *   Scope ids.
   */
  public static function all(): array {
    return [
      self::BOARDS_READ,
      self::TASKS_READ,
      self::TASKS_WRITE,
      self::TASKS_CLAIM,
      self::EXECUTIONS_WRITE,
      self::MESSAGES_READ,
      self::MESSAGES_WRITE,
      self::EVENTS_READ,
    ];
  }

  /**
   * Maps a scope to the board grant it still requires.
   */
  public static function grantFor(string $scope): string {
    return match ($scope) {
      self::BOARDS_READ,
      self::TASKS_READ,
      self::MESSAGES_READ,
      self::EVENTS_READ => WorkspacePermission::READ,
      self::TASKS_WRITE,
      self::MESSAGES_WRITE,
      self::TASKS_CLAIM,
      self::EXECUTIONS_WRITE => WorkspacePermission::WRITE,
      default => throw new \InvalidArgumentException('Unknown token scope.'),
    };
  }

}
