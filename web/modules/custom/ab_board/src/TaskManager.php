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
use Drupal\user\UserInterface;

/**
 * Creates and edits tasks inside the caller's transaction.
 *
 * The editor writes only the whitelist. Status changes go through
 * transition(), which never writes in_progress, in_review or done.
 * The actor comes from the authenticated account. Locks follow
 * workspace, board, then task.
 */
class TaskManager {

  /**
   * Fields the generic editor may write.
   */
  private const WRITABLE = [
    'title',
    'description',
    'acceptance_criteria',
    'priority',
    'human_owner',
    'assigned_agent',
    'due_at',
    'position',
  ];

  /**
   * Priority values stored on the task.
   */
  private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly MembershipScope $scope,
    private readonly HumanActorContextFactory $actors,
    private readonly EventStoreInterface $events,
  ) {}

  /**
   * Creates a task on a board and records task.created.
   *
   * @param \Drupal\ab_board\Entity\Board $board
   *   Board that owns the new task.
   * @param array<string, mixed> $fields
   *   Whitelist values. Title is required.
   *
   * @throws \InvalidArgumentException
   *   A field is missing, unknown, or not writable here.
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The board workspace is outside the caller's scope.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller can see the workspace but cannot edit tasks.
   */
  public function create(Board $board, array $fields): Task {
    $workspace = $this->workspaceOfBoard($board);
    $this->assertWrite($workspace);
    $changes = $this->changes($fields, TRUE);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $this->lockBoard($board);
      $board = $this->reloadBoard($board);
      if ($board->get('workspace')->getString() !== (string) $workspace->id()) {
        throw new ScopedResourceNotFoundException('Board was not found.');
      }
      $created = Task::create([
        'board' => $board->id(),
        'title' => $changes['title'],
        'description' => $changes['description'] ?? '',
        'acceptance_criteria' => $changes['acceptance_criteria'] ?? '',
        'priority' => $changes['priority'] ?? 'normal',
        'position' => $changes['position'] ?? 0,
      ]);
      $this->setOptional($created, $changes);
      $created->save();
      if (!$created instanceof Task || $created->uuid() === NULL || $created->uuid() === '') {
        throw new \RuntimeException('The task was not stored.');
      }
      $this->record($workspace, $created, 'task.created');
      $this->afterRecord($created);
      return $created;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Edits whitelist fields and records task.updated.
   *
   * An unchanged edit does not bump the version or record another event.
   *
   * @param \Drupal\ab_board\Entity\Task $task
   *   Task to edit.
   * @param array<string, mixed> $fields
   *   Whitelist values to replace.
   * @param int $expectedVersion
   *   Version the caller read. A mismatch is a conflict.
   *
   * @throws \InvalidArgumentException
   *   A field is unknown or not writable here.
   * @throws \Drupal\ab_board\TaskVersionConflictException
   *   The stored version is no longer the expected one.
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The task workspace is outside the caller's scope.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller can see the workspace but cannot edit tasks.
   */
  public function update(Task $task, array $fields, int $expectedVersion): Task {
    $workspace = $this->workspaceOfTask($task);
    $this->assertWrite($workspace);
    $changes = $this->changes($fields, FALSE);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $board = $this->boardOf($task);
      $this->lockBoard($board);
      $this->lockTask($task);
      $current = $this->reloadTask($task);
      $version = (int) $current->get('version')->getString();
      if ($version !== $expectedVersion) {
        throw new TaskVersionConflictException('The task version is stale.');
      }
      if (!$this->apply($current, $changes)) {
        return $current;
      }
      $current->set('version', $version + 1);
      $current->save();
      $this->record($workspace, $current, 'task.updated');
      $this->afterRecord($current);
      return $current;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Moves a task along the human graph and records task.transitioned.
   *
   * The in_progress, in_review and done statuses are refused before the
   * transaction.
   * A task with an active execution is also refused.
   *
   * @param \Drupal\ab_board\Entity\Task $task
   *   Task to move.
   * @param string $target
   *   Status the human asked for.
   * @param int $expectedVersion
   *   Version the caller read. A mismatch is a conflict.
   *
   * @throws \Drupal\ab_board\TaskTransitionRejectedException
   *   The pair is not a human transition.
   * @throws \Drupal\ab_board\TaskVersionConflictException
   *   The stored version is no longer the expected one.
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The task workspace is outside the caller's scope.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller can see the workspace but cannot edit tasks.
   */
  public function transition(Task $task, string $target, int $expectedVersion): Task {
    $workspace = $this->workspaceOfTask($task);
    $this->assertWrite($workspace);
    $this->assertHumanTarget($target);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $board = $this->boardOf($task);
      $this->lockBoard($board);
      $this->lockTask($task);
      $current = $this->reloadTask($task);
      $version = (int) $current->get('version')->getString();
      if ($version !== $expectedVersion) {
        throw new TaskVersionConflictException('The task version is stale.');
      }
      if ($current->get('active_execution')->getString() !== '') {
        throw new TaskTransitionRejectedException('An active execution owns this task.');
      }
      $from = $current->get('status')->getString();
      if (!$this->transitionPolicy()->allows($from, $target)) {
        throw new TaskTransitionRejectedException('That status change is not a human transition.');
      }
      $current->set('status', $target);
      $current->set('version', $version + 1);
      $current->save();
      $this->record($workspace, $current, 'task.transitioned', $from);
      $this->afterRecord($current);
      return $current;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Runs after the event is recorded and before the caller commits.
   */
  protected function afterRecord(Task $task): void {
    // Tests throw from here to prove the caller transaction rolls back.
  }

  /**
   * Requires permission to edit tasks in a visible workspace.
   */
  private function assertWrite(Workspace $workspace): void {
    $account = $this->currentUser->getAccount();
    $can_read = $this->scope->allows($account, $workspace, WorkspacePermission::READ);
    if (!$can_read) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
    $can_write = $this->scope->allows($account, $workspace, WorkspacePermission::WRITE);
    if (!$can_write) {
      throw new ScopedOperationDeniedException('The task cannot be changed.');
    }
  }

  /**
   * Keeps only writable values.
   *
   * @param array<string, mixed> $fields
   *   Requested field values.
   * @param bool $requireTitle
   *   Whether a missing title is rejected.
   *
   * @return array<string, mixed>
   *   Normalized whitelist values.
   */
  private function changes(array $fields, bool $requireTitle): array {
    $unknown = array_diff(array_keys($fields), self::WRITABLE);
    if ($unknown !== []) {
      throw new \InvalidArgumentException('The task editor cannot change that field.');
    }
    if ($requireTitle && !array_key_exists('title', $fields)) {
      throw new \InvalidArgumentException('A task requires a title.');
    }
    $changes = [];
    if (array_key_exists('title', $fields)) {
      $changes['title'] = $this->title($fields['title']);
    }
    if (array_key_exists('description', $fields)) {
      $changes['description'] = $this->text($fields['description'], 'Description');
    }
    if (array_key_exists('acceptance_criteria', $fields)) {
      $changes['acceptance_criteria'] = $this->text($fields['acceptance_criteria'], 'Acceptance criteria');
    }
    if (array_key_exists('priority', $fields)) {
      $changes['priority'] = $this->priority($fields['priority']);
    }
    if (array_key_exists('human_owner', $fields)) {
      $changes['human_owner'] = $this->userId($fields['human_owner']);
    }
    if (array_key_exists('assigned_agent', $fields)) {
      $changes['assigned_agent'] = $this->positiveId($fields['assigned_agent'], 'Assigned agent');
    }
    if (array_key_exists('due_at', $fields)) {
      $changes['due_at'] = $this->timestamp($fields['due_at']);
    }
    if (array_key_exists('position', $fields)) {
      $changes['position'] = $this->position($fields['position']);
    }
    return $changes;
  }

  /**
   * Copies optional ids onto a new task.
   *
   * @param \Drupal\ab_board\Entity\Task $task
   *   Task being created.
   * @param array<string, mixed> $changes
   *   Normalized whitelist values.
   */
  private function setOptional(Task $task, array $changes): void {
    foreach (['human_owner', 'assigned_agent', 'due_at'] as $field) {
      if (array_key_exists($field, $changes)) {
        $task->set($field, $changes[$field]);
      }
    }
  }

  /**
   * Applies whitelist changes and reports whether any value moved.
   *
   * @param \Drupal\ab_board\Entity\Task $task
   *   Task being edited.
   * @param array<string, mixed> $changes
   *   Normalized whitelist values.
   */
  private function apply(Task $task, array $changes): bool {
    $changed = FALSE;
    foreach ($changes as $field => $value) {
      if ($this->stored($task, $field) === $value) {
        continue;
      }
      $task->set($field, $value);
      $changed = TRUE;
    }
    return $changed;
  }

  /**
   * Reads one field in the same shape the editor writes.
   */
  private function stored(Task $task, string $field): mixed {
    $raw = $task->get($field)->getString();
    $optional = in_array($field, ['human_owner', 'assigned_agent', 'due_at'], TRUE);
    if ($raw === '') {
      return $optional ? NULL : '';
    }
    $numeric = $optional || $field === 'position';
    return $numeric ? (int) $raw : $raw;
  }

  /**
   * Normalizes a required title.
   */
  private function title(mixed $value): string {
    if (!is_string($value)) {
      throw new \InvalidArgumentException('A task requires a title.');
    }
    $title = trim($value);
    if ($title === '' || mb_strlen($title) > 255) {
      throw new \InvalidArgumentException('A task requires a title.');
    }
    return $title;
  }

  /**
   * Normalizes a long text field.
   */
  private function text(mixed $value, string $label): string {
    if (!is_string($value)) {
      throw new \InvalidArgumentException($label . ' must be text.');
    }
    return $value;
  }

  /**
   * Normalizes a priority.
   */
  private function priority(mixed $value): string {
    if (!is_string($value) || !in_array($value, self::PRIORITIES, TRUE)) {
      throw new \InvalidArgumentException('Priority is invalid.');
    }
    return $value;
  }

  /**
   * Normalizes a human owner and checks that the account exists.
   */
  private function userId(mixed $value): ?int {
    $id = $this->positiveId($value, 'Human owner');
    if ($id === NULL) {
      return NULL;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($id);
    $active = $user instanceof UserInterface && (int) $user->get('status')->getString() === 1;
    if (!$active) {
      throw new \InvalidArgumentException('Human owner was not found.');
    }
    return $id;
  }

  /**
   * Normalizes an optional positive id.
   */
  private function positiveId(mixed $value, string $label): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (is_int($value) && $value > 0) {
      return $value;
    }
    if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
      return (int) $value;
    }
    throw new \InvalidArgumentException($label . ' must be a positive id.');
  }

  /**
   * Normalizes an optional timestamp.
   */
  private function timestamp(mixed $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (is_int($value) && $value >= 0) {
      return $value;
    }
    if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
      return (int) $value;
    }
    throw new \InvalidArgumentException('Due must be a timestamp.');
  }

  /**
   * Normalizes a board position.
   */
  private function position(mixed $value): int {
    if (is_int($value) && $value >= 0) {
      return $value;
    }
    if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
      return (int) $value;
    }
    throw new \InvalidArgumentException('Position must be zero or greater.');
  }

  /**
   * Loads the workspace stored on the board.
   */
  private function workspaceOfBoard(Board $board): Workspace {
    $workspace_id = $board->get('workspace')->getString();
    return $this->workspace($workspace_id);
  }

  /**
   * Loads the workspace stored on the task.
   */
  private function workspaceOfTask(Task $task): Workspace {
    return $this->workspace($task->get('workspace')->getString());
  }

  /**
   * Loads a workspace by id.
   */
  private function workspace(string $workspace_id): Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($workspace_id);
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
   * Reloads a board from storage.
   */
  private function reloadBoard(Board $board): Board {
    $storage = $this->entityTypeManager->getStorage('ab_board');
    $storage->resetCache([$board->id()]);
    $reloaded = $storage->load($board->id());
    if (!$reloaded instanceof Board) {
      throw new ScopedResourceNotFoundException('Board was not found.');
    }
    return $reloaded;
  }

  /**
   * Reloads a task from storage.
   */
  private function reloadTask(Task $task): Task {
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
   * Locks the task row for the current transaction.
   */
  private function lockTask(Task $task): void {
    $locked = $this->database->query('SELECT id FROM {ab_task} WHERE id = :id FOR UPDATE', [
      ':id' => $task->id(),
    ])->fetchField();
    if ($locked === FALSE) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
  }

  /**
   * Refuses statuses that belong to execution or approval.
   */
  private function assertHumanTarget(string $target): void {
    $known = [
      'backlog',
      'ready',
      'in_progress',
      'in_review',
      'done',
      'cancelled',
    ];
    if (!in_array($target, $known, TRUE)) {
      throw new TaskTransitionRejectedException('That status is unknown.');
    }
    $reserved = in_array($target, TaskTransitionPolicy::RESERVED, TRUE);
    if ($reserved) {
      throw new TaskTransitionRejectedException('Execution status is not a human transition.');
    }
  }

  /**
   * Loads the human transition graph.
   */
  private function transitionPolicy(): TaskTransitionPolicy {
    return new TaskTransitionPolicy();
  }

  /**
   * Records a task event for the authenticated actor.
   */
  private function record(Workspace $workspace, Task $task, string $type, ?string $from = NULL): void {
    $actor = $this->actors->create($workspace);
    $payload = [
      'title' => (string) $task->label(),
      'status' => $task->get('status')->getString(),
      'priority' => $task->get('priority')->getString(),
      'version' => (int) $task->get('version')->getString(),
    ];
    if ($from !== NULL) {
      $payload = [
        'title' => $payload['title'],
        'status' => $payload['status'],
        'from' => $from,
        'priority' => $payload['priority'],
        'version' => $payload['version'],
      ];
    }
    $this->events->record(new EventDraft(
      (string) $workspace->uuid(),
      $type,
      (string) $task->uuid(),
      $actor->actorType,
      $actor->actorId,
      $payload,
    ));
  }

}
