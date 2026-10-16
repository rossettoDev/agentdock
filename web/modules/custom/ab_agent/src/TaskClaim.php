<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Claims a ready task for the authenticated agent.
 *
 * The actor comes from the token. The method does not accept an
 * agent id argument, and a second claim conflicts instead of
 * replaying the lease secret.
 */
class TaskClaim {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ClaimWriter $writer,
    private readonly AgentOperationGate $gate,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Reserves the task and returns the only copy of the lease token.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The task is missing or outside the actor workspace.
   * @throws \Drupal\ab_agent\AgentAccessDeniedException
   *   The token, agent or grant does not allow the claim.
   * @throws \Drupal\ab_agent\ClaimRejectedException
   *   The task is not available to this agent.
   */
  public function claim(ActorContext $actor, string $taskUuid): ClaimedTask {
    $task = $this->loadTask($taskUuid);
    $workspace = $this->workspaceOf($task);
    if ((string) $workspace->uuid() !== $actor->workspaceId) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
    $board = $this->boardOf($task);
    $this->gate->assert($actor, $board, AgentTokenScope::TASKS_CLAIM);
    $agent = $this->loadAgent($actor->actorId, $workspace);
    $tokenId = $actor->tokenId;
    if (!is_string($tokenId) || $tokenId === '') {
      throw new AgentAccessDeniedException();
    }
    $transaction = $this->database->startTransaction();
    try {
      $claimed = $this->writer->execute(new ClaimCommand(
        (int) $workspace->id(),
        (string) $workspace->uuid(),
        (int) $agent->id(),
        (string) $agent->uuid(),
        (int) $task->id(),
        (string) $task->uuid(),
        (int) $board->id(),
        (int) $agent->get('concurrency_limit')->getString(),
        $tokenId,
      ));
      $this->afterRecord($claimed);
      $this->entityTypeManager->getStorage('ab_task')->resetCache([(int) $task->id()]);
      $this->cacheTagsInvalidator->invalidateTags([
        'ab_workspace_tasks:' . $workspace->id(),
      ]);
      return $claimed;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Runs after the claim event, still inside the transaction.
   *
   * Tests override this to prove a failure rolls the claim back.
   */
  protected function afterRecord(ClaimedTask $claimed): void {
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
   * Loads the board that owns the task.
   */
  private function boardOf(Task $task): Board {
    $board = $this->entityTypeManager->getStorage('ab_board')->load($task->get('board')->getString());
    if (!$board instanceof Board) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
    return $board;
  }

  /**
   * Loads the workspace stored on the task.
   */
  private function workspaceOf(Task $task): Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($task->get('workspace')->getString());
    if (!$workspace instanceof Workspace) {
      throw new ScopedResourceNotFoundException('Task was not found.');
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
