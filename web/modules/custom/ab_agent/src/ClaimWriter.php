<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_event\EventDraft;
use Drupal\ab_event\EventStoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Writes one execution and one lease inside the caller's transaction.
 *
 * Locks the agent, then the task, then any existing lease. The event
 * is recorded only after those rows are held.
 */
class ClaimWriter {

  /**
   * Seconds a claim holds the task before a later heartbeat.
   */
  public const LEASE_TTL = 120;

  public function __construct(
    private readonly Connection $database,
    private readonly EventStoreInterface $events,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Reserves the task for this agent.
   *
   * The caller already opened the transaction. A unique lease row is
   * the backstop when two claims pass the status check together.
   */
  public function execute(ClaimCommand $command): ClaimedTask {
    if (!$this->database->inTransaction()) {
      throw new \RuntimeException('A claim needs an open transaction.');
    }
    $this->lockAgent($command->agentId);
    $this->assertStillAuthorized($command);
    $task = $this->lockTask($command);
    $this->rejectExistingLease($command->taskId);
    $this->assertAvailable($command, $task);
    $now = $this->time->getRequestTime();
    $expires = $now + self::LEASE_TTL;
    $attempt = $this->nextAttempt($command->taskId);
    $executionId = $this->insertExecution($command, $attempt, $now, $expires);
    $secret = $this->insertLease($command, $executionId, $now, $expires);
    $version = ((int) $task['version']) + 1;
    $this->database->update('ab_task')
      ->fields([
        'status' => 'in_progress',
        'version' => $version,
        'assigned_agent' => $command->agentId,
        'active_execution' => $executionId,
        'changed' => $now,
      ])
      ->condition('id', $command->taskId)
      ->execute();
    $executionUuid = $this->executionUuid($executionId);
    $this->events->record(new EventDraft(
      $command->workspaceUuid,
      'task.claimed',
      $command->taskUuid,
      ActorContext::TYPE_AGENT,
      $command->agentUuid,
      [
        'execution_id' => $executionUuid,
        'agent_id' => $command->agentUuid,
        'status' => 'in_progress',
        'version' => $version,
        'lease_expires_at' => $expires,
        'attempt' => $attempt,
      ],
    ));
    return new ClaimedTask(
      $executionUuid,
      $command->taskUuid,
      $secret,
      $expires,
      $version,
      1,
    );
  }

  /**
   * Holds the agent row before any task or lease row.
   */
  private function lockAgent(int $agentId): void {
    $locked = $this->database->query(
      'SELECT id FROM {ab_agent} WHERE id = :id FOR UPDATE',
      [':id' => $agentId],
    )->fetchField();
    if ($locked === FALSE) {
      throw new AgentAccessDeniedException();
    }
  }

  /**
   * Reloads the task and holds its row.
   *
   * @return array<string, mixed>
   *   Status, version, assignment, workspace and board.
   */
  private function lockTask(ClaimCommand $command): array {
    $task = $this->database->query(
      'SELECT status, version, assigned_agent, workspace, board FROM {ab_task} WHERE id = :id FOR UPDATE',
      [':id' => $command->taskId],
    )->fetchAssoc();
    $sameBoard = is_array($task) && (int) $task['board'] === $command->boardId;
    $sameWorkspace = is_array($task) && (int) $task['workspace'] === $command->workspaceId;
    if (!$sameBoard || !$sameWorkspace) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
    return $task;
  }

  /**
   * Refuses a task that already has a lease row.
   *
   * An expired row stays until a later recovery clears it.
   */
  private function rejectExistingLease(int $taskId): void {
    $existing = $this->database->query(
      'SELECT task_id FROM {ab_execution_lease} WHERE task_id = :task FOR UPDATE',
      [':task' => $taskId],
    )->fetchField();
    if ($existing !== FALSE) {
      throw new ClaimRejectedException(ClaimRejectedException::LEASE);
    }
  }

  /**
   * Confirms the token, the agent and the board grant inside the lock.
   */
  private function assertStillAuthorized(ClaimCommand $command): void {
    $enabled = $this->database->query(
      'SELECT enabled FROM {ab_agent} WHERE id = :id',
      [':id' => $command->agentId],
    )->fetchField();
    if ((int) $enabled !== 1) {
      throw new AgentAccessDeniedException();
    }
    $token = $this->database->query(
      'SELECT scopes, expires_at, revoked_at FROM {ab_agent_token} WHERE token_id = :token AND agent_id = :agent',
      [
        ':token' => $command->tokenId,
        ':agent' => $command->agentId,
      ],
    )->fetchAssoc();
    $now = $this->time->getRequestTime();
    $scopes = is_array($token) ? explode(',', (string) $token['scopes']) : [];
    $expired = !is_array($token) || (int) $token['expires_at'] <= $now;
    $revoked = is_array($token) && $token['revoked_at'] !== NULL;
    if ($expired || $revoked || !in_array(AgentTokenScope::TASKS_CLAIM, $scopes, TRUE)) {
      throw new AgentAccessDeniedException();
    }
    $permissions = $this->database->query(
      'SELECT permissions FROM {ab_agent_board_grant} WHERE agent_id = :agent AND board_id = :board',
      [
        ':agent' => $command->agentId,
        ':board' => $command->boardId,
      ],
    )->fetchField();
    $granted = $permissions === FALSE ? [] : explode(',', (string) $permissions);
    if (!in_array('write', $granted, TRUE)) {
      throw new AgentAccessDeniedException();
    }
  }

  /**
   * Confirms status, predecessors, assignment and the concurrency cap.
   *
   * @param \Drupal\ab_agent\ClaimCommand $command
   *   Claim being checked.
   * @param array<string, mixed> $task
   *   The locked task row.
   */
  private function assertAvailable(ClaimCommand $command, array $task): void {
    if ((string) $task['status'] !== 'ready') {
      throw new ClaimRejectedException(ClaimRejectedException::STATUS);
    }
    $open = $this->database->query(
      "SELECT 1 FROM {ab_task_dependency} d INNER JOIN {ab_task} p ON p.id = d.dependency_id WHERE d.task_id = :task AND p.status <> 'done'",
      [':task' => $command->taskId],
    )->fetchField();
    if ($open !== FALSE) {
      throw new ClaimRejectedException(ClaimRejectedException::BLOCKED);
    }
    $assigned = $task['assigned_agent'];
    $taken = $assigned !== NULL && $assigned !== '' && (int) $assigned !== 0;
    if ($taken && (int) $assigned !== $command->agentId) {
      throw new ClaimRejectedException(ClaimRejectedException::ASSIGNED);
    }
    $running = (int) $this->database->query(
      "SELECT COUNT(*) FROM {ab_execution} WHERE agent = :agent AND status IN ('running', 'waiting_input')",
      [':agent' => $command->agentId],
    )->fetchField();
    if ($running >= $command->concurrencyLimit) {
      throw new ClaimRejectedException(ClaimRejectedException::LIMIT);
    }
  }

  /**
   * Counts earlier attempts of this task.
   */
  private function nextAttempt(int $taskId): int {
    $count = (int) $this->database->query(
      'SELECT COUNT(*) FROM {ab_execution} WHERE task = :task',
      [':task' => $taskId],
    )->fetchField();
    return $count + 1;
  }

  /**
   * Inserts the running execution and returns its numeric id.
   */
  private function insertExecution(ClaimCommand $command, int $attempt, int $now, int $expires): int {
    return (int) $this->database->insert('ab_execution')
      ->fields([
        'uuid' => $this->uuid->generate(),
        'workspace' => $command->workspaceId,
        'task' => $command->taskId,
        'agent' => $command->agentId,
        'status' => 'running',
        'lease_expires_at' => $expires,
        'started_at' => $now,
        'attempt' => $attempt,
        'result_summary' => '',
        'failure_code' => '',
        'version' => 1,
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();
  }

  /**
   * Stores only the hash of the lease secret.
   */
  private function insertLease(ClaimCommand $command, int $executionId, int $now, int $expires): string {
    $public = bin2hex(random_bytes(16));
    $secret = bin2hex(random_bytes(32));
    try {
      $this->database->insert('ab_execution_lease')
        ->fields([
          'task_id' => $command->taskId,
          'execution_id' => $executionId,
          'workspace' => $command->workspaceId,
          'agent_id' => $command->agentId,
          'token_hash' => hash('sha256', $secret),
          'expires_at' => $expires,
          'generation' => 1,
          'created' => $now,
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      throw new ClaimRejectedException(ClaimRejectedException::LEASE);
    }
    return 'ab_lease_' . $public . '.' . $secret;
  }

  /**
   * Reads the uuid written for the new execution.
   */
  private function executionUuid(int $executionId): string {
    $uuid = $this->database->query(
      'SELECT uuid FROM {ab_execution} WHERE id = :id',
      [':id' => $executionId],
    )->fetchField();
    if (!is_string($uuid) || $uuid === '') {
      throw new \RuntimeException('The execution row has no uuid.');
    }
    return $uuid;
  }

}
