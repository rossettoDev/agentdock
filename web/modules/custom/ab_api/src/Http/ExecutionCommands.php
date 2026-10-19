<?php

declare(strict_types=1);

namespace Drupal\ab_api\Http;

use Drupal\ab_agent\ClaimedTask;
use Drupal\ab_agent\ClaimRejectedException;
use Drupal\ab_agent\ClaimWriter;
use Drupal\ab_agent\ExecutionMaintenance;
use Drupal\ab_agent\ExecutionRejectedException;
use Drupal\ab_agent\LeaseRejectedException;
use Drupal\ab_agent\RenewedLease;
use Drupal\ab_agent\TaskClaim;
use Drupal\ab_agent\TaskReview;
use Drupal\ab_api\ApiException;
use Drupal\ab_api\IdempotencyKey;
use Drupal\ab_api\IdempotencyService;
use Drupal\ab_api\JsonPayload;
use Drupal\ab_api\PageQuery;
use Drupal\ab_api\ProblemDetails;
use Drupal\ab_api\StoredResponse;
use Drupal\ab_board\Entity\Task as BoardTask;
use Drupal\ab_board\TaskVersionConflictException;
use Drupal\ab_communication\InputRequest;
use Drupal\ab_communication\InputRequestRejectedException;
use Drupal\ab_communication\InputRequestService;
use Drupal\ab_communication\MessagePage;
use Drupal\ab_communication\MessageRejectedException;
use Drupal\ab_communication\MessageService;
use Drupal\ab_communication\PostedMessage;
use Drupal\ab_communication\StoredMessage;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\UuidLookup;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runs the execution cycle and the conversation commands.
 *
 * The lease token stays in the header. Claim stores it only as
 * ciphertext. A finished lease drops that replay. Status changes go
 * through the existing services, and an agent cannot review.
 */
final class ExecutionCommands {

  /**
   * Generation the current lease writer starts at.
   *
   * The HTTP contract carries the lease token, not this counter.
   */
  private const GENERATION = 1;

  public function __construct(
    private readonly ApiCaller $caller,
    private readonly IdempotencyService $idempotency,
    private readonly TaskDocument $documents,
    private readonly TaskClaim $claims,
    private readonly ExecutionMaintenance $executions,
    private readonly InputRequestService $inputs,
    private readonly MessageService $messages,
    private readonly TaskReview $reviews,
    private readonly UuidLookup $uuids,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Claims a ready task and seals the one-time lease token.
   */
  public function claim(Request $request, string $workspaceUuid, string $taskUuid): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    $this->object($request, []);
    return $this->idempotency->execute(
      $actor,
      'task.claim',
      $taskUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($actor, $taskUuid): StoredResponse {
        $claimed = $this->guard(fn () => $this->claims->claim($actor, $taskUuid));
        if (!$claimed instanceof ClaimedTask) {
          throw new \RuntimeException('The claim was not stored.');
        }
        $data = [
          'execution_id' => $claimed->executionUuid,
          'task_id' => $claimed->taskUuid,
          'lease_token' => $claimed->leaseToken,
          'lease_expires_at' => $this->documents->time($claimed->leaseExpiresAt),
          'task_version' => $claimed->taskVersion,
        ];
        return new StoredResponse(201, $this->encode($data));
      },
      TRUE,
      $this->time->getRequestTime() + ClaimWriter::LEASE_TTL,
    );
  }

  /**
   * Renews the lease. A replay does not renew it again.
   */
  public function heartbeat(Request $request, string $workspaceUuid, string $executionUuid): StoredResponse {
    $this->object($request, []);
    return $this->leaseCommand($request, $workspaceUuid, $executionUuid, 'execution.heartbeat', function (ActorContext $actor, string $lease) use ($executionUuid): array {
      $renewed = $this->guard(fn () => $this->executions->heartbeat($actor, $executionUuid, $lease, self::GENERATION));
      if (!$renewed instanceof RenewedLease) {
        throw new \RuntimeException('The heartbeat did not renew the lease.');
      }
      return [200, ['lease_expires_at' => $this->documents->time($renewed->leaseExpiresAt)]];
    });
  }

