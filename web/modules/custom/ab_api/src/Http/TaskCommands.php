<?php

declare(strict_types=1);

namespace Drupal\ab_api\Http;

use Drupal\ab_agent\AgentOperationGate;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_api\ApiException;
use Drupal\ab_api\IdempotencyKey;
use Drupal\ab_api\IdempotencyService;
use Drupal\ab_api\JsonPayload;
use Drupal\ab_api\ProblemDetails;
use Drupal\ab_api\StoredResponse;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskManager;
use Drupal\ab_board\TaskTransitionRejectedException;
use Drupal\ab_board\TaskVersionConflictException;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Creates and edits boards and tasks through the domain services.
 *
 * Status is not a PATCH field. The version comes from If-Match.
 * The actor is the authenticated caller.
 */
final class TaskCommands {

  /**
   * Fields a client may send when creating a task.
   */
  private const CREATE_FIELDS = [
    'title',
    'description',
    'acceptance_criteria',
    'priority',
    'human_owner',
    'due_at',
  ];

  public function __construct(
    private readonly ApiCaller $caller,
    private readonly Catalog $catalog,
    private readonly TaskDocument $documents,
    private readonly IdempotencyService $idempotency,
    private readonly BoardManager $boards,
    private readonly TaskManager $tasks,
    private readonly AgentOperationGate $gate,
    private readonly Connection $database,
  ) {}

