<?php

declare(strict_types=1);

namespace Drupal\ab_api\Http;

use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_api\ApiException;
use Drupal\ab_api\ApiLimits;
use Drupal\ab_api\PageQuery;
use Drupal\ab_api\ProblemDetails;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads a board snapshot, later events, and the agent inbox.
 *
 * Tasks and the cursor come from one statement, so a commit cannot sit
 * in the tasks and past the cursor. A later poll therefore still sees it.
 * Filtered events advance the cursor without being returned.
 */
final class SyncReader {

  /**
   * Rows inspected while filling one page.
   */
  private const SCAN = 200;

  public function __construct(
    private readonly Connection $database,
    private readonly ApiCaller $caller,
    private readonly Catalog $catalog,
    private readonly TaskDocument $documents,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns events the caller can read after the cursor.
   *
   * @return array<string, mixed>
   *   An event page.
   */
  public function events(Request $request, string $workspaceUuid): array {
    $actor = $this->caller->actor($request, $workspaceUuid);
    $this->caller->assertScope($actor, AgentTokenScope::EVENTS_READ);
    $workspace = $this->caller->workspace($workspaceUuid);
    $after = $this->cursor($request->query->get('cursor'));
    $this->assertFresh($workspaceUuid, $after);
    $limit = PageQuery::limit($this->queryValue($request, 'limit'));
    $readable = $this->readableBoards($actor, (int) $workspace->id());
    return $this->page($actor, $workspace, $after, $limit, $readable, FALSE);
  }

  /**
   * Returns the board tasks and the sequence from one statement.
   *
   * @return array<string, mixed>
   *   The snapshot.
   */
  public function snapshot(Request $request, string $workspaceUuid, string $boardUuid): array {
    $board = $this->catalog->boardIn($request, $workspaceUuid, $boardUuid, AgentTokenScope::TASKS_READ);
    $workspace = $this->caller->workspace($workspaceUuid);
    $rows = $this->database->query(
      'SELECT t.uuid, t.title, t.description, t.acceptance_criteria, t.status, t.priority, t.human_owner, t.assigned_agent, t.due_at, t.position, t.version, t.active_execution, t.workspace, t.board, seq.sequence FROM (SELECT COALESCE((SELECT sequence FROM {ab_workspace_sequence} WHERE workspace_id = :workspace), 0) AS sequence) seq LEFT JOIN {ab_task} t ON t.board = :board ORDER BY t.position, t.id',
      [
        ':workspace' => (string) $workspace->uuid(),
        ':board' => (int) $board->id(),
      ],
    )->fetchAll();
    $tasks = [];
    $sequence = 0;
    foreach ($rows as $row) {
      $stored = (array) $row;
      $sequence = (int) ($stored['sequence'] ?? 0);
      if (!is_string($stored['uuid'] ?? NULL) || $stored['uuid'] === '') {
        continue;
      }
      $tasks[] = $this->documents->captured($stored);
    }
    return [
      'data' => $tasks,
      'cursor' => (string) $sequence,
    ];
  }

  /**
   * Returns mention signals for the authenticated agent.
   *
   * @return array<string, mixed>
   *   An inbox page. A mention does not start an execution.
   */
  public function inbox(Request $request, string $workspaceUuid): array {
    $actor = $this->caller->actor($request, $workspaceUuid);
    if ($actor->actorType !== ActorContext::TYPE_AGENT) {
      throw new ScopedOperationDeniedException('The inbox is not available.');
    }
    $this->caller->assertScope($actor, AgentTokenScope::EVENTS_READ);
    $workspace = $this->caller->workspace($workspaceUuid);
    $after = $this->cursor($request->query->get('cursor'));
    $this->assertFresh($workspaceUuid, $after);
    $limit = PageQuery::limit($this->queryValue($request, 'limit'));
    $readable = $this->readableBoards($actor, (int) $workspace->id());
    return $this->page($actor, $workspace, $after, $limit, $readable, TRUE);
  }

  /**
   * Fills one page and moves the cursor past events that were inspected.
   *
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   The authenticated caller.
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace being read.
   * @param int $after
   *   Last sequence the caller already has.
   * @param int $limit
   *   Maximum visible rows in the page.
   * @param array<string, true> $readable
   *   Board UUIDs the caller can read.
   * @param bool $inbox
   *   TRUE when the page is the agent inbox.
   *
   * @return array<string, mixed>
   *   A page whose cursor passed every inspected event.
   */
  private function page(ActorContext $actor, Workspace $workspace, int $after, int $limit, array $readable, bool $inbox): array {
    $documents = [];
    $last = $after;
    $workspaceId = (int) $workspace->id();
    while (count($documents) < $limit) {
      $rows = $this->database->query(
        'SELECT event_id, sequence, type, resource_id, actor_type, actor_id, created, payload FROM {ab_event} WHERE workspace_id = :workspace AND sequence > :after ORDER BY sequence ASC LIMIT ' . self::SCAN,
        [
          ':workspace' => (string) $workspace->uuid(),
          ':after' => $last,
        ],
      )->fetchAll();
      if ($rows === []) {
        break;
      }
      foreach ($rows as $row) {
        $stored = (array) $row;
        $sequence = (int) ($stored['sequence'] ?? 0);
        if ($sequence <= $last) {
          continue;
        }
        $last = $sequence;
        $document = $inbox
          ? $this->signal($actor, $workspaceId, $stored, $readable)
          : $this->eventDocument($actor, $workspaceId, $stored, $readable);
        if ($document === NULL) {
          continue;
        }
        $documents[] = $document;
        if (count($documents) === $limit) {
          break;
        }
      }
      if (count($rows) < self::SCAN) {
        break;
      }
    }
    $more = $this->database->query(
      'SELECT 1 FROM {ab_event} WHERE workspace_id = :workspace AND sequence > :after',
      [
        ':workspace' => (string) $workspace->uuid(),
        ':after' => $last,
      ],
    )->fetchField();
    return [
      'data' => $documents,
      'page' => [
        'limit' => $limit,
        'next_cursor' => $more === FALSE ? NULL : (string) $last,
        'cursor' => $last > $after ? (string) $last : NULL,
      ],
    ];
  }

  /**
   * Returns an event document when the caller can read its board.
   *
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   The authenticated caller.
   * @param int $workspaceId
   *   Internal workspace id.
   * @param array<string, mixed> $row
   *   One stored event.
   * @param array<string, true> $readable
   *   Board UUIDs the caller can read.
   *
   * @return array<string, mixed>|null
   *   The public event, or NULL when it stays hidden.
   */
  private function eventDocument(ActorContext $actor, int $workspaceId, array $row, array $readable): ?array {
    if (!$this->allowed($actor, $workspaceId, $row, $readable)) {
      return NULL;
    }
    $payload = $this->payload($row);
    return [
      'event_id' => (string) $row['event_id'],
      'sequence' => (int) $row['sequence'],
      'type' => (string) $row['type'],
      'resource_id' => (string) $row['resource_id'],
      'actor_type' => (string) $row['actor_type'],
      'actor_id' => (string) $row['actor_id'],
      'created_at' => $this->documents->time($row['created']),
      'payload' => $payload,
    ];
  }

  /**
   * Returns a mention addressed to this agent on a board it can read.
   *
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   The authenticated agent.
   * @param int $workspaceId
   *   Internal workspace id.
   * @param array<string, mixed> $row
   *   One stored event.
   * @param array<string, true> $readable
   *   Board UUIDs the caller can read.
   *
   * @return array<string, mixed>|null
   *   The inbox item, or NULL when the event is not this agent's signal.
   */
  private function signal(ActorContext $actor, int $workspaceId, array $row, array $readable): ?array {
    if ((string) ($row['type'] ?? '') !== 'message.mentioned') {
      return NULL;
    }
    if (!$this->allowed($actor, $workspaceId, $row, $readable)) {
      return NULL;
    }
    $payload = $this->payload($row);
    $recipientType = $payload['recipient_type'] ?? NULL;
    $recipientId = $payload['recipient_id'] ?? NULL;
    if ($recipientType !== ActorContext::TYPE_AGENT || $recipientId !== $actor->actorId) {
      return NULL;
    }
    return [
      'event_id' => (string) $row['event_id'],
      'type' => 'message.mentioned',
      'resource_id' => (string) $row['resource_id'],
      'recipient_type' => ActorContext::TYPE_AGENT,
      'recipient_id' => $actor->actorId,
    ];
  }

  /**
   * Reports whether this caller may see the event.
   *
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   The authenticated caller.
   * @param int $workspaceId
   *   Internal workspace id.
   * @param array<string, mixed> $row
   *   One stored event.
   * @param array<string, true> $readable
   *   Board UUIDs the caller can read.
   */
  private function allowed(ActorContext $actor, int $workspaceId, array $row, array $readable): bool {
    $board = $this->boardOf($workspaceId, $row);
    if ($board === NULL) {
      return FALSE;
    }
    if ($board === '') {
      return $actor->actorType !== ActorContext::TYPE_AGENT;
    }
    return isset($readable[$board]);
  }

  /**
   * Resolves the board, or an empty string when the event has none.
   *
   * @param int $workspaceId
   *   Internal workspace id.
   * @param array<string, mixed> $row
   *   One stored event.
   *
   * @return string|null
   *   A board UUID, an empty string for a workspace event, or NULL to hide.
   */
  private function boardOf(int $workspaceId, array $row): ?string {
    $payload = $this->payload($row);
    $board = $payload['board_id'] ?? NULL;
    if (is_string($board) && $this->boardInWorkspace($workspaceId, $board)) {
      return $board;
    }
    $task = $payload['task_id'] ?? NULL;
    if (is_string($task)) {
      $found = $this->taskBoard($workspaceId, $task);
      if ($found !== NULL) {
        return $found;
      }
    }
    $thread = $payload['thread_id'] ?? NULL;
    if (is_string($thread)) {
      $found = $this->threadBoard($workspaceId, $thread);
      if ($found !== NULL) {
        return $found;
      }
    }
    $type = (string) ($row['type'] ?? '');
    $resource = (string) ($row['resource_id'] ?? '');
    if (str_starts_with($type, 'board.')) {
      return $this->boardInWorkspace($workspaceId, $resource) ? $resource : NULL;
    }
    if (str_starts_with($type, 'task.') || str_starts_with($type, 'execution.')) {
      return $this->taskBoard($workspaceId, $resource);
    }
    if (str_starts_with($type, 'message.') || str_starts_with($type, 'input.')) {
      return NULL;
    }
    return '';
  }

  /**
   * Decodes a payload object.
   *
   * @param array<string, mixed> $row
   *   One stored event.
   *
   * @return array<string, mixed>
   *   The payload, or an empty object when it is not one.
   */
  private function payload(array $row): array {
    $decoded = json_decode((string) ($row['payload'] ?? ''), TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Lists board UUIDs the caller can read now.
   *
   * @return array<string, true>
   *   Readable board UUIDs.
   */
  private function readableBoards(ActorContext $actor, int $workspaceId): array {
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      $rows = $this->database->query(
        'SELECT b.uuid, g.permissions FROM {ab_board} b INNER JOIN {ab_agent_board_grant} g ON g.board_id = b.id INNER JOIN {ab_agent} a ON a.id = g.agent_id WHERE b.workspace = :workspace AND a.uuid = :agent',
        [
          ':workspace' => $workspaceId,
          ':agent' => $actor->actorId,
        ],
      )->fetchAll();
      $readable = [];
      foreach ($rows as $row) {
        $stored = (array) $row;
        $permissions = explode(',', (string) ($stored['permissions'] ?? ''));
        if (is_string($stored['uuid'] ?? NULL) && in_array('read', $permissions, TRUE)) {
          $readable[$stored['uuid']] = TRUE;
        }
      }
      return $readable;
    }
    $uuids = $this->database->query(
      'SELECT uuid FROM {ab_board} WHERE workspace = :workspace',
      [':workspace' => $workspaceId],
    )->fetchCol();
    $readable = [];
    foreach ($uuids as $uuid) {
      if (is_string($uuid) && $uuid !== '') {
        $readable[$uuid] = TRUE;
      }
    }
    return $readable;
  }

  /**
   * Reports whether a board UUID belongs to this workspace.
   */
  private function boardInWorkspace(int $workspaceId, string $boardUuid): bool {
    if (!$this->uuid($boardUuid)) {
      return FALSE;
    }
    $found = $this->database->query(
      'SELECT 1 FROM {ab_board} WHERE uuid = :board AND workspace = :workspace',
      [
        ':board' => $boardUuid,
        ':workspace' => $workspaceId,
      ],
    )->fetchField();
    return $found !== FALSE;
  }

  /**
   * Returns the board UUID of a task in this workspace.
   */
  private function taskBoard(int $workspaceId, string $taskUuid): ?string {
    if (!$this->uuid($taskUuid)) {
      return NULL;
    }
    $board = $this->database->query(
      'SELECT b.uuid FROM {ab_task} t INNER JOIN {ab_board} b ON b.id = t.board WHERE t.uuid = :task AND b.workspace = :workspace',
      [
        ':task' => $taskUuid,
        ':workspace' => $workspaceId,
      ],
    )->fetchField();
    return is_string($board) && $board !== '' ? $board : NULL;
  }

  /**
   * Returns the board UUID of a thread in this workspace.
   */
  private function threadBoard(int $workspaceId, string $threadUuid): ?string {
    if (!$this->uuid($threadUuid)) {
      return NULL;
    }
    $board = $this->database->query(
      'SELECT b.uuid FROM {ab_thread} t INNER JOIN {ab_board} b ON b.id = t.board_id WHERE t.uuid = :thread AND b.workspace = :workspace',
      [
        ':thread' => $threadUuid,
        ':workspace' => $workspaceId,
      ],
    )->fetchField();
    return is_string($board) && $board !== '' ? $board : NULL;
  }

  /**
   * Rejects a cursor that would skip an expired event.
   */
  private function assertFresh(string $workspaceUuid, int $after): void {
    $cutoff = $this->time->getRequestTime() - $this->retention();
    if ($after === 0) {
      $expired = $this->database->query(
        'SELECT 1 FROM {ab_event} WHERE workspace_id = :workspace AND created < :cutoff',
        [
          ':workspace' => $workspaceUuid,
          ':cutoff' => $cutoff,
        ],
      )->fetchField();
      if ($expired !== FALSE) {
        throw new ApiException(410, 'resync_required', ProblemDetails::fixedDetail(410));
      }
      return;
    }
    $created = $this->database->query(
      'SELECT created FROM {ab_event} WHERE workspace_id = :workspace AND sequence <= :after ORDER BY sequence DESC LIMIT 1',
      [
        ':workspace' => $workspaceUuid,
        ':after' => $after,
      ],
    )->fetchField();
    if ($created !== FALSE && (int) $created < $cutoff) {
      throw new ApiException(410, 'resync_required', ProblemDetails::fixedDetail(410));
    }
  }

  /**
   * Reads the retention window. A setting overrides the default of 30 days.
   */
  private function retention(): int {
    $value = Settings::get('ab_event_retention', ApiLimits::EVENT_RETENTION);
    if (is_int($value) && $value >= 0) {
      return $value;
    }
    if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
      return (int) $value;
    }
    return ApiLimits::EVENT_RETENTION;
  }

  /**
   * Reads a sequence cursor. An empty value starts at the beginning.
   */
  private function cursor(mixed $value): int {
    if ($value === NULL || $value === '') {
      return 0;
    }
    if (!is_string($value) || strlen($value) > 18 || preg_match('/^[0-9]+$/', $value) !== 1) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return (int) $value;
  }

  /**
   * Reads one query parameter as a string.
   */
  private function queryValue(Request $request, string $name): ?string {
    $value = $request->query->get($name);
    if ($value === NULL || is_string($value)) {
      return $value;
    }
    throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
  }

  /**
   * Reports whether a value is a lowercase UUID.
   */
  private function uuid(string $value): bool {
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
  }

}