  /**
   * Stores one progress note.
   */
  public function progress(Request $request, string $workspaceUuid, string $executionUuid): StoredResponse {
    $body = $this->object($request, ['note']);
    $note = $body['note'] ?? NULL;
    if (!is_string($note)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return $this->leaseCommand($request, $workspaceUuid, $executionUuid, 'execution.progress', function (ActorContext $actor, string $lease) use ($executionUuid, $note): array {
      $this->guard(fn () => $this->executions->progress($actor, $executionUuid, $lease, self::GENERATION, $note));
      return [201, $this->progressNote($executionUuid)];
    });
  }

  /**
   * Asks a human a question and keeps the lease.
   */
  public function requestInput(Request $request, string $workspaceUuid, string $executionUuid): StoredResponse {
    $body = $this->object($request, ['body']);
    $text = $body['body'] ?? NULL;
    if (!is_string($text)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return $this->leaseCommand($request, $workspaceUuid, $executionUuid, 'execution.request_input', function (ActorContext $actor, string $lease) use ($executionUuid, $text): array {
      $opened = $this->guard(fn () => $this->inputs->request($actor, $executionUuid, $lease, self::GENERATION, $text));
      if (!$opened instanceof InputRequest) {
        throw new \RuntimeException('The question was not stored.');
      }
      return [201, [
        'id' => $opened->requestUuid,
        'execution_id' => $opened->executionUuid,
        'message_id' => $opened->messageUuid,
        'status' => 'open',
      ]];
    });
  }

  /**
   * Answers one open question. An agent cannot answer.
   */
  public function answer(Request $request, string $workspaceUuid, string $requestUuid): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    $body = $this->object($request, ['body']);
    $text = $body['body'] ?? NULL;
    if (!is_string($text)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return $this->idempotency->execute(
      $actor,
      'input.answer',
      $requestUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($actor, $requestUuid, $text): StoredResponse {
        $this->guard(fn () => $this->inputs->answer($actor, $requestUuid, $text));
        return new StoredResponse(201, $this->encode($this->answerMessage($requestUuid)));
      },
    );
  }

  /**
   * Delivers the result and drops the sealed claim replay.
   */
  public function submit(Request $request, string $workspaceUuid, string $executionUuid): StoredResponse {
    $body = $this->object($request, ['summary', 'links']);
    $summary = $body['summary'] ?? NULL;
    if (!is_string($summary)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $links = $this->links($body['links'] ?? []);
    return $this->leaseCommand($request, $workspaceUuid, $executionUuid, 'execution.submit', function (ActorContext $actor, string $lease) use ($executionUuid, $summary, $links): array {
      $this->guard(fn () => $this->executions->submit($actor, $executionUuid, $lease, self::GENERATION, $summary, $links));
      $this->forgetClaim($actor, $executionUuid);
      return [200, $this->execution($executionUuid)];
    });
  }

  /**
   * Records a failure and drops the sealed claim replay.
   */
  public function fail(Request $request, string $workspaceUuid, string $executionUuid): StoredResponse {
    $body = $this->object($request, ['code', 'detail']);
    $code = $body['code'] ?? NULL;
    if (!is_string($code)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return $this->leaseCommand($request, $workspaceUuid, $executionUuid, 'execution.fail', function (ActorContext $actor, string $lease) use ($executionUuid, $code): array {
      $this->guard(fn () => $this->executions->fail($actor, $executionUuid, $lease, self::GENERATION, $code));
      $this->forgetClaim($actor, $executionUuid);
      return [200, $this->execution($executionUuid)];
    });
  }

  /**
   * Releases the lease and drops the sealed claim replay.
   */
  public function release(Request $request, string $workspaceUuid, string $executionUuid): StoredResponse {
    $this->object($request, []);
    return $this->leaseCommand($request, $workspaceUuid, $executionUuid, 'execution.release', function (ActorContext $actor, string $lease) use ($executionUuid): array {
      $this->guard(fn () => $this->executions->release($actor, $executionUuid, $lease, self::GENERATION));
      $this->forgetClaim($actor, $executionUuid);
      return [200, $this->execution($executionUuid)];
    });
  }

  /**
   * Approves a delivery. The caller has to be a human reviewer.
   */
  public function approve(Request $request, string $workspaceUuid, string $taskUuid): StoredResponse {
    $this->object($request, ['version']);
    return $this->review($request, $workspaceUuid, $taskUuid, 'task.approve', function (string $uuid, int $version): void {
      $this->reviews->approve($uuid, $version);
    });
  }

  /**
   * Returns a delivery to ready.
   */
  public function reject(Request $request, string $workspaceUuid, string $taskUuid): StoredResponse {
    $body = $this->object($request, ['version', 'reason']);
    $reason = $body['reason'] ?? NULL;
    if (!is_string($reason)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return $this->review($request, $workspaceUuid, $taskUuid, 'task.reject', function (string $uuid, int $version) use ($reason): void {
      $this->reviews->reject($uuid, $version, $reason);
    });
  }

  /**
   * Reads one conversation page.
   *
   * @return array<string, mixed>
   *   The page.
   */
  public function messages(Request $request, string $workspaceUuid, string $threadUuid): array {
    $actor = $this->caller->actor($request, $workspaceUuid);
    $limit = $this->limit($request);
    $cursor = $request->query->get('cursor');
    if ($cursor !== NULL && !is_string($cursor)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $page = $this->guard(fn () => $this->messages->read($actor, $threadUuid, $cursor, $limit));
    if (!$page instanceof MessagePage) {
      throw new \RuntimeException('The conversation was not read.');
    }
    $data = [];
    foreach ($page->messages as $message) {
      if ($message instanceof StoredMessage) {
        $data[] = $this->message($threadUuid, $message);
      }
    }
    return [
      'data' => $data,
      'page' => [
        'limit' => $limit,
        'next_cursor' => $page->cursor,
      ],
    ];
  }

  /**
   * Appends a message to a thread the caller can write.
   */
  public function createMessage(Request $request, string $workspaceUuid, string $threadUuid): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    $body = $this->object($request, ['kind', 'body', 'reply_to', 'mentions']);
    $kind = $body['kind'] ?? NULL;
    $text = $body['body'] ?? NULL;
    if (!is_string($kind) || !is_string($text)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $reply = $body['reply_to'] ?? NULL;
    if ($reply !== NULL && !is_string($reply)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $place = $this->thread($workspaceUuid, $threadUuid);
    $mentions = $this->mentions($body['mentions'] ?? []);
    return $this->idempotency->execute(
      $actor,
      'message.create',
      $threadUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($actor, $place, $kind, $text, $reply, $mentions, $threadUuid): StoredResponse {
        $posted = $this->guard(fn () => $this->messages->post(
          $actor,
          $place['scope'],
          $place['uuid'],
          $kind,
          $text,
          $reply,
          NULL,
          $mentions,
        ));
        if (!$posted instanceof PostedMessage) {
          throw new \RuntimeException('The message was not stored.');
        }
        return new StoredResponse(201, $this->encode($this->storedMessage($threadUuid, $posted->messageUuid)));
      },
    );
  }

  /**
   * Runs one lease command and stores only the success.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string $workspaceUuid
   *   Workspace the path names.
   * @param string $executionUuid
   *   Execution the path names.
   * @param string $operation
   *   Idempotency operation name.
   * @param callable(ActorContext, string): array{0: int, 1: array<string, mixed>} $effect
   *   Status and document. The lease argument is the header value.
   */
  private function leaseCommand(Request $request, string $workspaceUuid, string $executionUuid, string $operation, callable $effect): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    $lease = $this->lease($request);
    return $this->idempotency->execute(
      $actor,
      $operation,
      $executionUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($actor, $lease, $effect): StoredResponse {
        [$status, $data] = $effect($actor, $lease);
        return new StoredResponse($status, $this->encode($data));
      },
    );
  }

  /**
   * Runs a human review.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string $workspaceUuid
   *   Workspace the path names.
   * @param string $taskUuid
   *   Task the path names.
   * @param string $operation
   *   Idempotency operation name.
   * @param callable(string, int): void $decide
   *   Approve or reject.
   */
  private function review(Request $request, string $workspaceUuid, string $taskUuid, string $operation, callable $decide): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    if ($actor->actorType === ActorContext::TYPE_AGENT || !$actor->allows(WorkspacePermission::REVIEW)) {
      throw new ScopedOperationDeniedException('The task cannot be changed.');
    }
    $version = $this->version($request);
    return $this->idempotency->execute(
      $actor,
      $operation,
      $taskUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($taskUuid, $version, $decide): StoredResponse {
        $this->guard(function () use ($decide, $taskUuid, $version): void {
          $decide($taskUuid, $version);
        });
        return new StoredResponse(200, $this->encode($this->task($taskUuid)));
      },
    );
  }

  /**
   * Requires a JSON object whose keys are on the whitelist.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string[] $allowed
   *   Field names the route accepts.
   *
   * @return array<string, mixed>
   *   The object.
   */
  private function object(Request $request, array $allowed): array {
    $body = JsonPayload::object($request->getContent());
    if (array_diff(array_keys($body), $allowed) !== []) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return $body;
  }

  /**
   * Reads the lease header without echoing it.
   */
  private function lease(Request $request): string {
    $header = $request->headers->get('X-Execution-Lease');
    if (!is_string($header) || $header === '') {
      throw new ApiException(428, 'precondition_required', 'X-Execution-Lease is required.');
    }
    return $header;
  }

  /**
   * Reads the review version from the body.
   */
  private function version(Request $request): int {
    $body = JsonPayload::object($request->getContent());
    $stated = $body['version'] ?? NULL;
    if (!is_int($stated) && !(is_string($stated) && preg_match('/^[1-9][0-9]*$/', $stated) === 1)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return (int) $stated;
  }

  /**
   * Maps domain failures to problem responses inside the reservation.
   */
  private function guard(callable $call): mixed {
    try {
      return $call();
    }
    catch (LeaseRejectedException | ClaimRejectedException | ExecutionRejectedException) {
      throw new ApiException(409, 'conflict', ProblemDetails::fixedDetail(409));
    }
    catch (MessageRejectedException $exception) {
      $status = $exception->getMessage() === MessageRejectedException::KIND ? 403 : 422;
      $code = $status === 403 ? 'forbidden' : 'invalid';
      throw new ApiException($status, $code, ProblemDetails::fixedDetail($status));
    }
    catch (InputRequestRejectedException $exception) {
      if ($exception->getMessage() === InputRequestRejectedException::ACTOR) {
        throw new ApiException(403, 'forbidden', ProblemDetails::fixedDetail(403));
      }
      throw new ApiException(409, 'conflict', ProblemDetails::fixedDetail(409));
    }
    catch (TaskVersionConflictException) {
      throw new ApiException(412, 'stale_version', ProblemDetails::fixedDetail(412));
    }
    catch (\InvalidArgumentException) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
  }

  /**
   * Drops the sealed claim once the lease is gone.
   */
  private function forgetClaim(ActorContext $actor, string $executionUuid): void {
    $task = $this->database->query(
      'SELECT t.uuid FROM {ab_execution} e INNER JOIN {ab_task} t ON t.id = e.task WHERE e.uuid = :uuid',
      [':uuid' => $executionUuid],
    )->fetchField();
    if (is_string($task) && $task !== '') {
      $this->idempotency->discardSealed($actor, 'task.claim', $task);
    }
  }

  /**
   * Reads the note just stored for this execution.
   *
   * @return array<string, mixed>
   *   The public note.
   */
  private function progressNote(string $executionUuid): array {
    $row = $this->database->query(
      'SELECT p.sequence, p.body, p.created FROM {ab_execution_progress} p INNER JOIN {ab_execution} e ON e.id = p.execution_id WHERE e.uuid = :uuid ORDER BY p.sequence DESC',
      [':uuid' => $executionUuid],
    )->fetchAssoc();
    if (!is_array($row)) {
      throw new ScopedResourceNotFoundException('Execution was not found.');
    }
    return [
      'execution_id' => $executionUuid,
      'sequence' => (int) $row['sequence'],
      'body' => (string) $row['body'],
      'created_at' => $this->documents->time($row['created']),
    ];
  }

  /**
   * Reads the execution the contract shows, without the lease secret.
   *
   * @return array<string, mixed>
   *   The public execution.
   */
  private function execution(string $executionUuid): array {
    $row = $this->database->query(
      'SELECT e.uuid AS id, t.uuid AS task_id, a.uuid AS agent_id, e.status, e.attempt, e.version, e.started_at, e.lease_expires_at, e.heartbeat_at, e.ended_at, e.result_summary, e.failure_code FROM {ab_execution} e INNER JOIN {ab_task} t ON t.id = e.task INNER JOIN {ab_agent} a ON a.id = e.agent WHERE e.uuid = :uuid',
      [':uuid' => $executionUuid],
    )->fetchAssoc();
    if (!is_array($row)) {
      throw new ScopedResourceNotFoundException('Execution was not found.');
    }
    return [
      'id' => (string) $row['id'],
      'task_id' => (string) $row['task_id'],
      'agent_id' => (string) $row['agent_id'],
      'status' => (string) $row['status'],
      'attempt' => (int) $row['attempt'],
      'version' => (int) $row['version'],
      'started_at' => $this->documents->time($row['started_at']),
      'lease_expires_at' => $this->documents->time($row['lease_expires_at']),
      'heartbeat_at' => $this->documents->time($row['heartbeat_at']),
      'ended_at' => $this->documents->time($row['ended_at']),
      'result_summary' => (string) $row['result_summary'],
      'failure_code' => (string) $row['failure_code'],
    ];
  }

  /**
   * Reads the answer stored for one request.
   *
   * @return array<string, mixed>
   *   The public message.
   */
  private function answerMessage(string $requestUuid): array {
    $row = $this->database->query(
      'SELECT m.uuid, m.kind, m.body, m.created, t.uuid AS thread_id, p.uuid AS reply_to FROM {ab_input_request} r INNER JOIN {ab_message} m ON m.id = r.answer_message_id INNER JOIN {ab_thread} t ON t.id = m.thread_id LEFT JOIN {ab_message} p ON p.id = m.reply_to WHERE r.uuid = :uuid',
      [':uuid' => $requestUuid],
    )->fetchAssoc();
    if (!is_array($row)) {
      throw new ScopedResourceNotFoundException('Input request was not found.');
    }
    $reply = is_string($row['reply_to']) && $row['reply_to'] !== '' ? $row['reply_to'] : NULL;
    return [
      'id' => (string) $row['uuid'],
      'thread_id' => (string) $row['thread_id'],
      'kind' => (string) $row['kind'],
      'body' => (string) $row['body'],
      'reply_to' => $reply,
      'created_at' => $this->documents->time($row['created']),
    ];
  }

  /**
   * Reads one stored message.
   *
   * @return array<string, mixed>
   *   The public message.
   */
  private function storedMessage(string $threadUuid, string $messageUuid): array {
    $row = $this->database->query(
      'SELECT m.kind, m.body, m.created, p.uuid AS reply_to FROM {ab_message} m LEFT JOIN {ab_message} p ON p.id = m.reply_to WHERE m.uuid = :uuid',
      [':uuid' => $messageUuid],
    )->fetchAssoc();
    if (!is_array($row)) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
    $reply = is_string($row['reply_to']) && $row['reply_to'] !== '' ? $row['reply_to'] : NULL;
    return [
      'id' => $messageUuid,
      'thread_id' => $threadUuid,
      'kind' => (string) $row['kind'],
      'body' => (string) $row['body'],
      'reply_to' => $reply,
      'created_at' => $this->documents->time($row['created']),
    ];
  }

  /**
   * Builds one page item.
   *
   * @return array<string, mixed>
   *   The public message.
   */
  private function message(string $threadUuid, StoredMessage $message): array {
    return [
      'id' => $message->uuid,
      'thread_id' => $threadUuid,
      'kind' => $message->kind,
      'body' => $message->body,
      'reply_to' => $message->replyTo,
      'created_at' => $this->documents->time($message->created),
    ];
  }

  /**
   * Loads the task document after a review.
   *
   * @return array<string, mixed>
   *   The public task.
   */
  private function task(string $taskUuid): array {
    $task = $this->uuids->load('ab_task', $taskUuid);
    if (!$task instanceof BoardTask) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
    return $this->documents->task($task);
  }

  /**
   * Finds the board or task a thread belongs to.
   *
   * @return array{scope: string, uuid: string}
   *   The scope passed to the message service.
   */
  private function thread(string $workspaceUuid, string $threadUuid): array {
    if (!preg_match('/^[0-9a-f-]{36}$/', $threadUuid)) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
    $row = $this->database->query(
      'SELECT t.task_id, b.uuid AS board_uuid, k.uuid AS task_uuid FROM {ab_thread} t INNER JOIN {ab_board} b ON b.id = t.board_id INNER JOIN {ab_workspace} w ON w.id = t.workspace LEFT JOIN {ab_task} k ON k.id = t.task_id WHERE t.uuid = :thread AND w.uuid = :workspace',
      [
        ':thread' => $threadUuid,
        ':workspace' => $workspaceUuid,
      ],
    )->fetchAssoc();
    if (!is_array($row)) {
      throw new ScopedResourceNotFoundException('Conversation was not found.');
    }
    if ((int) $row['task_id'] > 0 && is_string($row['task_uuid'])) {
      return ['scope' => 'task', 'uuid' => $row['task_uuid']];
    }
    return ['scope' => 'board', 'uuid' => (string) $row['board_uuid']];
  }

  /**
   * Translates public mention ids into the ids the service stores.
   *
   * @param mixed $mentions
   *   Mention list from the body.
   *
   * @return array<int, array<string, string>>
   *   Mentions for MessageService.
   */
  private function mentions(mixed $mentions): array {
    if ($mentions === []) {
      return [];
    }
    if (!is_array($mentions)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $normalized = [];
    foreach ($mentions as $mention) {
      if (!is_array($mention) || !is_string($mention['type'] ?? NULL) || !is_string($mention['id'] ?? NULL)) {
        throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
      }
      if ($mention['type'] === 'human') {
        $normalized[] = ['type' => 'human', 'id' => (string) $this->userId($mention['id'])];
      }
      elseif ($mention['type'] === 'agent') {
        $normalized[] = ['type' => 'agent', 'id' => $mention['id']];
      }
      else {
        throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
      }
    }
    return $normalized;
  }

  /**
   * Resolves a user UUID to an account id.
   */
  private function userId(string $uuid): int {
    if (preg_match('/^[0-9a-f-]{36}$/', $uuid) !== 1) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $id = $this->database->query('SELECT uid FROM {users} WHERE uuid = :uuid', [
      ':uuid' => $uuid,
    ])->fetchField();
    if ($id === FALSE) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return (int) $id;
  }

  /**
   * Turns contract link strings into the pairs the writer stores.
   *
   * @param mixed $links
   *   Link list from the body.
   *
   * @return array<int, array{url: string, label: string}>
   *   Links for the delivery.
   */
  private function links(mixed $links): array {
    if ($links === []) {
      return [];
    }
    if (!is_array($links)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $clean = [];
    foreach ($links as $link) {
      if (!is_string($link)) {
        throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
      }
      $clean[] = ['url' => $link, 'label' => ''];
    }
    return $clean;
  }

  /**
   * Reads the page size.
   */
  private function limit(Request $request): int {
    $raw = $request->query->get('limit');
    if ($raw !== NULL && !is_string($raw)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return PageQuery::limit($raw);
  }

  /**
   * Encodes a data envelope.
   *
   * @param array<string, mixed> $data
   *   The public document.
   */
  private function encode(array $data): string {
    return json_encode(['data' => $data], JSON_THROW_ON_ERROR);
  }

}
