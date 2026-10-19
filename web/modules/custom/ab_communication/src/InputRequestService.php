<?php

declare(strict_types=1);

namespace Drupal\ab_communication;

use Drupal\ab_agent\AgentAccessDeniedException;
use Drupal\ab_agent\AgentOperationGate;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_agent\Entity\Execution;
use Drupal\ab_agent\ExecutionWriter;
use Drupal\ab_agent\LeaseCommand;
use Drupal\ab_agent\LeaseMutation;
use Drupal\ab_agent\LeaseRejectedException;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_event\EventDraft;
use Drupal\ab_event\EventStoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Asks a human for information and resolves that question.
 *
 * The request, the message and the waiting state share one transaction.
 * An expired execution stays interrupted when the answer arrives later.
 */
class InputRequestService {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MessageService $messages,
    private readonly ExecutionWriter $writer,
    private readonly EventStoreInterface $events,
    private readonly AgentOperationGate $gate,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Opens a question and keeps the lease.
   *
   * Several open questions leave the execution waiting. The body is
   * stored on the message and omitted from the event.
   */
  public function request(ActorContext $actor, string $executionUuid, string $leaseToken, int $generation, string $body): InputRequest {
    $body = $this->preparedBody($body);
    $execution = $this->loadExecution($executionUuid);
    $task = $this->taskOf($execution);
    $workspace = $this->workspaceOf($task);
    if ((string) $workspace->uuid() !== $actor->workspaceId) {
      throw new ScopedResourceNotFoundException('Execution was not found.');
    }
    $board = $this->boardOf($task);
    $this->gate->assert($actor, $board, AgentTokenScope::EXECUTIONS_WRITE);
    $this->gate->assert($actor, $board, AgentTokenScope::MESSAGES_WRITE);
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
      $this->lockAgent((int) $agent->id());
      $this->assertMessageScope((int) $agent->id(), $tokenId);
      $this->lockBoard((int) $board->id());
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
        '',
      ), 'ask');
      if ($mutation->kind === LeaseMutation::EXPIRED) {
        $this->afterRecord();
        $this->forget($mutation);
        throw new LeaseRejectedException(LeaseRejectedException::EXPIRED);
      }
      if ($mutation->kind !== LeaseMutation::WAITING) {
        throw new LeaseRejectedException(LeaseRejectedException::STATE);
      }
      $message = $this->messages->append(
        $actor,
        (int) $workspace->id(),
        (int) $board->id(),
        (int) $task->id(),
        'question',
        $body,
        NULL,
        (int) $execution->id(),
      );
      $requestUuid = $this->uuid->generate();
      $this->database->insert('ab_input_request')
        ->fields([
          'uuid' => $requestUuid,
          'workspace' => (int) $workspace->id(),
          'task_id' => (int) $task->id(),
          'execution_id' => (int) $execution->id(),
          'question_message_id' => $message->messageId,
          'status' => 'open',
          'answer_message_id' => NULL,
          'created' => $this->time->getRequestTime(),
          'resolved_at' => NULL,
        ])
        ->execute();
      $this->messages->recordCreated(
        $actor,
        (string) $workspace->uuid(),
        (string) $board->uuid(),
        (string) $task->uuid(),
        $message,
      );
      $this->events->record(new EventDraft(
        (string) $workspace->uuid(),
        'input.requested',
        $requestUuid,
        $actor->actorType,
        $actor->actorId,
        [
          'execution_id' => (string) $execution->uuid(),
          'task_id' => (string) $task->uuid(),
          'message_id' => $message->messageUuid,
        ],
      ));
      $this->afterRecord();
      $this->forget($mutation);
      $this->cacheTagsInvalidator->invalidateTags([
        'ab_workspace_messages:' . $workspace->id(),
        'ab_agent_signal:' . $agent->id(),
      ]);
      return new InputRequest($requestUuid, $message->messageUuid, (string) $execution->uuid());
    }
    catch (LeaseRejectedException $exception) {
      if ($exception->getMessage() !== LeaseRejectedException::EXPIRED) {
        $transaction->rollBack();
      }
      throw $exception;
    }
    catch (MessageRejectedException $exception) {
      if ($exception->getMessage() !== MessageRejectedException::BUDGET) {
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
   * Stores a human answer and resolves one open request.
   *
   * The execution returns to running only when every question of the
   * live lease is answered. An interrupted execution is not revived.
   */
  public function answer(ActorContext $actor, string $requestUuid, string $body): void {
    $body = $this->preparedBody($body);
    if ($actor->actorType !== ActorContext::TYPE_HUMAN) {
      throw new InputRequestRejectedException(InputRequestRejectedException::ACTOR);
    }
    $request = $this->loadRequest($requestUuid);
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($request['workspace']);
    if (!$workspace instanceof Workspace || (string) $workspace->uuid() !== $actor->workspaceId) {
      throw new ScopedResourceNotFoundException('Input request was not found.');
    }
    if (!$actor->allows(WorkspacePermission::READ)) {
      throw new ScopedResourceNotFoundException('Input request was not found.');
    }
    if (!$actor->allows(WorkspacePermission::WRITE)) {
      throw new ScopedOperationDeniedException('The conversation cannot be changed.');
    }
    if ((string) $request['status'] !== 'open') {
      throw new InputRequestRejectedException(InputRequestRejectedException::STATE);
    }
    $task = $this->entityTypeManager->getStorage('ab_task')->load($request['task_id']);
    $execution = $this->entityTypeManager->getStorage('ab_execution')->load($request['execution_id']);
    if (!$task instanceof Task || !$execution instanceof Execution) {
      throw new ScopedResourceNotFoundException('Input request was not found.');
    }
    $board = $this->boardOf($task);
    $agentId = (int) $execution->get('agent')->getString();
    $transaction = $this->database->startTransaction();
    try {
      $this->lockAgent($agentId);
      $this->lockBoard((int) $board->id());
      $this->lockTask((int) $task->id());
      $lease = $this->database->query(
        'SELECT execution_id, expires_at FROM {ab_execution_lease} WHERE task_id = :task FOR UPDATE',
        [':task' => (int) $task->id()],
      )->fetchAssoc();
      $executionRow = $this->database->query(
        'SELECT id, status, version FROM {ab_execution} WHERE id = :id FOR UPDATE',
        [':id' => (int) $execution->id()],
      )->fetchAssoc();
      $locked = $this->database->query(
        'SELECT id, status, question_message_id FROM {ab_input_request} WHERE id = :id FOR UPDATE',
        [':id' => (int) $request['id']],
      )->fetchAssoc();
      if (!is_array($executionRow) || !is_array($locked) || (string) $locked['status'] !== 'open') {
        throw new InputRequestRejectedException(InputRequestRejectedException::STATE);
      }
      $questionUuid = $this->database->select('ab_message', 'm')
        ->fields('m', ['uuid'])
        ->condition('id', (int) $locked['question_message_id'])
        ->execute()
        ->fetchField();
      if (!is_string($questionUuid) || $questionUuid === '') {
        throw new ScopedResourceNotFoundException('Input request was not found.');
      }
      $message = $this->messages->append(
        $actor,
        (int) $workspace->id(),
        (int) $board->id(),
        (int) $task->id(),
        'answer',
        $body,
        $questionUuid,
        NULL,
      );
      $now = $this->time->getRequestTime();
      $this->database->update('ab_input_request')
        ->fields([
          'status' => 'answered',
          'answer_message_id' => $message->messageId,
          'resolved_at' => $now,
        ])
        ->condition('id', (int) $request['id'])
        ->condition('status', 'open')
        ->execute();
      $open = (int) $this->database->select('ab_input_request', 'r')
        ->condition('execution_id', (int) $execution->id())
        ->condition('status', 'open')
        ->countQuery()
        ->execute()
        ->fetchField();
      $leaseHoldsThis = is_array($lease) && (int) $lease['execution_id'] === (int) $execution->id();
      $leaseAlive = $leaseHoldsThis && (int) $lease['expires_at'] > $now;
      if ($open === 0 && (string) $executionRow['status'] === 'waiting_input' && $leaseAlive) {
        $this->database->update('ab_execution')
          ->fields([
            'status' => 'running',
            'version' => ((int) $executionRow['version']) + 1,
            'changed' => $now,
          ])
          ->condition('id', (int) $execution->id())
          ->condition('status', 'waiting_input')
          ->execute();
      }
      $this->messages->recordCreated(
        $actor,
        (string) $workspace->uuid(),
        (string) $board->uuid(),
        (string) $task->uuid(),
        $message,
      );
      $this->events->record(new EventDraft(
        (string) $workspace->uuid(),
        'input.answered',
        $requestUuid,
        $actor->actorType,
        $actor->actorId,
        [
          'execution_id' => (string) $execution->uuid(),
          'task_id' => (string) $task->uuid(),
          'message_id' => $message->messageUuid,
        ],
      ));
      $this->afterRecord();
      $this->entityTypeManager->getStorage('ab_task')->resetCache([(int) $task->id()]);
      $this->entityTypeManager->getStorage('ab_execution')->resetCache([(int) $execution->id()]);
      $this->cacheTagsInvalidator->invalidateTags([
        'ab_workspace_tasks:' . $workspace->id(),
        'ab_workspace_messages:' . $workspace->id(),
        'ab_agent_signal:' . $agentId,
      ]);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Lists questions of one task for a reader of that workspace.
   *
   * @return \Drupal\ab_communication\InputRequestView[]
   *   Open questions first in creation order, then answered ones.
   */
  public function forTask(ActorContext $actor, Task $task): array {
    $workspace = $this->workspaceOf($task);
    if ((string) $workspace->uuid() !== $actor->workspaceId || !$actor->allows(WorkspacePermission::READ)) {
      return [];
    }
    $rows = $this->database->select('ab_input_request', 'r');
    $rows->join('ab_message', 'q', 'q.id = r.question_message_id');
    $rows->leftJoin('ab_message', 'a', 'a.id = r.answer_message_id');
    $rows->fields('r', ['uuid', 'status', 'created', 'id']);
    $rows->addField('q', 'body', 'question');
    $rows->addField('a', 'body', 'answer');
    $rows->addField('a', 'actor_type', 'author_type');
    $rows->addField('a', 'actor_id', 'author_id');
    $rows->condition('r.task_id', (int) $task->id());
    $rows->condition('r.workspace', (int) $workspace->id());
    $rows->orderBy('r.created');
    $rows->orderBy('r.id');
    $items = [];
    foreach ($rows->execute() as $row) {
      if (!is_object($row)) {
        continue;
      }
      $answer = isset($row->answer) && is_string($row->answer) && $row->answer !== '' ? $row->answer : NULL;
      $authorType = isset($row->author_type) && is_string($row->author_type) ? $row->author_type : NULL;
      $authorId = isset($row->author_id) && is_string($row->author_id) ? $row->author_id : NULL;
      $items[] = new InputRequestView(
        (string) $row->uuid,
        (string) $row->status,
        (string) $row->question,
        $answer,
        $authorType,
        $authorId,
      );
    }
    return $items;
  }

  /**
   * Runs after the event, still inside the transaction.
   *
   * Tests override this to prove a failure rolls the write back.
   */
  protected function afterRecord(): void {
  }

  /**
   * Rejects an empty body and a body over the message limit.
   */
  private function preparedBody(string $body): string {
    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
    if ($body === '' || strlen($body) > MessageService::BODY_LIMIT) {
      throw new \InvalidArgumentException('Message body is invalid.');
    }
    return $body;
  }

  /**
   * Confirms the locked token may still post a message.
   */
  private function assertMessageScope(int $agentId, string $tokenId): void {
    $scopes = $this->database->query(
      'SELECT scopes FROM {ab_agent_token} WHERE token_id = :token AND agent_id = :agent',
      [
        ':token' => $tokenId,
        ':agent' => $agentId,
      ],
    )->fetchField();
    $granted = $scopes === FALSE ? [] : explode(',', (string) $scopes);
    if (!in_array(AgentTokenScope::MESSAGES_WRITE, $granted, TRUE)) {
      throw new AgentAccessDeniedException();
    }
  }

  /**
   * Holds the agent row before the board and the task.
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
   * Holds the board row before the task row.
   */
  private function lockBoard(int $boardId): void {
    $locked = $this->database->query(
      'SELECT id FROM {ab_board} WHERE id = :id FOR UPDATE',
      [':id' => $boardId],
    )->fetchField();
    if ($locked === FALSE) {
      throw new ScopedResourceNotFoundException('Execution was not found.');
    }
  }

  /**
   * Holds the task row before the lease row.
   */
  private function lockTask(int $taskId): void {
    $locked = $this->database->query(
      'SELECT id FROM {ab_task} WHERE id = :id FOR UPDATE',
      [':id' => $taskId],
    )->fetchField();
    if ($locked === FALSE) {
      throw new ScopedResourceNotFoundException('Input request was not found.');
    }
  }

  /**
   * Drops cached rows after an execution status change.
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
   * Loads a request row by its public uuid.
   *
   * @return array<string, mixed>
   *   The stored request.
   */
  private function loadRequest(string $uuid): array {
    $row = $this->database->select('ab_input_request', 'r')
      ->fields('r')
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchAssoc();
    if (!is_array($row)) {
      throw new ScopedResourceNotFoundException('Input request was not found.');
    }
    return $row;
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
