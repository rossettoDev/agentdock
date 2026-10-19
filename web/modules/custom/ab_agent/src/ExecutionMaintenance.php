<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_agent\Entity\Execution;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Heartbeat, failure, release and expiry for one execution.
 *
 * The actor comes from the token. A second recovery does not record
 * another event. Result delivery stays in the review story.
 */
class ExecutionMaintenance {

  /**
   * Seconds a runner should wait between heartbeats.
   *
   * A faster call is still accepted. The lease lasts LEASE_TTL seconds.
   */
  public const HEARTBEAT_INTERVAL = 30;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ExecutionWriter $writer,
    private readonly AgentOperationGate $gate,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Extends the current lease from the injected clock.
   *
   * @throws \Drupal\ab_agent\LeaseRejectedException
   *   The lease is expired, stale or no longer active.
   */
  public function heartbeat(ActorContext $actor, string $executionUuid, string $leaseToken, int $generation): RenewedLease {
    $mutation = $this->mutate($actor, $executionUuid, $leaseToken, $generation, 'heartbeat', '');
    if (!$mutation->renewed instanceof RenewedLease) {
      throw new \RuntimeException('The heartbeat did not renew the lease.');
    }
    return $mutation->renewed;
  }

  /**
   * Fails the execution and returns the task to ready.
   *
   * @throws \InvalidArgumentException
   *   The failure code is empty or outside the allowed shape.
   */
  public function fail(ActorContext $actor, string $executionUuid, string $leaseToken, int $generation, string $failureCode): void {
    if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $failureCode) !== 1) {
      throw new \InvalidArgumentException('Failure code is invalid.');
    }
    $this->mutate($actor, $executionUuid, $leaseToken, $generation, 'fail', $failureCode);
  }

  /**
   * Releases the task without marking the execution as failed.
   */
  public function release(ActorContext $actor, string $executionUuid, string $leaseToken, int $generation): void {
    $this->mutate($actor, $executionUuid, $leaseToken, $generation, 'release', '');
  }

  /**
   * Rejects a stale lease before any result is stored.
   *
   * A current lease is not delivered here. That write is the review story.
   */
  public function submit(ActorContext $actor, string $executionUuid, string $leaseToken, int $generation): void {
    $mutation = $this->mutate($actor, $executionUuid, $leaseToken, $generation, 'submit', '');
    if ($mutation->kind === LeaseMutation::READY) {
      throw new ExecutionRejectedException(ExecutionRejectedException::DELIVERY);
    }
  }

  /**
   * Interrupts expired leases and returns how many tasks were released.
   */
  public function recover(): int {
    $closed = $this->writer->recoverExpired();
    foreach ($closed as $mutation) {
      $this->forget($mutation);
    }
    return count($closed);
  }

  /**
   * Runs after a lease write, still inside the transaction.
   *
   * Tests override this to prove a failure rolls the write back.
   * Recovery commits inside the writer, so this hook does not cover it.
   */
  protected function afterRecord(LeaseMutation $mutation): void {
  }

  /**
   * Checks the lease and applies one operation.
   */
  private function mutate(ActorContext $actor, string $executionUuid, string $leaseToken, int $generation, string $operation, string $failureCode): LeaseMutation {
    $execution = $this->loadExecution($executionUuid);
    $task = $this->taskOf($execution);
    $workspace = $this->workspaceOf($task);
    if ((string) $workspace->uuid() !== $actor->workspaceId) {
      throw new ScopedResourceNotFoundException('Execution was not found.');
    }
    $board = $this->boardOf($task);
    $this->gate->assert($actor, $board, AgentTokenScope::EXECUTIONS_WRITE);
    $agent = $this->loadAgent($actor->actorId, $workspace);
    if ((int) $execution->get('agent')->getString() !== (int) $agent->id()) {
      throw new AgentAccessDeniedException();
    }
    $tokenId = $actor->tokenId;
    if (!is_string($tokenId) || $tokenId === '') {
      throw new AgentAccessDeniedException();
    }
    $transaction = $this->database->startTransaction();
    try {
      $mutation = $this->writer->mutate(new LeaseCommand(
        (int) $workspace->id(),
        (string) $workspace->uuid(),
        (int) $agent->id(),
        (string) $agent->uuid(),
        (int) $execution->id(),
        (string) $execution->uuid(),
        (int) $task->id(),
        (string) $task->uuid(),
        (int) $board->id(),
        $tokenId,
        $this->secretHash($leaseToken),
        $generation,
        $failureCode,
      ), $operation);
      $this->afterRecord($mutation);
      if ($mutation->kind !== LeaseMutation::RENEWED && $mutation->kind !== LeaseMutation::READY) {
        $this->forget($mutation);
      }
      if ($mutation->kind === LeaseMutation::EXPIRED) {
        throw new LeaseRejectedException(LeaseRejectedException::EXPIRED);
      }
      return $mutation;
    }
    catch (LeaseRejectedException $exception) {
      if ($exception->getMessage() !== LeaseRejectedException::EXPIRED) {
        $transaction->rollBack();
      }
      throw $exception;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Drops cached task rows after a status change.
   */
  private function forget(LeaseMutation $mutation): void {
    if ($mutation->taskId === 0) {
      return;
    }
    $this->entityTypeManager->getStorage('ab_task')->resetCache([$mutation->taskId]);
    $this->entityTypeManager->getStorage('ab_execution')->resetCache([$mutation->executionId]);
    $this->cacheTagsInvalidator->invalidateTags([
      'ab_workspace_tasks:' . $mutation->workspaceId,
    ]);
  }

  /**
   * Hashes the lease secret and keeps the plaintext off the command.
   */
  private function secretHash(string $leaseToken): string {
    if (preg_match('/^ab_lease_[0-9a-f]{32}\.([0-9a-f]{64})$/', $leaseToken, $matches) !== 1) {
      throw new LeaseRejectedException(LeaseRejectedException::TOKEN);
    }
    return hash('sha256', $matches[1]);
  }

  /**
   * Loads an execution by uuid without applying the current user.
   */
  private function loadExecution(string $executionUuid): Execution {
    $ids = $this->entityTypeManager->getStorage('ab_execution')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $executionUuid)
      ->execute();
    $execution = $this->entityTypeManager->getStorage('ab_execution')->load(reset($ids) ?: 0);
    if (!$execution instanceof Execution) {
      throw new ScopedResourceNotFoundException('Execution was not found.');
    }
    return $execution;
  }

  /**
   * Loads the task linked from the execution.
   */
  private function taskOf(Execution $execution): Task {
    $task = $this->entityTypeManager->getStorage('ab_task')->load($execution->get('task')->getString());
    if (!$task instanceof Task) {
      throw new ScopedResourceNotFoundException('Execution was not found.');
    }
    return $task;
  }

  /**
   * Loads the board that owns the task.
   */
  private function boardOf(Task $task): Board {
    $board = $this->entityTypeManager->getStorage('ab_board')->load($task->get('board')->getString());
    if (!$board instanceof Board) {
      throw new ScopedResourceNotFoundException('Execution was not found.');
    }
    return $board;
  }

  /**
   * Loads the workspace stored on the task.
   */
  private function workspaceOf(Task $task): Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($task->get('workspace')->getString());
    if (!$workspace instanceof Workspace) {
      throw new ScopedResourceNotFoundException('Execution was not found.');
    }
    return $workspace;
  }

  /**
   * Loads the agent named by the actor inside this workspace.
   */
  private function loadAgent(string $agentUuid, Workspace $workspace): Agent {
    $ids = $this->entityTypeManager->getStorage('ab_agent')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $agentUuid)
      ->condition('workspace', $workspace->id())
      ->execute();
    $agent = $this->entityTypeManager->getStorage('ab_agent')->load(reset($ids) ?: 0);
    if (!$agent instanceof Agent) {
      throw new AgentAccessDeniedException();
    }
    return $agent;
  }

}
