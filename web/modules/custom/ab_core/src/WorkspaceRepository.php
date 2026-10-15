<?php

declare(strict_types=1);

namespace Drupal\ab_core;

use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Lists and addresses workspaces visible to the current user.
 */
final class WorkspaceRepository {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MembershipScope $scope,
    private readonly UuidLookup $uuidLookup,
  ) {}

  /**
   * Returns workspaces where the current user has an active membership.
   *
   * @return \Drupal\ab_core\Entity\Workspace[]
   *   Visible workspaces.
   */
  public function accessibleWorkspaces(): array {
    $ids = $this->scope->activeWorkspaceIds($this->currentUser->getAccount());
    if ($ids === []) {
      return [];
    }
    $workspaces = [];
    $storage = $this->entityTypeManager->getStorage('ab_workspace');
    foreach ($storage->loadMultiple($ids) as $workspace) {
      if ($workspace instanceof Workspace) {
        $workspaces[] = $workspace;
      }
    }
    return $workspaces;
  }

  /**
   * Counts workspaces where the current user has an active membership.
   */
  public function countAccessibleWorkspaces(): int {
    $account = $this->currentUser->getAccount();
    return count($this->scope->activeWorkspaceIds($account));
  }

  /**
   * Returns a workspace the current user can read, addressed by UUID.
   */
  public function loadAccessibleByUuid(string $uuid): ?Workspace {
    $workspace = $this->load($uuid);
    if (!$workspace instanceof Workspace || !$this->canRead($workspace)) {
      return NULL;
    }
    return $workspace;
  }

  /**
   * Renames a workspace the current user can manage, addressed by UUID.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The UUID is missing or outside the caller's scope.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller can see the workspace but cannot change it.
   */
  public function renameAccessibleByUuid(
    string $uuid,
    string $name,
  ): Workspace {
    $workspace = $this->load($uuid);
    if (!$workspace instanceof Workspace || !$this->canRead($workspace)) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
    $account = $this->currentUser->getAccount();
    $can_manage = $this->scope->allows(
      $account,
      $workspace,
      WorkspacePermission::MANAGE_WORKSPACE,
    );
    if (!$can_manage) {
      throw new ScopedOperationDeniedException('Workspace cannot be changed.');
    }
    $workspace->set('name', $name);
    $workspace->save();
    return $workspace;
  }

  /**
   * Builds a cacheable listing of visible workspace names.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function workspaceListing(): array {
    $labels = [];
    foreach ($this->accessibleWorkspaces() as $workspace) {
      $labels[] = (string) $workspace->label();
    }
    return [
      '#markup' => implode("\n", $labels),
      '#cache' => [
        'contexts' => ['user', 'ab_memberships'],
        'tags' => ['ab_membership_list:' . $this->currentUser->id()],
      ],
    ];
  }

  /**
   * Loads a workspace by UUID, ignoring scope.
   */
  private function load(string $uuid): ?Workspace {
    $workspace = $this->uuidLookup->load('ab_workspace', $uuid);
    return $workspace instanceof Workspace ? $workspace : NULL;
  }

  /**
   * Reports whether the current user can read the workspace.
   */
  private function canRead(Workspace $workspace): bool {
    return $this->scope->allows(
      $this->currentUser->getAccount(),
      $workspace,
      WorkspacePermission::READ,
    );
  }

}
