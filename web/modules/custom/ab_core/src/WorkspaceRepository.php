<?php

declare(strict_types=1);

namespace Drupal\ab_core;

use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Lists the workspaces visible to the current user.
 */
final class WorkspaceRepository {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MembershipScope $scope,
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
    foreach ($this->entityTypeManager->getStorage('ab_workspace')->loadMultiple($ids) as $workspace) {
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
    return count($this->scope->activeWorkspaceIds($this->currentUser->getAccount()));
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

}
