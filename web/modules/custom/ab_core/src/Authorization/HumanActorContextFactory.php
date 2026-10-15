<?php

declare(strict_types=1);

namespace Drupal\ab_core\Authorization;

use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Builds the human actor context from the authenticated account.
 */
final class HumanActorContextFactory {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly WorkspaceRolePolicy $policy,
  ) {}

  /**
   * Creates the context for the current user in one workspace.
   *
   * The membership lookup is the tenancy source, so it does not use entity
   * access. A missing or inactive membership grants nothing.
   */
  public function create(Workspace $workspace): ActorContext {
    $account_id = (string) $this->currentUser->id();
    return new ActorContext(
      ActorContext::TYPE_HUMAN,
      $account_id === '' ? '0' : $account_id,
      (string) $workspace->uuid(),
      $this->permissions($workspace, $account_id),
    );
  }

  /**
   * Returns permissions from the active membership of the current user.
   *
   * @return string[]
   *   Permission ids.
   */
  private function permissions(Workspace $workspace, string $account_id): array {
    if ($workspace->id() === NULL || (int) $account_id <= 0) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('ab_membership');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('user', $account_id)
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

}
