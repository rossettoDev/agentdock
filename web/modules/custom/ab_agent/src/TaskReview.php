<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskVersionConflictException;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_event\EventDraft;
use Drupal\ab_event\EventStoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Approves, rejects or cancels a delivery for the current human.
 *
 * The actor is the authenticated account. An agent id in the caller
 * is ignored because this command does not accept one. Done and
 * cancelled tasks do not keep an active execution.
 */
class TaskReview {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly MembershipScope $scope,
    private readonly HumanActorContextFactory $actors,
    private readonly EventStoreInterface $events,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Marks a reviewed delivery done and clears the active execution.
   */
  public function approve(string $taskUuid, int $expectedVersion): void {
    $this->decide($taskUuid, $expectedVersion, WorkspacePermission::REVIEW, 'approve');
  }

  /**
   * Returns the task to ready and keeps the submitted execution.
   */
  public function reject(string $taskUuid, int $expectedVersion, string $reason): void {
    $reason = trim($reason);
    $tooLong = mb_strlen($reason) > 500;
    if ($reason === '' || $tooLong || preg_match('/[<>\r\n]/', $reason) === 1) {
      throw new \InvalidArgumentException('A rejection requires a reason.');
    }
    $this->decide($taskUuid, $expectedVersion, WorkspacePermission::REVIEW, 'reject', $reason);
  }

  /**
   * Ends the reservation and cancels the task.
   */
  public function cancel(string $taskUuid, int $expectedVersion): void {
    $this->decide($taskUuid, $expectedVersion, WorkspacePermission::WRITE, 'cancel');
  }

  /**
   * Runs after the review event, still inside the transaction.
   *
   * Tests override this to prove a failure rolls the write back.
   */
  protected function afterRecord(): void {
  }

