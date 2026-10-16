<?php

declare(strict_types=1);

namespace Drupal\ab_board;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\UuidLookup;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Lists boards inside a workspace the current user can read.
 */
final class BoardRepository {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MembershipScope $scope,
    private readonly UuidLookup $uuidLookup,
  ) {}

  /**
   * Returns the boards of one readable workspace, ordered by name.
   *
   * @return \Drupal\ab_board\Entity\Board[]
   *   Boards in that workspace, including archived ones.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The workspace is outside the caller's scope.
   */
  public function accessibleBoards(Workspace $workspace): array {
    $this->assertReadable($workspace);
    $storage = $this->entityTypeManager->getStorage('ab_board');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->sort('name')
      ->execute();
    $boards = [];
    foreach ($storage->loadMultiple($ids) as $board) {
      if ($board instanceof Board) {
        $boards[] = $board;
      }
    }
    return $boards;
  }

  /**
   * Returns a board the current user can read, addressed by UUID.
   */
  public function loadAccessibleByUuid(string $uuid): ?Board {
    try {
      $loaded = $this->uuidLookup->load('ab_board', $uuid);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
    if (!$loaded instanceof Board) {
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
