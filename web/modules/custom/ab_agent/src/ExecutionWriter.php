<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_event\EventDraft;
use Drupal\ab_event\EventStoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Renews, notes or closes one lease inside the caller's transaction.
 *
 * Locks the agent, then the task, the execution and the lease. The
 * event is recorded only after those rows are held. Heartbeat does
 * not record an event.
 */
class ExecutionWriter {

  /**
   * Maximum progress notes stored for one execution.
   */
  public const PROGRESS_LIMIT = 20;

  /**
   * Maximum characters in one progress note.
   */
  public const PROGRESS_LENGTH = 500;

  /**
   * Maximum characters in a result summary.
   */
  public const SUMMARY_LENGTH = 2000;

  /**
   * Maximum result links accepted with one delivery.
   */
  public const LINK_LIMIT = 5;

  public function __construct(
    private readonly Connection $database,
    private readonly EventStoreInterface $events,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Applies heartbeat, progress, fail, release or delivery.
   *
   * An expired lease that still matches the holder is interrupted here,
   * so the caller does not wait for the recovery command.
   */
  public function mutate(LeaseCommand $command, string $operation): LeaseMutation {
    if (!$this->database->inTransaction()) {
      throw new \RuntimeException('A lease mutation needs an open transaction.');
    }
    $this->lockAgent($command->agentId);
    $this->assertStillAuthorized($command);
    $task = $this->lockTask($command->taskId, $command->workspaceId, $command->boardId);
    $lease = $this->lockLease($command->taskId);
    $execution = $this->lockExecution($command);
    $this->assertHolder($command, $execution, $lease);
    $now = $this->time->getRequestTime();
    if ((int) $lease['expires_at'] <= $now) {
      $this->close(
        $command,
        $task,
        $execution,
        'interrupted',
        '',
        'expired',
        'execution.interrupted',
        ActorContext::TYPE_AGENT,
        $command->agentUuid,
        $now,
      );
      return new LeaseMutation(
        LeaseMutation::EXPIRED,
        NULL,
        $command->workspaceId,
        $command->taskId,
        $command->executionId,
      );
    }
    if ($operation === 'heartbeat') {
      return $this->renew($command, $execution, $now);
    }
    if ($operation === 'fail') {
      $this->close(
        $command,
        $task,
        $execution,
        'failed',
        $command->failureCode,
        'failed',
        'execution.failed',
        ActorContext::TYPE_AGENT,
        $command->agentUuid,
        $now,
      );
      return new LeaseMutation(LeaseMutation::FAILED, NULL, $command->workspaceId, $command->taskId, $command->executionId);
    }
    if ($operation === 'release') {
      $this->close(
        $command,
        $task,
        $execution,
        'interrupted',
        '',
        'released',
        'execution.interrupted',
        ActorContext::TYPE_AGENT,
        $command->agentUuid,
        $now,
      );
      return new LeaseMutation(LeaseMutation::RELEASED, NULL, $command->workspaceId, $command->taskId, $command->executionId);
    }
    if ($operation === 'progress') {
      return $this->recordProgress($command, $now);
    }
    if ($operation === 'submit') {
      return $this->deliver($command, $task, $execution, $now);
    }
    if ($operation === 'ask') {
      return $this->pause($command, $execution, $now);
    }
    return new LeaseMutation(LeaseMutation::READY);
  }

  /**
   * Interrupts every expired lease the caller has not already closed.
   *
   * Each task uses its own transaction. A lease renewed after the scan
   * is left alone. A second pass finds nothing to record.
   *
   * @return \Drupal\ab_agent\LeaseMutation[]
   *   One mutation for each task that was released.
   */
  public function recoverExpired(): array {
    $now = $this->time->getRequestTime();
    $taskIds = $this->database->query(
      'SELECT task_id FROM {ab_execution_lease} WHERE expires_at <= :now ORDER BY task_id',
      [':now' => $now],
    )->fetchCol();
    $closed = [];
    foreach ($taskIds as $taskId) {
      try {
        $mutation = $this->recoverTask((int) $taskId, $now);
      }
      catch (LeaseRejectedException) {
        continue;
      }
      if ($mutation instanceof LeaseMutation) {
        $closed[] = $mutation;
      }
    }
    return $closed;
  }

  /**
   * Closes one expired lease, or leaves it when the clock no longer applies.
   */
  private function recoverTask(int $taskId, int $now): ?LeaseMutation {
    $transaction = $this->database->startTransaction();
    try {
      $peek = $this->database->query(
        'SELECT agent_id FROM {ab_execution_lease} WHERE task_id = :task',
        [':task' => $taskId],
      )->fetchAssoc();
      if (!is_array($peek)) {
        return NULL;
      }
      $this->lockAgent((int) $peek['agent_id']);
      $task = $this->database->query(
        'SELECT id, uuid, status, version, board, workspace FROM {ab_task} WHERE id = :id FOR UPDATE',
        [':id' => $taskId],
      )->fetchAssoc();
      $lease = $this->database->query(
        'SELECT task_id, execution_id, agent_id, token_hash, expires_at, generation FROM {ab_execution_lease} WHERE task_id = :task FOR UPDATE',
        [':task' => $taskId],
      )->fetchAssoc();
      $sameAgent = is_array($lease) && (int) $lease['agent_id'] === (int) $peek['agent_id'];
      if (!is_array($task) || !is_array($lease) || !$sameAgent || (int) $lease['expires_at'] > $now) {
        return NULL;
      }
      $execution = $this->database->query(
        'SELECT id, uuid, status, version, task, workspace, agent FROM {ab_execution} WHERE id = :id FOR UPDATE',
        [':id' => (int) $lease['execution_id']],
      )->fetchAssoc();
      if (!is_array($execution) || !in_array((string) $execution['status'], ['running', 'waiting_input'], TRUE)) {
        return NULL;
      }
      $workspaceUuid = $this->database->query(
        'SELECT uuid FROM {ab_workspace} WHERE id = :id',
        [':id' => (int) $task['workspace']],
      )->fetchField();
      $agentUuid = $this->database->query(
        'SELECT uuid FROM {ab_agent} WHERE id = :id',
        [':id' => (int) $lease['agent_id']],
      )->fetchField();
      if (!is_string($workspaceUuid) || !is_string($agentUuid) || !is_string($task['uuid']) || !is_string($execution['uuid'])) {
        return NULL;
      }
      $command = new LeaseCommand(
        (int) $task['workspace'],
        $workspaceUuid,
        (int) $lease['agent_id'],
        $agentUuid,
        (int) $execution['id'],
        (string) $execution['uuid'],
        $taskId,
        (string) $task['uuid'],
        (int) $task['board'],
        '',
        (string) $lease['token_hash'],
        (int) $lease['generation'],
        '',
      );
      $closed = $this->close(
        $command,
        $task,
        $execution,
        'interrupted',
        '',
        'expired',
        'execution.interrupted',
        ActorContext::TYPE_SYSTEM,
        'lease-recovery',
        $now,
      );
      if (!$closed) {
        return NULL;
      }
      return new LeaseMutation(LeaseMutation::EXPIRED, NULL, (int) $task['workspace'], $taskId, (int) $execution['id']);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Marks the execution as waiting without releasing the lease.
   *
   * The task stays in progress. Heartbeat remains the way to extend
   * the reservation.
   */
  private function pause(LeaseCommand $command, array $execution, int $now): LeaseMutation {
    $updated = $this->database->update('ab_execution')
      ->fields([
        'status' => 'waiting_input',
        'version' => ((int) $execution['version']) + 1,
        'changed' => $now,
      ])
      ->condition('id', $command->executionId)
      ->condition('status', ['running', 'waiting_input'], 'IN')
      ->execute();
    if ((int) $updated !== 1) {
      throw new LeaseRejectedException(LeaseRejectedException::STATE);
    }
    return new LeaseMutation(
      LeaseMutation::WAITING,
      NULL,
      $command->workspaceId,
      $command->taskId,
      $command->executionId,
    );
  }

  /**
   * Extends the lease from the injected clock.
   */
  private function renew(LeaseCommand $command, array $execution, int $now): LeaseMutation {
    $expires = $now + ClaimWriter::LEASE_TTL;
    $version = ((int) $execution['version']) + 1;
    $this->database->update('ab_execution_lease')
      ->fields(['expires_at' => $expires])
      ->condition('task_id', $command->taskId)
      ->condition('execution_id', $command->executionId)
      ->condition('generation', $command->generation)
      ->execute();
    $this->database->update('ab_execution')
      ->fields([
        'heartbeat_at' => $now,
        'lease_expires_at' => $expires,
        'version' => $version,
        'changed' => $now,
      ])
      ->condition('id', $command->executionId)
      ->condition('status', ['running', 'waiting_input'], 'IN')
      ->execute();
    return new LeaseMutation(
      LeaseMutation::RENEWED,
      new RenewedLease($command->executionUuid, $expires, $command->generation),
      $command->workspaceId,
      $command->taskId,
      $command->executionId,
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
   * Confirms the token, the agent and the board grant inside the lock.
   */
  private function assertStillAuthorized(LeaseCommand $command): void {
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
    $revoked = is_array($token) && $token['revoked_at'] !== NULL && $token['revoked_at'] !== '';
    $allowed = in_array(AgentTokenScope::EXECUTIONS_WRITE, $scopes, TRUE);
    if ($expired || $revoked || !$allowed) {
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
   * Reloads the task and holds its row.
   *
   * @return array<string, mixed>
   *   Status, version, board and workspace.
   */
  private function lockTask(int $taskId, int $workspaceId, int $boardId): array {
    $task = $this->database->query(
      'SELECT id, uuid, status, version, board, workspace FROM {ab_task} WHERE id = :id FOR UPDATE',
      [':id' => $taskId],
    )->fetchAssoc();
    $sameBoard = is_array($task) && (int) $task['board'] === $boardId;
    $sameWorkspace = is_array($task) && (int) $task['workspace'] === $workspaceId;
    if (!$sameBoard || !$sameWorkspace || !is_string($task['uuid'])) {
      throw new ScopedResourceNotFoundException('Execution was not found.');
    }
    return $task;
  }

  /**
   * Holds the execution row.
   *
   * @return array<string, mixed>
   *   Status, version and the task link.
   */
  private function lockExecution(LeaseCommand $command): array {
    $execution = $this->database->query(
      'SELECT id, uuid, status, version, attempt, task, workspace, agent FROM {ab_execution} WHERE id = :id FOR UPDATE',
      [':id' => $command->executionId],
    )->fetchAssoc();
    $sameTask = is_array($execution) && (int) $execution['task'] === $command->taskId;
    $sameWorkspace = is_array($execution) && (int) $execution['workspace'] === $command->workspaceId;
    if (!$sameTask || !$sameWorkspace) {
      throw new ScopedResourceNotFoundException('Execution was not found.');
    }
    return $execution;
  }

  /**
   * Holds the lease row for the task.
   *
   * @return array<string, mixed>
   *   Hash, expiry, generation and the holder.
   */
  private function lockLease(int $taskId): array {
    $lease = $this->database->query(
      'SELECT task_id, execution_id, agent_id, token_hash, expires_at, generation FROM {ab_execution_lease} WHERE task_id = :task FOR UPDATE',
      [':task' => $taskId],
    )->fetchAssoc();
    if (!is_array($lease)) {
      throw new LeaseRejectedException(LeaseRejectedException::STATE);
    }
    return $lease;
  }

  /**
   * Confirms the caller still holds this generation of the lease.
   */
  private function assertHolder(LeaseCommand $command, array $execution, array $lease): void {
    if ((int) $lease['agent_id'] !== $command->agentId || (int) $execution['agent'] !== $command->agentId) {
      throw new AgentAccessDeniedException();
    }
    if ((int) $lease['execution_id'] !== $command->executionId) {
      throw new LeaseRejectedException(LeaseRejectedException::TOKEN);
    }
    $hash = (string) $lease['token_hash'];
    if (!hash_equals($hash, $command->secretHash)) {
      throw new LeaseRejectedException(LeaseRejectedException::TOKEN);
    }
    if ((int) $lease['generation'] !== $command->generation) {
      throw new LeaseRejectedException(LeaseRejectedException::GENERATION);
    }
    $status = (string) $execution['status'];
    if (!in_array($status, ['running', 'waiting_input'], TRUE)) {
      throw new LeaseRejectedException(LeaseRejectedException::STATE);
    }
  }

  /**
   * Ends the execution, drops the lease and moves the task.
   */
  private function close(
    LeaseCommand $command,
    array $task,
    array $execution,
    string $executionStatus,
    string $failureCode,
    string $reason,
    string $eventType,
    string $actorType,
    string $actorId,
    int $now,
    string $taskStatus = 'ready',
    bool $keepAssignment = FALSE,
    string $resultSummary = '',
  ): bool {
    $executionFields = [
      'status' => $executionStatus,
      'ended_at' => $now,
      'failure_code' => $failureCode,
      'changed' => $now,
      'version' => ((int) $execution['version']) + 1,
    ];
    if ($resultSummary !== '') {
      $executionFields['result_summary'] = $resultSummary;
    }
    $executionUpdated = $this->database->update('ab_execution')
      ->fields($executionFields)
      ->condition('id', $command->executionId)
      ->condition('status', ['running', 'waiting_input'], 'IN')
      ->execute();
    if ((int) $executionUpdated !== 1) {
      return FALSE;
    }
    $taskFields = [
      'status' => $taskStatus,
      'version' => ((int) $task['version']) + 1,
      'changed' => $now,
    ];
    if (!$keepAssignment) {
      $taskFields['assigned_agent'] = NULL;
      $taskFields['active_execution'] = NULL;
    }
    $taskUpdated = $this->database->update('ab_task')
      ->fields($taskFields)
      ->condition('id', $command->taskId)
      ->condition('active_execution', $command->executionId)
      ->execute();
    if ((int) $taskUpdated !== 1) {
      throw new \RuntimeException('The task did not release with the execution.');
    }
    $this->database->delete('ab_execution_lease')
      ->condition('task_id', $command->taskId)
      ->condition('execution_id', $command->executionId)
      ->execute();
    $payload = [
      'execution_id' => $command->executionUuid,
      'task_id' => $command->taskUuid,
      'reason' => $reason,
      'status' => $taskStatus,
      'version' => ((int) $task['version']) + 1,
    ];
    if ($failureCode !== '') {
      $payload['failure_code'] = $failureCode;
    }
    if ($resultSummary !== '') {
      $payload['summary'] = $resultSummary;
      $payload['attempt'] = (int) ($execution['attempt'] ?? 0);
    }
    $this->events->record(new EventDraft(
      $command->workspaceUuid,
      $eventType,
      $command->taskUuid,
      $actorType,
      $actorId,
      $payload,
    ));
    return TRUE;
  }

  /**
   * Stores one plain note without ending the lease.
   */
  private function recordProgress(LeaseCommand $command, int $now): LeaseMutation {
    $note = trim($command->note);
    $tooLong = mb_strlen($note) > self::PROGRESS_LENGTH;
    if ($note === '' || $tooLong || preg_match('/[<>\r\n]/', $note) === 1) {
      throw new \InvalidArgumentException('Progress note is invalid.');
    }
    $count = (int) $this->database->query(
      'SELECT COUNT(*) FROM {ab_execution_progress} WHERE execution_id = :id',
      [':id' => $command->executionId],
    )->fetchField();
    if ($count >= self::PROGRESS_LIMIT) {
      throw new ExecutionRejectedException(ExecutionRejectedException::PROGRESS);
    }
    $this->database->insert('ab_execution_progress')
      ->fields([
        'execution_id' => $command->executionId,
        'sequence' => $count + 1,
        'workspace' => $command->workspaceId,
        'body' => $note,
        'created' => $now,
      ])
      ->execute();
    $this->events->record(new EventDraft(
      $command->workspaceUuid,
      'execution.progressed',
      $command->taskUuid,
      ActorContext::TYPE_AGENT,
      $command->agentUuid,
      [
        'execution_id' => $command->executionUuid,
        'task_id' => $command->taskUuid,
        'note' => $note,
      ],
    ));
    return new LeaseMutation(
      LeaseMutation::PROGRESSED,
      NULL,
      $command->workspaceId,
      $command->taskId,
      $command->executionId,
    );
  }

  /**
   * Stores the result, ends the lease and moves the task to review.
   */
  private function deliver(LeaseCommand $command, array $task, array $execution, int $now): LeaseMutation {
    $summary = trim($command->summary);
    if ($summary === '' || mb_strlen($summary) > self::SUMMARY_LENGTH) {
      throw new \InvalidArgumentException('Result summary is invalid.');
    }
    $links = $this->acceptedLinks($command->links);
    $closed = $this->close(
      $command,
      $task,
      $execution,
      'submitted',
      '',
      'submitted',
      'execution.submitted',
      ActorContext::TYPE_AGENT,
      $command->agentUuid,
      $now,
      'in_review',
      TRUE,
      $summary,
    );
    if (!$closed) {
      throw new LeaseRejectedException(LeaseRejectedException::STATE);
    }
    foreach ($links as $delta => $link) {
      $this->database->insert('ab_execution_link')
        ->fields([
          'execution_id' => $command->executionId,
          'delta' => $delta,
          'workspace' => $command->workspaceId,
          'url' => $link['url'],
          'label' => $link['label'],
        ])
        ->execute();
    }
    return new LeaseMutation(
      LeaseMutation::SUBMITTED,
      NULL,
      $command->workspaceId,
      $command->taskId,
      $command->executionId,
    );
  }

  /**
   * Keeps only http and https links, and only a short list.
   *
   * @param array<mixed> $links
   *   Links offered with the result.
   *
   * @return array<int, array{url: string, label: string}>
   *   Links safe to store.
   */
  private function acceptedLinks(array $links): array {
    if (count($links) > self::LINK_LIMIT) {
      throw new \InvalidArgumentException('Result links are invalid.');
    }
    $clean = [];
    foreach ($links as $link) {
      if (!is_array($link)) {
        throw new \InvalidArgumentException('Result links are invalid.');
      }
      $url = isset($link['url']) && is_string($link['url']) ? trim($link['url']) : '';
      $label = isset($link['label']) && is_string($link['label']) ? trim($link['label']) : '';
      $shape = preg_match('#^https?://[^\s<>]+$#i', $url) === 1;
      if (!$shape || strlen($url) > 500 || mb_strlen($label) > 80) {
        throw new \InvalidArgumentException('Result links are invalid.');
      }
      $clean[] = ['url' => $url, 'label' => $label];
    }
    return $clean;
  }

}