  /**
   * Locks the agent, then the task, and records one review outcome.
   */
  private function decide(string $taskUuid, int $expectedVersion, string $permission, string $operation, string $reason = ''): void {
    $task = $this->loadTask($taskUuid);
    $workspace = $this->visibleWorkspace($task);
    $actor = $this->actor($workspace, $permission);
    $transaction = $this->database->startTransaction();
    try {
      $agentId = $this->peekAgent($task);
      if ($agentId > 0) {
        $this->lockAgent($agentId);
      }
      $row = $this->lockTask((int) $task->id(), (int) $workspace->id());
      if ((int) $row['version'] !== $expectedVersion) {
        throw new TaskVersionConflictException('The task version is stale.');
      }
      $assigned = (int) ($row['assigned_agent'] ?? 0);
      if ($agentId > 0 && $assigned > 0 && $assigned !== $agentId) {
        throw new TaskReviewRejectedException(TaskReviewRejectedException::STATUS);
      }
      $executionId = $this->apply($operation, $actor, $workspace, $row, $reason);
      $this->afterRecord();
      $this->forget((int) $workspace->id(), (int) $row['id'], $executionId, $agentId);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Applies one review outcome while the task row is locked.
   */
  private function apply(string $operation, ActorContext $actor, Workspace $workspace, array $row, string $reason): int {
    $status = (string) $row['status'];
    $executionId = (int) ($row['active_execution'] ?? 0);
    if ($executionId === 0) {
      throw new TaskReviewRejectedException(TaskReviewRejectedException::STATUS);
    }
    $this->lockLease((int) $row['id']);
    $execution = $this->lockExecution($executionId, (int) $row['id']);
    $now = $this->time->getRequestTime();
    if ($operation === 'approve') {
      $this->assertReviewState($status, (string) $execution['status'], 'in_review', 'submitted');
      $this->finishTask($row, 'done', $now);
      $this->record($actor, $workspace, $row, 'task.approved', 'done', (string) $execution['uuid'], '');
      return $executionId;
    }
    if ($operation === 'reject') {
      $this->assertReviewState($status, (string) $execution['status'], 'in_review', 'submitted');
      $this->finishTask($row, 'ready', $now);
      $this->record($actor, $workspace, $row, 'task.rejected', 'ready', (string) $execution['uuid'], $reason);
      return $executionId;
    }
    $expected = $status === 'in_progress' ? ['running', 'waiting_input'] : ['submitted'];
    if (!in_array($status, ['in_progress', 'in_review'], TRUE)) {
      throw new TaskReviewRejectedException(TaskReviewRejectedException::STATUS);
    }
    if (!in_array((string) $execution['status'], $expected, TRUE)) {
      throw new TaskReviewRejectedException(TaskReviewRejectedException::STATUS);
    }
    $updated = $this->database->update('ab_execution')
      ->fields([
        'status' => 'cancelled',
        'ended_at' => $now,
        'changed' => $now,
        'version' => ((int) $execution['version']) + 1,
      ])
      ->condition('id', $executionId)
      ->execute();
    if ((int) $updated !== 1) {
      throw new TaskReviewRejectedException(TaskReviewRejectedException::STATUS);
    }
    $this->database->delete('ab_execution_lease')
      ->condition('task_id', (int) $row['id'])
      ->condition('execution_id', $executionId)
      ->execute();
    $this->finishTask($row, 'cancelled', $now);
    $this->record($actor, $workspace, $row, 'execution.cancelled', 'cancelled', (string) $execution['uuid'], '');
    return $executionId;
  }

  /**
   * Requires the task and the execution to be in the expected pair.
   */
  private function assertReviewState(string $taskStatus, string $executionStatus, string $expectedTask, string $expectedExecution): void {
    if ($taskStatus !== $expectedTask || $executionStatus !== $expectedExecution) {
      throw new TaskReviewRejectedException(TaskReviewRejectedException::STATUS);
    }
  }

  /**
   * Moves the task and clears the live execution pointer.
   */
  private function finishTask(array $row, string $status, int $now): void {
    $updated = $this->database->update('ab_task')
      ->fields([
        'status' => $status,
        'version' => ((int) $row['version']) + 1,
        'assigned_agent' => NULL,
        'active_execution' => NULL,
        'changed' => $now,
      ])
      ->condition('id', (int) $row['id'])
      ->condition('version', (int) $row['version'])
      ->condition('status', (string) $row['status'])
      ->execute();
    if ((int) $updated !== 1) {
      throw new TaskReviewRejectedException(TaskReviewRejectedException::STATUS);
    }
  }

  /**
   * Records the human decision after the task row is held.
   */
  private function record(ActorContext $actor, Workspace $workspace, array $row, string $type, string $status, string $executionUuid, string $reason): void {
    $payload = [
      'execution_id' => $executionUuid,
      'task_id' => (string) $row['uuid'],
      'status' => $status,
      'version' => ((int) $row['version']) + 1,
    ];
    if ($reason !== '') {
      $payload['reason'] = $reason;
    }
    $this->events->record(new EventDraft(
      (string) $workspace->uuid(),
      $type,
      (string) $row['uuid'],
      $actor->actorType,
      $actor->actorId,
      $payload,
    ));
  }

  /**
   * Drops cached rows after the review commits.
   */
  private function forget(int $workspaceId, int $taskId, int $executionId, int $agentId): void {
    $this->entityTypeManager->getStorage('ab_task')->resetCache([$taskId]);
    if ($executionId > 0) {
      $this->entityTypeManager->getStorage('ab_execution')->resetCache([$executionId]);
    }
    $tags = ['ab_workspace_tasks:' . $workspaceId];
    if ($agentId > 0) {
      $tags[] = 'ab_agent_signal:' . $agentId;
    }
    $this->cacheTagsInvalidator->invalidateTags($tags);
  }

  /**
   * Reads the delivering agent before the task lock.
   */
  private function peekAgent(Task $task): int {
    $assigned = (int) $task->get('assigned_agent')->getString();
    if ($assigned > 0) {
      return $assigned;
    }
    $active = (int) $task->get('active_execution')->getString();
    if ($active === 0) {
      return 0;
    }
    return (int) $this->database->query(
      'SELECT agent FROM {ab_execution} WHERE id = :id',
      [':id' => $active],
    )->fetchField();
  }

  /**
   * Holds the agent row before the task row.
   */
  private function lockAgent(int $agentId): void {
    $locked = $this->database->query(
      'SELECT id FROM {ab_agent} WHERE id = :id FOR UPDATE',
      [':id' => $agentId],
    )->fetchField();
    if ($locked === FALSE) {
      throw new TaskReviewRejectedException(TaskReviewRejectedException::STATUS);
    }
  }

  /**
   * Holds the task row in its workspace.
   *
   * @return array<string, mixed>
   *   Status, version and the active execution.
   */
  private function lockTask(int $taskId, int $workspaceId): array {
    $row = $this->database->query(
      'SELECT id, uuid, status, version, assigned_agent, active_execution, workspace FROM {ab_task} WHERE id = :id FOR UPDATE',
      [':id' => $taskId],
    )->fetchAssoc();
    if (!is_array($row) || (int) $row['workspace'] !== $workspaceId || !is_string($row['uuid'])) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
    return $row;
  }

  /**
   * Holds the lease row when the task still has one.
   */
  private function lockLease(int $taskId): void {
    $this->database->query(
      'SELECT task_id FROM {ab_execution_lease} WHERE task_id = :task FOR UPDATE',
      [':task' => $taskId],
    )->fetchField();
  }

  /**
   * Holds the execution that the task still points at.
   *
   * @return array<string, mixed>
   *   Status, version and the public id.
   */
  private function lockExecution(int $executionId, int $taskId): array {
    $row = $this->database->query(
      'SELECT id, uuid, status, version, task FROM {ab_execution} WHERE id = :id FOR UPDATE',
      [':id' => $executionId],
    )->fetchAssoc();
    if (!is_array($row) || (int) $row['task'] !== $taskId || !is_string($row['uuid'])) {
      throw new TaskReviewRejectedException(TaskReviewRejectedException::STATUS);
    }
    return $row;
  }

  /**
   * Loads a task by uuid without applying the current user.
   */
  private function loadTask(string $taskUuid): Task {
    $ids = $this->entityTypeManager->getStorage('ab_task')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $taskUuid)
      ->execute();
    $task = $this->entityTypeManager->getStorage('ab_task')->load(reset($ids) ?: 0);
    if (!$task instanceof Task) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
    return $task;
  }

  /**
   * Requires a workspace the current account can read.
   */
  private function visibleWorkspace(Task $task): Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($task->get('workspace')->getString());
    if (!$workspace instanceof Workspace) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
    $account = $this->currentUser->getAccount();
    if (!$this->scope->allows($account, $workspace, WorkspacePermission::READ)) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
    return $workspace;
  }

  /**
   * Requires the review or write permission and returns the human actor.
   */
  private function actor(Workspace $workspace, string $permission): ActorContext {
    $account = $this->currentUser->getAccount();
    if (!$this->scope->allows($account, $workspace, $permission)) {
      throw new ScopedOperationDeniedException('The task cannot be reviewed.');
    }
    $actor = $this->actors->create($workspace);
    if ($actor->actorType !== ActorContext::TYPE_HUMAN) {
      throw new ScopedOperationDeniedException('The task cannot be reviewed.');
    }
    return $actor;
  }

}
