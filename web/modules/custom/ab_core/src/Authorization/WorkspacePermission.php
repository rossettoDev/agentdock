<?php

declare(strict_types=1);

namespace Drupal\ab_core\Authorization;

/**
 * Names the workspace permissions granted by a membership role.
 */
final class WorkspacePermission {

  public const READ = 'read';

  public const WRITE = 'write';

  public const REVIEW = 'review';

  public const MANAGE_MEMBERS = 'membership.manage';

  public const TRANSFER_OWNERSHIP = 'membership.transfer';

  public const MANAGE_WORKSPACE = 'workspace.manage';

  public const MANAGE_AGENTS = 'agent.manage';

  public const MANAGE_CREDENTIALS = 'credential.manage';

  public const MANAGE_BOARDS = 'board.manage';

  /**
   * Returns every permission the policy may grant.
   *
   * @return string[]
   *   Permission ids.
   */
  public static function all(): array {
    return [
      self::READ,
      self::WRITE,
      self::REVIEW,
      self::MANAGE_MEMBERS,
      self::TRANSFER_OWNERSHIP,
      self::MANAGE_WORKSPACE,
      self::MANAGE_AGENTS,
      self::MANAGE_CREDENTIALS,
      self::MANAGE_BOARDS,
    ];
  }

}
