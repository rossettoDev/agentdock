<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\UuidLookup;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Lists agents inside a workspace the current user can read.
 */
final class AgentRepository {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MembershipScope $scope,
    private readonly UuidLookup $uuidLookup,
  ) {}

  /**
   * Returns the agents of one readable workspace, ordered by name.
   *
   * @return \Drupal\ab_agent\Entity\Agent[]
   *   Agents in that workspace.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The workspace is outside the caller's scope.
   */
  public function accessibleAgents(Workspace $workspace): array {
    $this->assertReadable($workspace);
    $storage = $this->entityTypeManager->getStorage('ab_agent');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->sort('name')
      ->execute();
    $agents = [];
    foreach ($storage->loadMultiple($ids) as $agent) {
      if ($agent instanceof Agent) {
        $agents[] = $agent;
      }
    }
    return $agents;
  }

  /**
   * Returns an agent the current user can read, addressed by UUID.
   */
  public function loadAccessibleByUuid(string $uuid): ?Agent {
    try {
      $loaded = $this->uuidLookup->load('ab_agent', $uuid);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
    if (!$loaded instanceof Agent) {
      return NULL;
    }
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($loaded->get('workspace')->getString());
    if (!$workspace instanceof Workspace || !$this->canRead($workspace)) {
      return NULL;
    }
    return $loaded;
  }

  /**
   * Requires a workspace the current user can read.
   */
  private function assertReadable(Workspace $workspace): void {
    if (!$this->canRead($workspace)) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
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
