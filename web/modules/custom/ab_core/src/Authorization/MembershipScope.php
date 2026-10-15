<?php

declare(strict_types=1);

namespace Drupal\ab_core\Authorization;

use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Resolves active workspace membership for an account.
 *
 * The lookup is the tenancy source, so it does not use entity access.
 */
final class MembershipScope {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly WorkspaceRolePolicy $policy,
  ) {}

  /**
   * Returns workspace ids where the account has an active membership.
   *
   * @return string[]
   *   Workspace ids, sorted.
   */
  public function activeWorkspaceIds(AccountInterface $account): array {
    if ((int) $account->id() <= 0) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('ab_membership');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('user', $account->id())
      ->condition('status', 'active')
      ->execute();
    $workspace_ids = [];
    foreach ($storage->loadMultiple($ids) as $membership) {
      if (!$membership instanceof Membership) {
        continue;
      }
      $workspace_id = $membership->get('workspace')->getString();
      if ($workspace_id !== '') {
        $workspace_ids[$workspace_id] = $workspace_id;
      }
    }
    $workspace_ids = array_values($workspace_ids);
    sort($workspace_ids);
    return $workspace_ids;
  }

  /**
   * Returns permissions for the account inside one workspace.
   *
   * @return string[]
   *   Permission ids.
   */
  public function permissions(AccountInterface $account, Workspace $workspace): array {
    if ($workspace->id() === NULL || (int) $account->id() <= 0) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('ab_membership');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('user', $account->id())
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return [];
    }
    $membership = $storage->load(reset($ids));
    if (!$membership instanceof Membership) {
      return [];
    }
    return $this->policy->permissions(
      $membership->get('role')->getString(),
      $membership->get('status')->getString(),
    );
  }

  /**
   * Reports whether the account holds a permission in the workspace.
   */
  public function allows(AccountInterface $account, Workspace $workspace, string $permission): bool {
    return in_array($permission, $this->permissions($account, $workspace), TRUE);
  }

}
