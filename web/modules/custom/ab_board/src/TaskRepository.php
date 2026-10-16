<?php

declare(strict_types=1);

namespace Drupal\ab_board;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\UuidLookup;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Lists tasks inside a board the current user can read.
 */
final class TaskRepository {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MembershipScope $scope,
    private readonly UuidLookup $uuidLookup,
  ) {}

  /**
   * Returns the tasks of one readable board, ordered for the list.
   *
   * @return \Drupal\ab_board\Entity\Task[]
   *   Tasks on that board.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The board workspace is outside the caller's scope.
   */
  public function accessibleTasks(Board $board): array {
    $this->assertReadable($this->workspaceOf($board));
    $storage = $this->entityTypeManager->getStorage('ab_task');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('board', $board->id())
      ->sort('position')
      ->sort('title')
      ->execute();
    $tasks = [];
    foreach ($storage->loadMultiple($ids) as $task) {
      if ($task instanceof Task) {
        $tasks[] = $task;
      }
    }
    return $tasks;
  }

  /**
   * Returns a task the current user can read, addressed by UUID.
   */
  public function loadAccessibleByUuid(string $uuid): ?Task {
    try {
      $loaded = $this->uuidLookup->load('ab_task', $uuid);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
    if (!$loaded instanceof Task) {
      return NULL;
    }
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($loaded->get('workspace')->getString());
    if (!$workspace instanceof Workspace || !$this->canRead($workspace)) {
      return NULL;
    }
    return $loaded;
  }

  /**
   * Loads the workspace stored on the board.
   */
  public function workspaceOf(Board $board): Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($board->get('workspace')->getString());
    if (!$workspace instanceof Workspace) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
    return $workspace;
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