  /**
   * Creates a board.
   */
  public function createBoard(Request $request, string $workspaceUuid): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    $this->assertHumanManage($actor);
    $body = JsonPayload::object($request->getContent());
    $name = $body['name'] ?? NULL;
    $description = $body['description'] ?? '';
    if (!is_string($name) || (array_key_exists('description', $body) && !is_string($description))) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    unset($body['name'], $body['description']);
    if ($body !== []) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $workspace = $this->caller->workspace($workspaceUuid);
    return $this->idempotency->execute(
      $actor,
      'board.create',
      $workspaceUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($workspace, $name, $description): StoredResponse {
        try {
          $board = $this->boards->create($workspace, $name, $description);
        }
        catch (\InvalidArgumentException) {
          throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
        }
        return new StoredResponse(201, $this->encode($this->documents->board($board)));
      },
    );
  }

  /**
   * Creates a task on a board.
   */
  public function createTask(Request $request, string $workspaceUuid, string $boardUuid): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    $board = $this->catalog->boardIn($request, $workspaceUuid, $boardUuid, AgentTokenScope::TASKS_WRITE);
    $this->assertMayWrite($actor, $board);
    $fields = $this->fields(JsonPayload::object($request->getContent()), TRUE);
    return $this->idempotency->execute(
      $actor,
      'task.create',
      $boardUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($board, $fields, $actor): StoredResponse {
        $created = $this->run(fn (): Task => $this->tasks->create($board, $fields, $this->agent($actor)));
        return new StoredResponse(201, $this->encode($this->documents->task($created)));
      },
    );
  }

  /**
   * Patches whitelist fields. Status is rejected.
   */
  public function updateTask(Request $request, string $workspaceUuid, string $taskUuid): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    $task = $this->catalog->taskIn($request, $workspaceUuid, $taskUuid);
    $board = $this->catalog->boardIn($request, $workspaceUuid, $this->boardUuid($task), AgentTokenScope::TASKS_WRITE);
    $this->assertMayWrite($actor, $board);
    $expected = $this->version($request);
    $fields = $this->fields(JsonPayload::object($request->getContent()), FALSE);
    return $this->idempotency->execute(
      $actor,
      'task.update',
      $taskUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($task, $fields, $expected, $actor): StoredResponse {
        $updated = $this->run(fn (): Task => $this->tasks->update($task, $fields, $expected, $this->agent($actor)));
        return new StoredResponse(200, $this->encode($this->documents->task($updated)));
      },
    );
  }

  /**
   * Moves a task through a human transition.
   */
  public function transition(Request $request, string $workspaceUuid, string $taskUuid): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      throw new ScopedOperationDeniedException('The task cannot be changed.');
    }
    if (!$actor->allows(WorkspacePermission::WRITE)) {
      throw new ScopedOperationDeniedException('The task cannot be changed.');
    }
    $task = $this->catalog->taskIn($request, $workspaceUuid, $taskUuid);
    $expected = $this->version($request);
    $body = JsonPayload::object($request->getContent());
    $target = $body['to'] ?? NULL;
    if (!is_string($target) || array_diff(array_keys($body), ['to', 'version']) !== []) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return $this->idempotency->execute(
      $actor,
      'task.transition',
      $taskUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($task, $target, $expected): StoredResponse {
        $moved = $this->run(fn (): Task => $this->tasks->transition($task, $target, $expected));
        return new StoredResponse(200, $this->encode($this->documents->task($moved)));
      },
    );
  }

  /**
   * Keeps the version header and the body version in agreement.
   */
  private function version(Request $request): int {
    $expected = $this->caller->ifMatch($request);
    $body = JsonPayload::object($request->getContent());
    $stated = $body['version'] ?? NULL;
    if (!is_int($stated) && !(is_string($stated) && preg_match('/^[1-9][0-9]*$/', $stated) === 1)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    if ((int) $stated !== $expected) {
      throw new ApiException(412, 'stale_version', ProblemDetails::fixedDetail(412));
    }
    return $expected;
  }

  /**
   * Normalizes a task body into manager fields.
   *
   * @param array<string, mixed> $body
   *   The JSON object.
   * @param bool $create
   *   TRUE when version is not a field.
   *
   * @return array<string, mixed>
   *   Whitelist values.
   */
  private function fields(array $body, bool $create): array {
    $allowed = self::CREATE_FIELDS;
    if (!$create) {
      $allowed[] = 'version';
    }
    if (array_diff(array_keys($body), $allowed) !== []) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $fields = [];
    foreach (self::CREATE_FIELDS as $name) {
      if (!array_key_exists($name, $body)) {
        continue;
      }
      $fields[$name] = $body[$name];
    }
    if (array_key_exists('human_owner', $fields)) {
      $fields['human_owner'] = $this->userId($fields['human_owner']);
    }
    if (array_key_exists('due_at', $fields)) {
      $fields['due_at'] = $this->due($fields['due_at']);
    }
    return $fields;
  }

  /**
   * Resolves a user UUID to an account id.
   */
  private function userId(mixed $value): ?int {
    if ($value === NULL) {
      return NULL;
    }
    if (!is_string($value) || preg_match('/^[0-9a-f-]{36}$/', $value) !== 1) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $id = $this->database->query('SELECT uid FROM {users} WHERE uuid = :uuid', [
      ':uuid' => $value,
    ])->fetchField();
    if ($id === FALSE) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return (int) $id;
  }

  /**
   * Parses a due date into a UTC timestamp.
   */
  private function due(mixed $value): ?int {
    if ($value === NULL) {
      return NULL;
    }
    if (!is_string($value) || $value === '') {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    try {
      return (new \DateTimeImmutable($value))->getTimestamp();
    }
    catch (\Exception) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
  }

  /**
   * Requires a human who can manage boards.
   */
  private function assertHumanManage(ActorContext $actor): void {
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      throw new ScopedOperationDeniedException('The board cannot be changed.');
    }
    if (!$actor->allows(WorkspacePermission::MANAGE_BOARDS)) {
      throw new ScopedOperationDeniedException('The board cannot be changed.');
    }
  }

  /**
   * Requires write access for the human or the agent gate.
   */
  private function assertMayWrite(ActorContext $actor, Board $board): void {
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      $this->gate->assert($actor, $board, AgentTokenScope::TASKS_WRITE);
      return;
    }
    if (!$actor->allows(WorkspacePermission::WRITE)) {
      throw new ScopedOperationDeniedException('The task cannot be changed.');
    }
  }

  /**
   * Passes an agent actor through and leaves humans to the session.
   */
  private function agent(ActorContext $actor): ?ActorContext {
    return $actor->actorType === ActorContext::TYPE_AGENT ? $actor : NULL;
  }

  /**
   * Runs a domain call and maps its safe failures.
   */
  private function run(callable $call): Task {
    try {
      $task = $call();
    }
    catch (TaskVersionConflictException) {
      throw new ApiException(412, 'stale_version', ProblemDetails::fixedDetail(412));
    }
    catch (TaskTransitionRejectedException) {
      throw new ApiException(409, 'conflict', ProblemDetails::fixedDetail(409));
    }
    catch (\InvalidArgumentException) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    if (!$task instanceof Task) {
      throw new \RuntimeException('The task was not stored.');
    }
    return $task;
  }

  /**
   * Encodes a data envelope.
   *
   * @param array<string, mixed> $data
   *   The public document.
   */
  private function encode(array $data): string {
    $json = json_encode(['data' => $data], JSON_THROW_ON_ERROR);
    return $json;
  }

  /**
   * Returns the board UUID stored on a task.
   */
  private function boardUuid(Task $task): string {
    $uuid = $this->database->query('SELECT uuid FROM {ab_board} WHERE id = :id', [
      ':id' => (int) $task->get('board')->getString(),
    ])->fetchField();
    return is_string($uuid) ? $uuid : '';
  }

}
