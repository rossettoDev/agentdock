<?php

declare(strict_types=1);

namespace Drupal\ab_communication;

use Drupal\ab_agent\AgentAccessDeniedException;
use Drupal\ab_agent\AgentOperationGate;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_agent\ExecutionWriter;
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
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Opens one thread per board or task and appends messages.
 *
 * The author is the actor. A second structural create returns the
 * same thread. Messages are insert-only. Question does not open an
 * input request; that wait is a later story.
 */
class MessageService {

  /**
   * Maximum stored body length, in bytes.
   */
  public const BODY_LIMIT = 16384;

  /**
   * Default page size for a conversation.
   */
  public const PAGE_DEFAULT = 50;

  /**
   * Maximum page size.
   */
  public const PAGE_MAX = 100;

  /**
   * Agent messages allowed on one execution.
   */
  public const MESSAGE_BUDGET = 50;

  /**
   * Seconds an execution may run before a message ends the lease.
   */
  public const DURATION_BUDGET = 7200;

  /**
   * Kinds a human may write.
   */
  private const HUMAN_KINDS = ['comment', 'answer'];

  /**
   * Kinds an agent may write.
   */
  private const AGENT_KINDS = ['comment', 'progress', 'question', 'result'];

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventStoreInterface $events,
    private readonly AgentOperationGate $gate,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly ExecutionWriter $leases,
    private readonly NotificationService $notifications,
  ) {}

  /**
   * Appends a message and creates the thread when it is missing.
   */
  public function post(
    ActorContext $actor,
    string $scope,
    string $scopeUuid,
    string $kind,
    string $body,
    ?string $replyTo = NULL,
    ?string $executionUuid = NULL,
    array $mentions = [],
  ): PostedMessage {
    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
    if ($body === '' || strlen($body) > self::BODY_LIMIT) {
      throw new \InvalidArgumentException('Message body is invalid.');
    }
    $place = $this->loadScope($actor, $scope, $scopeUuid);
    $this->assertKind($actor, $kind);
    $this->assertMayWrite($actor, $place['board']);
    $recipients = $this->recipients($place, $mentions);
    $transaction = $this->database->startTransaction();
    try {
      if ($actor->actorType === ActorContext::TYPE_AGENT) {
        $this->lockAgent($place['agentId']);
        $this->assertAgentStill($actor, $place);
      }
      $this->lockBoard($place['boardId']);
      if ($place['taskId'] > 0) {
        $this->lockTask($place['taskId'], $place['boardId']);
      }
      $this->guardBudget($actor, $place);
      $thread = $this->ensureThread($place);
      $replyId = $this->replyId($thread['id'], $replyTo);
      $executionId = $this->executionId($actor, $place, $executionUuid);
      $messageUuid = $this->uuid->generate();
      $now = $this->time->getRequestTime();
      $messageId = (int) $this->database->insert('ab_message')
        ->fields([
          'uuid' => $messageUuid,
          'workspace' => $place['workspaceId'],
          'thread_id' => $thread['id'],
          'actor_type' => $actor->actorType,
          'actor_id' => $actor->actorId,
          'kind' => $kind,
          'body' => $body,
          'reply_to' => $replyId,
          'execution_id' => $executionId,
          'created' => $now,
        ])
        ->execute();
      $signals = $this->storeMentions($place, $messageId, $recipients);
      foreach ($signals as $signal) {
        if ($signal['user_id'] !== NULL) {
          $this->notifications->project($signal['event_id'], $place['workspaceId'], $signal['user_id']);
        }
      }
      $payload = [
        'thread_id' => $thread['uuid'],
        'kind' => $kind,
        'board_id' => $place['boardUuid'],
      ];
      if ($place['taskUuid'] !== '') {
        $payload['task_id'] = $place['taskUuid'];
      }
      $this->events->record(new EventDraft(
        $place['workspaceUuid'],
        'message.created',
        $messageUuid,
        $actor->actorType,
        $actor->actorId,
        $payload,
      ));
      foreach ($signals as $signal) {
        $this->events->record(new EventDraft(
          $place['workspaceUuid'],
          'message.mentioned',
          $signal['uuid'],
          $actor->actorType,
          $actor->actorId,
          [
            'message_id' => $messageUuid,
            'thread_id' => $thread['uuid'],
            'recipient_type' => $signal['type'],
            'recipient_id' => $signal['id'],
          ],
          [EventStoreInterface::DESTINATION_SYNC],
          $signal['event_id'],
        ));
      }
      $this->afterRecord();
      $this->cacheTagsInvalidator->invalidateTags([
        'ab_workspace_messages:' . $place['workspaceId'],
      ]);
      return new PostedMessage($messageUuid, $thread['uuid'], $kind);
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
   * Inserts one message while the caller holds the transaction.
   *
   * The caller locks the agent, the board and the task first, then
   * records the event after every domain row for this operation.
   */
  public function append(
    ActorContext $actor,
    int $workspaceId,
    int $boardId,
    int $taskId,
    string $kind,
    string $body,
    ?string $replyTo = NULL,
    ?int $executionId = NULL,
  ): AppendedMessage {
    if (!$this->database->inTransaction()) {
      throw new \RuntimeException('A message append needs an open transaction.');
    }
    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
    if ($body === '' || strlen($body) > self::BODY_LIMIT) {
      throw new \InvalidArgumentException('Message body is invalid.');
    }
    $this->assertKind($actor, $kind);
    if ($actor->actorType === ActorContext::TYPE_AGENT && $taskId > 0) {
      $this->guardBudget($actor, $this->budgetPlace($workspaceId, $boardId, $taskId, $actor->actorId));
    }
    $thread = $this->ensureThread([
      'workspaceId' => $workspaceId,
      'boardId' => $boardId,
      'taskId' => $taskId,
    ]);
    $messageUuid = $this->uuid->generate();
    $id = $this->database->insert('ab_message')
      ->fields([
        'uuid' => $messageUuid,
        'workspace' => $workspaceId,
        'thread_id' => $thread['id'],
        'actor_type' => $actor->actorType,
        'actor_id' => $actor->actorId,
        'kind' => $kind,
        'body' => $body,
        'reply_to' => $this->replyId($thread['id'], $replyTo),
        'execution_id' => $executionId,
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
    return new AppendedMessage((int) $id, $messageUuid, $thread['uuid'], $kind);
  }

  /**
   * Records message.created without the body.
   */
  public function recordCreated(
    ActorContext $actor,
    string $workspaceUuid,
    string $boardUuid,
    string $taskUuid,
    AppendedMessage $message,
  ): void {
    $payload = [
      'thread_id' => $message->threadUuid,
      'kind' => $message->kind,
      'board_id' => $boardUuid,
    ];
    if ($taskUuid !== '') {
      $payload['task_id'] = $taskUuid;
    }
    $this->events->record(new EventDraft(
      $workspaceUuid,
      'message.created',
      $message->messageUuid,
      $actor->actorType,
      $actor->actorId,
      $payload,
    ));
  }

  /**
   * Returns the thread uuid when one already exists.
   */
  public function findThreadUuid(ActorContext $actor, string $scope, string $scopeUuid): ?string {
    $place = $this->loadScope($actor, $scope, $scopeUuid);
    $this->assertMayRead($actor, $place['board']);
    $uuid = $this->database->select('ab_thread', 't')
      ->fields('t', ['uuid'])
      ->condition('board_id', $place['boardId'])
      ->condition('task_id', $place['taskId'])
      ->execute()
      ->fetchField();
    return is_string($uuid) && $uuid !== '' ? $uuid : NULL;
  }

  /**
   * Reads one page of a thread the actor can see.
   */
  public function read(ActorContext $actor, string $threadUuid, ?string $cursor, int $limit): MessagePage {
    if ($limit < 1 || $limit > self::PAGE_MAX) {
      throw new \InvalidArgumentException('Message page size is invalid.');
    }
    $thread = $this->visibleThread($actor, $threadUuid);
    $before = $this->cursor($cursor);
    $query = $this->database->select('ab_message', 'm');
    $query->leftJoin('ab_message', 'p', 'p.id = m.reply_to');
    $query->fields('m', [
      'id',
      'uuid',
      'actor_type',
      'actor_id',
      'kind',
      'body',
      'created',
    ]);
    $query->addField('p', 'uuid', 'reply_uuid');
    $query->condition('m.thread_id', $thread['id']);
    if ($before !== NULL) {
      $older = $query->orConditionGroup()
        ->condition('m.created', $before['created'], '<')
        ->condition(
          $query->andConditionGroup()
            ->condition('m.created', $before['created'])
            ->condition('m.id', $before['id'], '<'),
        );
      $query->condition($older);
    }
    $query->orderBy('m.created', 'DESC');
    $query->orderBy('m.id', 'DESC');
    $query->range(0, $limit + 1);
    $rows = $query->execute()->fetchAll();
    $more = count($rows) > $limit;
    if ($more) {
      array_pop($rows);
    }
    $oldest = $rows === [] ? NULL : $rows[array_key_last($rows)];
    $next = NULL;
    if ($more && is_object($oldest)) {
      $next = ((int) $oldest->created) . '.' . ((int) $oldest->id);
    }
    $rows = array_reverse($rows);
    $messages = [];
    foreach ($rows as $row) {
      if (!is_object($row)) {
        continue;
      }
      $reply = isset($row->reply_uuid) && is_string($row->reply_uuid) && $row->reply_uuid !== ''
        ? $row->reply_uuid
        : NULL;
      $messages[] = new StoredMessage(
        (string) $row->uuid,
        (string) $row->actor_type,
        (string) $row->actor_id,
        (string) $row->kind,
        (string) $row->body,
        $reply,
        (int) $row->created,
        (int) $row->id,
      );
    }
    return new MessagePage($messages, $next);
  }

  /**
   * Lists conversations in the actor workspace.
   */
  public function listThreads(ActorContext $actor): array {
    $workspace = $this->workspaceByUuid($actor->workspaceId);
    if (!$workspace instanceof Workspace) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
    if ($actor->actorType === ActorContext::TYPE_HUMAN && !$actor->allows(WorkspacePermission::READ)) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
    $threads = $this->database->select('ab_thread', 't')
      ->fields('t', ['id', 'uuid', 'board_id', 'task_id'])
      ->condition('workspace', (int) $workspace->id())
      ->execute()
      ->fetchAll();
    $rows = [];
    foreach ($threads as $thread) {
      if (!is_object($thread)) {
        continue;
      }
      $last = $this->database->select('ab_message', 'm')
        ->fields('m', ['body', 'created', 'id'])
        ->condition('thread_id', (int) $thread->id)
        ->orderBy('created', 'DESC')
        ->orderBy('id', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchObject();
      if ($last === FALSE || !is_object($last)) {
        continue;
      }
      $board = $this->entityTypeManager->getStorage('ab_board')->load($thread->board_id);
      if (!$board instanceof Board) {
        continue;
      }
      $taskUuid = NULL;
      $taskLabel = '';
      if ((int) $thread->task_id > 0) {
        $task = $this->entityTypeManager->getStorage('ab_task')->load($thread->task_id);
        if ($task instanceof Task) {
          $taskUuid = (string) $task->uuid();
          $taskLabel = (string) $task->label();
        }
      }
      $rows[] = [
        'thread' => (string) $thread->uuid,
        'board' => $board,
        'board_uuid' => (string) $board->uuid(),
        'board_name' => (string) $board->label(),
        'task_uuid' => $taskUuid,
        'task_title' => $taskLabel,
        'body' => (string) $last->body,
        'created' => (int) $last->created,
        'id' => (int) $last->id,
      ];
    }
    usort($rows, static function (array $left, array $right): int {
      if ($left['created'] === $right['created']) {
        return $right['id'] <=> $left['id'];
      }
      return $right['created'] <=> $left['created'];
    });
    $items = [];
    foreach ($rows as $row) {
      $board = $row['board'];
      if (!$board instanceof Board) {
        continue;
      }
      if ($actor->actorType === ActorContext::TYPE_AGENT) {
        try {
          $this->gate->assert($actor, $board, AgentTokenScope::MESSAGES_READ);
        }
        catch (AgentAccessDeniedException) {
          continue;
        }
      }
      elseif ($actor->actorType !== ActorContext::TYPE_HUMAN) {
        continue;
      }
      $items[] = new ThreadSummary(
        $row['thread'],
        $row['board_uuid'],
        $row['board_name'],
        $row['task_uuid'],
        $row['task_title'],
        $this->excerpt($row['body']),
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
   * Checks structured recipients before the message is stored.
   *
   * Text such as @name is not a recipient and does not start work.
   *
   * @return array<int, array<string, mixed>>
   *   Recipients that may be stored with the message.
   */
  private function recipients(array $place, array $mentions): array {
    $normalized = [];
    $seen = [];
    foreach ($mentions as $mention) {
      if (!is_array($mention) || !isset($mention['type'], $mention['id'])) {
        throw new MessageRejectedException(MessageRejectedException::MENTION);
      }
      $type = (string) $mention['type'];
      $id = (string) $mention['id'];
      $key = $type . ':' . $id;
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      if ($type === ActorContext::TYPE_HUMAN) {
        $this->assertHumanRecipient($place, $id);
        $normalized[] = [
          'type' => $type,
          'id' => $id,
          'user_id' => (int) $id,
        ];
      }
      elseif ($type === ActorContext::TYPE_AGENT) {
        $this->assertAgentRecipient($place, $id);
        $normalized[] = [
          'type' => $type,
          'id' => $id,
          'user_id' => NULL,
        ];
      }
      else {
        throw new MessageRejectedException(MessageRejectedException::MENTION);
      }
    }
    return $normalized;
  }

  /**
   * Stores mention rows and returns the events still to record.
   *
   * @param array<string, mixed> $place
   *   The locked board, task and workspace ids.
   * @param int $messageId
   *   The stored message id.
   * @param array<int, array<string, mixed>> $recipients
   *   Recipients already checked.
   *
   * @return array<int, array<string, mixed>>
   *   Mention ids and the people who get a notification.
   */
  private function storeMentions(array $place, int $messageId, array $recipients): array {
    $signals = [];
    $now = $this->time->getRequestTime();
    foreach ($recipients as $recipient) {
      $uuid = $this->uuid->generate();
      $signals[] = [
        'uuid' => $uuid,
        'event_id' => $this->uuid->generate(),
        'type' => (string) $recipient['type'],
        'id' => (string) $recipient['id'],
        'user_id' => $recipient['user_id'],
      ];
      $this->database->insert('ab_message_mention')
        ->fields([
          'uuid' => $uuid,
          'workspace' => $place['workspaceId'],
          'message_id' => $messageId,
          'recipient_type' => (string) $recipient['type'],
          'recipient_id' => (string) $recipient['id'],
          'created' => $now,
        ])
        ->execute();
    }
    return $signals;
  }

  /**
   * Ends the lease when this execution is over its message budget.
   */
  private function guardBudget(ActorContext $actor, array $place): void {
    if ($actor->actorType !== ActorContext::TYPE_AGENT || (int) $place['taskId'] <= 0) {
      return;
    }
    $execution = $this->database->query(
      "SELECT id, uuid, started_at, agent FROM {ab_execution} WHERE task = :task AND status IN ('running', 'waiting_input')",
      [
        ':task' => (int) $place['taskId'],
      ],
    )->fetchAssoc();
    if (!is_array($execution)) {
      return;
    }
    $started = (int) $execution['started_at'];
    $count = (int) $this->database->query(
      "SELECT COUNT(*) FROM {ab_message} m INNER JOIN {ab_thread} t ON t.id = m.thread_id WHERE t.task_id = :task AND t.board_id = :board AND m.actor_type = :actor AND m.created >= :started",
      [
        ':task' => (int) $place['taskId'],
        ':board' => (int) $place['boardId'],
        ':actor' => ActorContext::TYPE_AGENT,
        ':started' => $started,
      ],
    )->fetchField();
    $now = $this->time->getRequestTime();
    $overTime = $started > 0 && ($now - $started) >= self::DURATION_BUDGET;
    if ($count < self::MESSAGE_BUDGET && !$overTime) {
      return;
    }
    $holderUuid = $this->database->query(
      'SELECT uuid FROM {ab_agent} WHERE id = :id',
      [':id' => (int) $execution['agent']],
    )->fetchField();
    if (!is_string($holderUuid) || $holderUuid === '') {
      throw new \RuntimeException('The execution holder was not stored.');
    }
    $this->leases->interruptBudget(
      (int) $place['workspaceId'],
      (string) $place['workspaceUuid'],
      (int) $execution['agent'],
      $holderUuid,
      (int) $execution['id'],
      (string) $execution['uuid'],
      (int) $place['taskId'],
      (string) $place['taskUuid'],
      (int) $place['boardId'],
      $overTime ? 'duration_budget' : 'message_budget',
    );
    $this->entityTypeManager->getStorage('ab_task')->resetCache([(int) $place['taskId']]);
    $this->entityTypeManager->getStorage('ab_execution')->resetCache([(int) $execution['id']]);
    $this->cacheTagsInvalidator->invalidateTags([
      'ab_workspace_tasks:' . $place['workspaceId'],
      'ab_agent_signal:' . $execution['agent'],
    ]);
    throw new MessageRejectedException(MessageRejectedException::BUDGET);
  }

  /**
   * Builds the place array used by the budget check.
   *
   * @return array<string, mixed>
   *   Identifiers for the open execution.
   */
  private function budgetPlace(int $workspaceId, int $boardId, int $taskId, string $agentUuid): array {
    $workspaceUuid = $this->database->query(
      'SELECT uuid FROM {ab_workspace} WHERE id = :id',
      [':id' => $workspaceId],
    )->fetchField();
    $taskUuid = $this->database->query(
      'SELECT uuid FROM {ab_task} WHERE id = :id',
      [':id' => $taskId],
    )->fetchField();
    $agentId = $this->database->query(
      'SELECT id FROM {ab_agent} WHERE uuid = :uuid AND workspace = :workspace',
      [
        ':uuid' => $agentUuid,
        ':workspace' => $workspaceId,
      ],
    )->fetchField();
    return [
      'workspaceId' => $workspaceId,
      'workspaceUuid' => is_string($workspaceUuid) ? $workspaceUuid : '',
      'boardId' => $boardId,
      'taskId' => $taskId,
      'taskUuid' => is_string($taskUuid) ? $taskUuid : '',
      'agentId' => $agentId === FALSE ? 0 : (int) $agentId,
    ];
  }

  /**
   * Refuses a person who cannot read this workspace.
   */
  private function assertHumanRecipient(array $place, string $userId): void {
    if (preg_match('/^[1-9][0-9]*$/', $userId) !== 1) {
      throw new MessageRejectedException(MessageRejectedException::MENTION);
    }
    $user = $this->entityTypeManager->getStorage('user')->load($userId);
    if (!$user instanceof UserInterface || (int) $user->get('status')->getString() !== 1) {
      throw new MessageRejectedException(MessageRejectedException::MENTION);
    }
    $membership = $this->database->select('ab_membership', 'm')
      ->fields('m', ['id'])
      ->condition('workspace', (int) $place['workspaceId'])
      ->condition('user', (int) $userId)
      ->condition('status', 'active')
      ->execute()
      ->fetchField();
    if ($membership === FALSE) {
      throw new MessageRejectedException(MessageRejectedException::MENTION);
    }
  }

  /**
   * Refuses an agent who cannot read this board.
   */
  private function assertAgentRecipient(array $place, string $agentUuid): void {
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $agentUuid) !== 1) {
      throw new MessageRejectedException(MessageRejectedException::MENTION);
    }
    $agentId = $this->database->query(
      'SELECT id, enabled FROM {ab_agent} WHERE uuid = :uuid AND workspace = :workspace',
      [
        ':uuid' => $agentUuid,
        ':workspace' => (int) $place['workspaceId'],
      ],
    )->fetchAssoc();
    if (!is_array($agentId) || (int) $agentId['enabled'] !== 1) {
      throw new MessageRejectedException(MessageRejectedException::MENTION);
    }
    $permissions = $this->database->query(
      'SELECT permissions FROM {ab_agent_board_grant} WHERE agent_id = :agent AND board_id = :board',
      [
        ':agent' => (int) $agentId['id'],
        ':board' => (int) $place['boardId'],
      ],
    )->fetchField();
    $granted = $permissions === FALSE ? [] : explode(',', (string) $permissions);
    if (!in_array(WorkspacePermission::READ, $granted, TRUE)) {
      throw new MessageRejectedException(MessageRejectedException::MENTION);
    }
  }

  /**
   * Refuses kinds the actor is not allowed to write.
   */
  private function assertKind(ActorContext $actor, string $kind): void {
    $allowed = match ($actor->actorType) {
      ActorContext::TYPE_HUMAN => self::HUMAN_KINDS,
      ActorContext::TYPE_AGENT => self::AGENT_KINDS,
      ActorContext::TYPE_SYSTEM => ['system'],
      default => [],
    };
    if (!in_array($kind, $allowed, TRUE)) {
      throw new MessageRejectedException(MessageRejectedException::KIND);
    }
  }

  /**
   * Requires write access for a human or an agent.
   */
  private function assertMayWrite(ActorContext $actor, Board $board): void {
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      $this->gate->assert($actor, $board, AgentTokenScope::MESSAGES_WRITE);
      return;
    }
    if ($actor->actorType === ActorContext::TYPE_SYSTEM) {
      return;
    }
    if (!$actor->allows(WorkspacePermission::READ)) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
    if (!$actor->allows(WorkspacePermission::WRITE)) {
      throw new ScopedOperationDeniedException('The conversation cannot be changed.');
    }
  }

  /**
   * Requires read access for a human or an agent.
   */
  private function assertMayRead(ActorContext $actor, Board $board): void {
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      $this->gate->assert($actor, $board, AgentTokenScope::MESSAGES_READ);
      return;
    }
    if ($actor->actorType !== ActorContext::TYPE_HUMAN || !$actor->allows(WorkspacePermission::READ)) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
  }

  /**
   * Loads the board or task inside the actor workspace.
   */
  private function loadScope(ActorContext $actor, string $scope, string $scopeUuid): array {
    if ($scope === 'board') {
      $board = $this->loadBoard($scopeUuid);
      $task = NULL;
    }
    elseif ($scope === 'task') {
      $task = $this->loadTask($scopeUuid);
      $board = $this->entityTypeManager->getStorage('ab_board')->load($task->get('board')->getString());
      if (!$board instanceof Board) {
        throw new ScopedResourceNotFoundException('Conversation was not found.');
      }
    }
    else {
      throw new \InvalidArgumentException('Message scope is invalid.');
    }
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($board->get('workspace')->getString());
    if (!$workspace instanceof Workspace || (string) $workspace->uuid() !== $actor->workspaceId) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
    if ($task instanceof Task && $task->get('workspace')->getString() !== $board->get('workspace')->getString()) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
    $agentId = 0;
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      $agentId = $this->agentId($actor->actorId, $workspace);
    }
    $taskUuid = $task instanceof Task ? (string) $task->uuid() : '';
    return [
      'board' => $board,
      'workspaceId' => (int) $workspace->id(),
      'workspaceUuid' => (string) $workspace->uuid(),
      'boardId' => (int) $board->id(),
      'boardUuid' => (string) $board->uuid(),
      'taskId' => $task instanceof Task ? (int) $task->id() : 0,
      'taskUuid' => $taskUuid,
      'agentId' => $agentId,
    ];
  }

  /**
   * Loads a thread the actor can read.
   */
  private function visibleThread(ActorContext $actor, string $threadUuid): array {
    $row = $this->database->select('ab_thread', 't')
      ->fields('t', ['id', 'workspace', 'board_id'])
      ->condition('uuid', $threadUuid)
      ->execute()
      ->fetchAssoc();
    if (!is_array($row)) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($row['workspace']);
    if (!$workspace instanceof Workspace || (string) $workspace->uuid() !== $actor->workspaceId) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
    $board = $this->entityTypeManager->getStorage('ab_board')->load($row['board_id']);
    if (!$board instanceof Board) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
    $this->assertMayRead($actor, $board);
    return [
      'id' => (int) $row['id'],
      'board' => $board,
    ];
  }

  /**
   * Inserts the thread or returns the one already stored.
   */
  private function ensureThread(array $place): array {
    $existing = $this->lockThread((int) $place['boardId'], (int) $place['taskId']);
    if ($existing !== NULL) {
      return $existing;
    }
    $uuid = $this->uuid->generate();
    try {
      $id = $this->database->insert('ab_thread')
        ->fields([
          'uuid' => $uuid,
          'workspace' => (int) $place['workspaceId'],
          'board_id' => (int) $place['boardId'],
          'task_id' => (int) $place['taskId'],
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      $existing = $this->lockThread((int) $place['boardId'], (int) $place['taskId']);
      if ($existing === NULL) {
        throw new \RuntimeException('The thread was not stored.');
      }
      return $existing;
    }
    return [
      'id' => (int) $id,
      'uuid' => $uuid,
    ];
  }

  /**
   * Holds the thread row when it already exists.
   */
  private function lockThread(int $boardId, int $taskId): ?array {
    $row = $this->database->query(
      'SELECT id, uuid FROM {ab_thread} WHERE board_id = :board AND task_id = :task FOR UPDATE',
      [
        ':board' => $boardId,
        ':task' => $taskId,
      ],
    )->fetchAssoc();
    if (!is_array($row) || !is_string($row['uuid'])) {
      return NULL;
    }
    return [
      'id' => (int) $row['id'],
      'uuid' => $row['uuid'],
    ];
  }

  /**
   * Resolves a reply inside the same thread.
   */
  private function replyId(int $threadId, ?string $replyTo): ?int {
    if ($replyTo === NULL || $replyTo === '') {
      return NULL;
    }
    $row = $this->database->select('ab_message', 'm')
      ->fields('m', ['id', 'thread_id'])
      ->condition('uuid', $replyTo)
      ->execute()
      ->fetchAssoc();
    if (!is_array($row) || (int) $row['thread_id'] !== $threadId) {
      throw new MessageRejectedException(MessageRejectedException::REPLY);
    }
    return (int) $row['id'];
  }

  /**
   * Links an execution only when it belongs to this agent and task.
   */
  private function executionId(ActorContext $actor, array $place, ?string $executionUuid): ?int {
    if ($executionUuid === NULL || $executionUuid === '') {
      return NULL;
    }
    if ($actor->actorType !== ActorContext::TYPE_AGENT || (int) $place['taskId'] === 0) {
      throw new MessageRejectedException(MessageRejectedException::EXECUTION);
    }
    $row = $this->database->select('ab_execution', 'e')
      ->fields('e', ['id', 'task', 'workspace', 'agent'])
      ->condition('uuid', $executionUuid)
      ->execute()
      ->fetchAssoc();
    if (!is_array($row)) {
      throw new MessageRejectedException(MessageRejectedException::EXECUTION);
    }
    $agentUuid = $this->database->select('ab_agent', 'a')
      ->fields('a', ['uuid'])
      ->condition('id', (int) $row['agent'])
      ->execute()
      ->fetchField();
    $sameTask = (int) $row['task'] === (int) $place['taskId'];
    $sameWorkspace = (int) $row['workspace'] === (int) $place['workspaceId'];
    $sameAgent = is_string($agentUuid) && $agentUuid === $actor->actorId;
    if (!$sameTask || !$sameWorkspace || !$sameAgent) {
      throw new MessageRejectedException(MessageRejectedException::EXECUTION);
    }
    return (int) $row['id'];
  }

  /**
   * Confirms the locked agent can still write on the board.
   */
  private function assertAgentStill(ActorContext $actor, array $place): void {
    $enabled = $this->database->query(
      'SELECT enabled FROM {ab_agent} WHERE id = :id',
      [':id' => (int) $place['agentId']],
    )->fetchField();
    $tokenId = $actor->tokenId;
    $token = is_string($tokenId) ? $this->database->query(
      'SELECT scopes, expires_at, revoked_at FROM {ab_agent_token} WHERE token_id = :token AND agent_id = :agent',
      [
        ':token' => $tokenId,
        ':agent' => (int) $place['agentId'],
      ],
    )->fetchAssoc() : FALSE;
    $now = $this->time->getRequestTime();
    $scopes = is_array($token) ? explode(',', (string) $token['scopes']) : [];
    $usable = (int) $enabled === 1
      && is_array($token)
      && $token['revoked_at'] === NULL
      && (int) $token['expires_at'] > $now
      && in_array(AgentTokenScope::MESSAGES_WRITE, $scopes, TRUE);
    $grant = $this->database->query(
      'SELECT permissions FROM {ab_agent_board_grant} WHERE agent_id = :agent AND board_id = :board',
      [
        ':agent' => (int) $place['agentId'],
        ':board' => (int) $place['boardId'],
      ],
    )->fetchField();
    $granted = $grant !== FALSE && in_array(WorkspacePermission::WRITE, explode(',', (string) $grant), TRUE);
    if (!$usable || !$granted) {
      throw new AgentAccessDeniedException();
    }
  }

  /**
   * Parses a cursor of created time and message id.
   */
  private function cursor(?string $cursor): ?array {
    if ($cursor === NULL || $cursor === '') {
      return NULL;
    }
    if (preg_match('/^(\d+)\.(\d+)$/', $cursor, $matches) !== 1) {
      throw new \InvalidArgumentException('Message cursor is invalid.');
    }
    return [
      'created' => (int) $matches[1],
      'id' => (int) $matches[2],
    ];
  }

  /**
   * Holds the agent row before the board row.
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
   * Holds the board row.
   */
  private function lockBoard(int $boardId): void {
    $locked = $this->database->query(
      'SELECT id FROM {ab_board} WHERE id = :id FOR UPDATE',
      [':id' => $boardId],
    )->fetchField();
    if ($locked === FALSE) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
  }

  /**
   * Holds the task row after the board row.
   */
  private function lockTask(int $taskId, int $boardId): void {
    $row = $this->database->query(
      'SELECT id, board FROM {ab_task} WHERE id = :id FOR UPDATE',
      [':id' => $taskId],
    )->fetchAssoc();
    if (!is_array($row) || (int) $row['board'] !== $boardId) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
  }

  /**
   * Loads a board by its public uuid.
   */
  private function loadBoard(string $uuid): Board {
    $ids = $this->entityTypeManager->getStorage('ab_board')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $uuid)
      ->execute();
    $board = $this->entityTypeManager->getStorage('ab_board')->load(reset($ids) ?: 0);
    if (!$board instanceof Board) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
    return $board;
  }

  /**
   * Loads a task by its public uuid.
   */
  private function loadTask(string $uuid): Task {
    $ids = $this->entityTypeManager->getStorage('ab_task')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $uuid)
      ->execute();
    $task = $this->entityTypeManager->getStorage('ab_task')->load(reset($ids) ?: 0);
    if (!$task instanceof Task) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
    return $task;
  }

  /**
   * Loads the workspace named by a uuid.
   */
  private function workspaceByUuid(string $uuid): ?Workspace {
    $ids = $this->entityTypeManager->getStorage('ab_workspace')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $uuid)
      ->execute();
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load(reset($ids) ?: 0);
    return $workspace instanceof Workspace ? $workspace : NULL;
  }

  /**
   * Resolves the agent id inside the workspace.
   */
  private function agentId(string $agentUuid, Workspace $workspace): int {
    $ids = $this->entityTypeManager->getStorage('ab_agent')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $agentUuid)
      ->condition('workspace', $workspace->id())
      ->execute();
    $agent = $this->entityTypeManager->getStorage('ab_agent')->load(reset($ids) ?: 0);
    if (!$agent instanceof Agent) {
      throw new AgentAccessDeniedException();
    }
    return (int) $agent->id();
  }

  /**
   * Shortens a body for the conversation list.
   */
  private function excerpt(string $body): string {
    $flat = trim(preg_replace('/\s+/', ' ', $body) ?? '');
    if (mb_strlen($flat) <= 80) {
      return $flat;
    }
    return mb_substr($flat, 0, 80);
  }

}
