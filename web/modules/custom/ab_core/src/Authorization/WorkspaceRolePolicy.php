<?php

declare(strict_types=1);

namespace Drupal\ab_core\Authorization;

/**
 * Maps an active workspace role to permissions.
 *
 * Roles are membership records. Drupal global roles do not grant these
 * permissions.
 */
final class WorkspaceRolePolicy {

  /**
   * Returns the permissions for a membership role and status.
   *
   * @return string[]
   *   Permission ids. Empty when the membership is not active.
   */
  public function permissions(string $role, string $status): array {
    if ($status !== 'active') {
      return [];
    }
    $member = [
      WorkspacePermission::READ,
      WorkspacePermission::WRITE,
      WorkspacePermission::REVIEW,
    ];
    $admin = [
      ...$member,
      WorkspacePermission::MANAGE_MEMBERS,
      WorkspacePermission::MANAGE_AGENTS,
      WorkspacePermission::MANAGE_BOARDS,
    ];
    return match ($role) {
      'viewer' => [WorkspacePermission::READ],
      'member' => $member,
      'admin' => $admin,
      'owner' => [
        ...$admin,
        WorkspacePermission::MANAGE_WORKSPACE,
        WorkspacePermission::MANAGE_CREDENTIALS,
        WorkspacePermission::TRANSFER_OWNERSHIP,
      ],
      default => [],
    };
  }

}
