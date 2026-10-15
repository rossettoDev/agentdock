<?php

declare(strict_types=1);

namespace Drupal\ab_core;

use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Reads and updates memberships addressed by public UUID.
 */
final class MembershipRepository {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MembershipScope $scope,
    private readonly UuidLookup $uuidLookup,
    private readonly MembershipManager $membershipManager,
  ) {}

  /**
   * Returns a membership the current user can read.
   */
  public function loadAccessibleByUuid(string $uuid): ?Membership {
    $loaded = $this->load($uuid);
    if ($loaded === NULL) {
      return NULL;
    }
    [$membership, $workspace] = $loaded;
    if (!$this->canRead($workspace)) {
      return NULL;
    }
    return $membership;
  }

  /**
   * Changes the role of a membership the current user can administer.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The UUID is missing or outside the caller's scope.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller can see the membership but cannot change it.
   */
  public function updateRole(string $uuid, string $role): Membership {
    $loaded = $this->load($uuid);
    if ($loaded === NULL || !$this->canRead($loaded[1])) {
      throw new ScopedResourceNotFoundException('Membership was not found.');
    }
    [$membership, $workspace] = $loaded;
    return $this->membershipManager->changeRole($workspace, $membership->uuid(), $role);
  }

  /**
   * Loads a membership and its workspace, ignoring scope.
   *
   * @return array|null
   *   The membership and its workspace, or NULL when either is missing.
   */
  private function load(string $uuid): ?array {
    $entity = $this->uuidLookup->load('ab_membership', $uuid);
    if (!$entity instanceof Membership) {
      return NULL;
    }
    $workspace_id = $entity->get('workspace')->getString();
    if ($workspace_id === '') {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('ab_workspace');
    $workspace = $storage->load($workspace_id);
    if (!$workspace instanceof Workspace) {
      return NULL;
    }
    return [$entity, $workspace];
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
