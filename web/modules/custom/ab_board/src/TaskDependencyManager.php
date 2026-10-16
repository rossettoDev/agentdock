<?php

declare(strict_types=1);

namespace Drupal\ab_board;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_event\EventDraft;
use Drupal\ab_event\EventStoreInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Stores task dependencies and derives the blocked condition.
 *
 * The blocked flag is not a column. A task is blocked while any
 * predecessor is not done. Locks follow workspace, board, then task
 * ids in ascending order.
 */
class TaskDependencyManager {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly MembershipScope $scope,
    private readonly HumanActorContextFactory $actors,
    private readonly EventStoreInterface $events,
  ) {}

  /**
   * Adds one predecessor and records task.dependency_added.
   *
   * @param \Drupal\ab_board\Entity\Task $task
   *   Task that waits.
   * @param \Drupal\ab_board\Entity\Task $dependency
   *   Task that must be done first.
   * @param int $expectedVersion
   *   Version the caller read. A mismatch is a conflict.
   *
   * @throws \Drupal\ab_board\TaskDependencyRejectedException
   *   The pair repeats, leaves the board, or closes a cycle.
   * @throws \Drupal\ab_board\TaskVersionConflictException
   *   The stored version is no longer the expected one.
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   Either task is outside the caller's scope.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller can see the workspace but cannot edit tasks.
   */
  public function add(Task $task, Task $dependency, int $expectedVersion): Task {
    return $this->change($task, $dependency, $expectedVersion, TRUE);
  }

  /**
   * Removes one predecessor and records task.dependency_removed.
   *
   * @param \Drupal\ab_board\Entity\Task $task
   *   Task that was waiting.
   * @param \Drupal\ab_board\Entity\Task $dependency
   *   Predecessor to drop.
   * @param int $expectedVersion
   *   Version the caller read. A mismatch is a conflict.
   *
   * @throws \Drupal\ab_board\TaskDependencyRejectedException
   *   The pair is not stored.
   * @throws \Drupal\ab_board\TaskVersionConflictException
   *   The stored version is no longer the expected one.
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   Either task is outside the caller's scope.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller can see the workspace but cannot edit tasks.
   */
  public function remove(Task $task, Task $dependency, int $expectedVersion): Task {
    return $this->change($task, $dependency, $expectedVersion, FALSE);
  }

  /**
   * Reports whether any predecessor is still not done.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The task workspace is outside the caller's scope.
   */
  public function isBlocked(Task $task): bool {
    $this->assertRead($this->workspaceOf($task));
    $query = $this->database->select('ab_task_dependency', 'd');
    $query->addField('d', 'dependency_id');
    $query->join('ab_task', 'predecessor', 'predecessor.id = d.dependency_id');
    $query->condition('d.task_id', $task->id());
    $query->condition('predecessor.status', 'done', '<>');
    $query->range(0, 1);
    return $query->execute()->fetchField() !== FALSE;
  }

  /**
   * Returns the predecessors of a readable task.
   *
   * @return \Drupal\ab_board\Entity\Task[]
   *   Predecessor tasks ordered by title.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The task workspace is outside the caller's scope.
   */
  public function predecessors(Task $task): array {
    $this->assertRead($this->workspaceOf($task));
    $ids = $this->database->select('ab_task_dependency', 'd')
      ->fields('d', ['dependency_id'])
      ->condition('d.task_id', $task->id())
      ->execute()
      ->fetchCol();
    if ($ids === []) {
      return [];
    }
    $loaded = $this->entityTypeManager->getStorage('ab_task')->loadMultiple($ids);
    $tasks = [];
    foreach ($loaded as $item) {
      if ($item instanceof Task) {
        $tasks[] = $item;
      }
    }
    usort($tasks, static function (Task $left, Task $right): int {
      return strcasecmp((string) $left->label(), (string) $right->label());
    });
    return $tasks;
  }

  /**
   * Runs after the event is recorded and before the caller commits.
   */
  protected function afterRecord(Task $task): void {
    // Tests throw from here to prove the caller transaction rolls back.
  }

  /**
   * Adds or removes one edge inside the caller transaction.
   */
  private function change(Task $task, Task $dependency, int $expectedVersion, bool $add): Task {
    $workspace = $this->workspaceOf($task);
    $this->assertWrite($workspace);
    $this->assertSameBoard($task, $dependency);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $board = $this->boardOf($task);
      $this->lockBoard($board);
      $this->lockTasks($task, $dependency);
      $current = $this->reload($task);
      $predecessor = $this->reload($dependency);
      $version = (int) $current->get('version')->getString();
      if ($version !== $expectedVersion) {
        throw new TaskVersionConflictException('The task version is stale.');
      }
      $this->assertSameBoard($current, $predecessor);
      $task_id = (int) $current->id();
      $dependency_id = (int) $predecessor->id();
      $stored = $this->pairExists($task_id, $dependency_id);
      if ($add) {
        $this->assertCanAdd($current, $predecessor, $stored);
        $this->insertPair($current, $predecessor);
        $type = 'task.dependency_added';
      }
      elseif (!$stored) {
        throw new TaskDependencyRejectedException(TaskDependencyRejectedException::MISSING);
      }
      else {
        $this->deletePair($task_id, $dependency_id);
        $type = 'task.dependency_removed';
      }
      $current->set('version', $version + 1);
      $current->save();
      $this->record($workspace, $current, $predecessor, $type);
      $this->afterRecord($current);
      return $current;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Rejects a pair that is already stored or that closes a cycle.
   */
  private function assertCanAdd(Task $task, Task $dependency, bool $stored): void {
    if ((int) $task->id() === (int) $dependency->id()) {
      throw new TaskDependencyRejectedException(TaskDependencyRejectedException::SELF);
    }
    if ($stored) {
      throw new TaskDependencyRejectedException(TaskDependencyRejectedException::DUPLICATE);
    }
    $board_id = (int) $task->get('board')->getString();
    $cycle = $this->closesCycle((int) $task->id(), (int) $dependency->id(), $board_id);
    if ($cycle) {
      throw new TaskDependencyRejectedException(TaskDependencyRejectedException::CYCLE);
    }
  }

  /**
   * Requires both tasks to stay on one readable board.
   */
  private function assertSameBoard(Task $task, Task $dependency): void {
    $other = $this->workspaceOf($dependency);
    $account = $this->currentUser->getAccount();
    $can_read = $this->scope->allows($account, $other, WorkspacePermission::READ);
    if (!$can_read) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
    $same_board = $task->get('board')->getString() === $dependency->get('board')->getString();
    $same_workspace = $task->get('workspace')->getString() === $dependency->get('workspace')->getString();
    if (!$same_board || !$same_workspace) {
      throw new TaskDependencyRejectedException(TaskDependencyRejectedException::BOARD);
    }
    $same = (string) $task->id() !== '' && (string) $task->id() === (string) $dependency->id();
    if ($same) {
      throw new TaskDependencyRejectedException(TaskDependencyRejectedException::SELF);
    }
  }

  /**
   * Reports whether the dependency already reaches the task.
   */
  private function closesCycle(int $taskId, int $dependencyId, int $boardId): bool {
    $edges = [];
    $rows = $this->database->select('ab_task_dependency', 'd')
      ->fields('d', ['task_id', 'dependency_id'])
      ->condition('d.board', $boardId)
      ->execute();
    foreach ($rows as $row) {
      $edges[(int) $row->task_id][] = (int) $row->dependency_id;
    }
    $seen = [];
    $pending = [$dependencyId];
    while ($pending !== []) {
      $current = array_pop($pending);
      if ($current === $taskId) {
        return TRUE;
      }
      if (isset($seen[$current])) {
        continue;
      }
      $seen[$current] = TRUE;
      foreach ($edges[$current] ?? [] as $next) {
        $pending[] = $next;
      }
    }
    return FALSE;
  }

  /**
   * Reports whether the directed pair is already stored.
   */
  private function pairExists(int $taskId, int $dependencyId): bool {
    $found = $this->database->select('ab_task_dependency', 'd')
      ->fields('d', ['task_id'])
      ->condition('d.task_id', $taskId)
      ->condition('d.dependency_id', $dependencyId)
      ->execute()
      ->fetchField();
    return $found !== FALSE;
  }

  /**
   * Inserts one dependency pair.
   */
  private function insertPair(Task $task, Task $dependency): void {
    $this->database->insert('ab_task_dependency')
      ->fields([
        'workspace' => (int) $task->get('workspace')->getString(),
        'board' => (int) $task->get('board')->getString(),
        'task_id' => (int) $task->id(),
        'dependency_id' => (int) $dependency->id(),
      ])
      ->execute();
  }

  /**
   * Deletes one dependency pair.
   */
  private function deletePair(int $taskId, int $dependencyId): void {
    $this->database->delete('ab_task_dependency')
      ->condition('task_id', $taskId)
      ->condition('dependency_id', $dependencyId)
      ->execute();
  }

  /**
   * Requires permission to read tasks in a visible workspace.
   */
  private function assertRead(Workspace $workspace): void {
    $can_read = $this->scope->allows(
      $this->currentUser->getAccount(),
      $workspace,
      WorkspacePermission::READ,
    );
    if (!$can_read) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
  }

  /**
   * Requires permission to edit tasks in a visible workspace.
   */
  private function assertWrite(Workspace $workspace): void {
    $this->assertRead($workspace);
    $can_write = $this->scope->allows(
      $this->currentUser->getAccount(),
      $workspace,
      WorkspacePermission::WRITE,
    );
    if (!$can_write) {
      throw new ScopedOperationDeniedException('The task cannot be changed.');
    }
  }

  /**
   * Loads the workspace stored on the task.
   */
  private function workspaceOf(Task $task): Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($task->get('workspace')->getString());
    if (!$workspace instanceof Workspace) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
    return $workspace;
  }

  /**
   * Loads the board stored on the task.
   */
  private function boardOf(Task $task): Board {
    $board = $this->entityTypeManager->getStorage('ab_board')->load($task->get('board')->getString());
    if (!$board instanceof Board) {
      throw new ScopedResourceNotFoundException('Board was not found.');
    }
    return $board;
  }

  /**
   * Reloads a task from storage.
   */
  private function reload(Task $task): Task {
    $storage = $this->entityTypeManager->getStorage('ab_task');
    $storage->resetCache([$task->id()]);
    $reloaded = $storage->load($task->id());
    if (!$reloaded instanceof Task) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
    return $reloaded;
  }

  /**
   * Locks the workspace row for the current transaction.
   */
  private function lockWorkspace(Workspace $workspace): void {
    $locked = $this->database->query('SELECT id FROM {ab_workspace} WHERE id = :id FOR UPDATE', [
      ':id' => $workspace->id(),
    ])->fetchField();
    if ($locked === FALSE) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
  }

  /**
   * Locks the board row for the current transaction.
   */
  private function lockBoard(Board $board): void {
    $locked = $this->database->query('SELECT id FROM {ab_board} WHERE id = :id FOR UPDATE', [
      ':id' => $board->id(),
    ])->fetchField();
    if ($locked === FALSE) {
      throw new ScopedResourceNotFoundException('Board was not found.');
    }
  }

  /**
   * Locks the two task rows in ascending id order.
   */
  private function lockTasks(Task $task, Task $dependency): void {
    $ids = [(int) $task->id(), (int) $dependency->id()];
    sort($ids);
    $locked = [];
    foreach (array_unique($ids) as $id) {
      $found = $this->database->query('SELECT id FROM {ab_task} WHERE id = :id FOR UPDATE', [
        ':id' => $id,
      ])->fetchField();
      if ($found === FALSE) {
        throw new ScopedResourceNotFoundException('Task was not found.');
      }
      $locked[] = (int) $found;
    }
    if (count($locked) !== count(array_unique($ids))) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
  }

  /**
   * Records a dependency event for the authenticated actor.
   */
  private function record(Workspace $workspace, Task $task, Task $dependency, string $type): void {
    $actor = $this->actors->create($workspace);
    $this->events->record(new EventDraft(
      (string) $workspace->uuid(),
      $type,
      (string) $task->uuid(),
      $actor->actorType,
      $actor->actorId,
      [
        'title' => (string) $task->label(),
        'status' => $task->get('status')->getString(),
        'priority' => $task->get('priority')->getString(),
        'version' => (int) $task->get('version')->getString(),
        'dependency' => (string) $dependency->uuid(),
      ],
    ));
  }

}
