<?php

declare(strict_types=1);

namespace Drupal\ab_api\Http;

use Drupal\ab_agent\AgentOperationGate;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_api\ApiException;
use Drupal\ab_api\PageQuery;
use Drupal\ab_api\ProblemDetails;
use Drupal\ab_board\BoardRepository;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\UuidLookup;
use Drupal\ab_core\WorkspaceRepository;
use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads workspaces, boards, tasks and executions for one caller.
 *
 * Filters apply after the scope check. A grant the caller does not
 * hold is not a result, and a limit above 100 is rejected.
 */
final class Catalog {

  /**
   * Task statuses a filter may name.
   */
  private const STATUSES = [
    'backlog',
    'ready',
    'in_progress',
    'in_review',
    'done',
    'cancelled',
  ];

  /**
   * Priorities a filter may name.
   */
  private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

  public function __construct(
    private readonly Connection $database,
    private readonly ApiCaller $caller,
    private readonly TaskDocument $documents,
    private readonly WorkspaceRepository $workspaces,
    private readonly BoardRepository $boards,
    private readonly UuidLookup $uuids,
    private readonly AgentOperationGate $gate,
  ) {}

  /**
   * Lists workspaces the caller can read.
   *
   * @return array<string, mixed>
   *   A page of workspaces.
   */
  public function workspaces(Request $request): array {
    $limit = $this->limit($request);
    $after = $this->idCursor($request->query->get('cursor'));
    $agent = $this->caller->agent($request);
    if ($agent instanceof ActorContext) {
      $workspace = $this->caller->workspace($agent->workspaceId);
      $rows = [$workspace];
    }
    else {
      $this->caller->assertHuman();
      $rows = $this->workspaces->accessibleWorkspaces();
      usort($rows, static fn (Workspace $left, Workspace $right): int => ((int) $left->id()) <=> ((int) $right->id()));
    }
    $page = [];
    foreach ($rows as $workspace) {
      if ((int) $workspace->id() <= $after) {
        continue;
      }
      $page[] = $workspace;
    }
    return $this->slice($page, $limit, fn (Workspace $workspace): string => (string) $workspace->id(), fn (Workspace $workspace): array => $this->documents->workspace($workspace));
  }

  /**
   * Lists boards in one workspace.
   *
   * @return array<string, mixed>
   *   A page of boards.
   */
  public function boards(Request $request, string $workspaceUuid): array {
    $actor = $this->caller->actor($request, $workspaceUuid);
    $workspace = $this->caller->workspace($workspaceUuid);
    $limit = $this->limit($request);
    $cursor = $this->nameCursor($request->query->get('cursor'));
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      $this->caller->assertScope($actor, AgentTokenScope::BOARDS_READ);
      $rows = $this->grantedBoards($actor, $workspace);
    }
    else {
      $rows = $this->boards->accessibleBoards($workspace);
    }
    usort($rows, static function (Board $left, Board $right): int {
      $byName = strcmp((string) $left->label(), (string) $right->label());
      if ($byName !== 0) {
        return $byName;
      }
      return ((int) $left->id()) <=> ((int) $right->id());
    });
    $page = [];
    foreach ($rows as $board) {
      if ($cursor !== NULL && !$this->afterName($board, $cursor)) {
        continue;
      }
      $page[] = $board;
    }
    return $this->slice($page, $limit, fn (Board $board): string => $this->nameToken($board), fn (Board $board): array => $this->documents->board($board));
  }

  /**
   * Lists tasks on one board.
   *
   * @return array<string, mixed>
   *   A page of tasks.
   */
  public function tasks(Request $request, string $workspaceUuid, string $boardUuid): array {
    $board = $this->boardIn($request, $workspaceUuid, $boardUuid, AgentTokenScope::TASKS_READ);
    $limit = $this->limit($request);
    $filters = $this->filters($request);
    $cursor = $this->taskCursor($request->query->get('cursor'));
    $ids = $this->taskIds((int) $board->id(), $filters, $cursor, $limit + 1);
    $loaded = [];
    foreach ($ids as $id) {
      $loaded[] = $this->loadTask($this->taskUuid($id));
    }
    return $this->slice($loaded, $limit, fn (Task $task): string => $this->taskToken($task), fn (Task $task): array => $this->documents->task($task));
  }

  /**
   * Reads one task.
   *
   * @return array<string, mixed>
   *   The public task.
   */
  public function task(Request $request, string $workspaceUuid, string $taskUuid): array {
    $task = $this->taskIn($request, $workspaceUuid, $taskUuid);
    return $this->documents->task($task);
  }

  /**
   * Reads one execution without the lease secret.
   *
   * @return array<string, mixed>
   *   The public execution.
   */
  public function execution(Request $request, string $workspaceUuid, string $executionUuid): array {
    $actor = $this->caller->actor($request, $workspaceUuid);
    $workspace = $this->caller->workspace($workspaceUuid);
    $row = $this->executionRow((int) $workspace->id(), $executionUuid);
    if ($row === NULL) {
      throw new ScopedResourceNotFoundException('Execution was not found.');
    }
    $board = $this->uuids->load('ab_board', (string) $row['board_uuid']);
    if (!$board instanceof Board) {
      throw new ScopedResourceNotFoundException('Execution was not found.');
    }
    $this->visible($actor, $board, AgentTokenScope::TASKS_READ);
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
   * Loads a board in the path workspace and checks the caller.
   */
  public function boardIn(Request $request, string $workspaceUuid, string $boardUuid, string $scope): Board {
    $actor = $this->caller->actor($request, $workspaceUuid);
    $board = $this->loadBoard($boardUuid);
    $workspace = $this->caller->workspace($workspaceUuid);
    if ((string) $board->get('workspace')->getString() !== (string) $workspace->id()) {
      throw new ScopedResourceNotFoundException('Board was not found.');
    }
    $this->visible($actor, $board, $scope);
    return $board;
  }

  /**
   * Loads a task in the path workspace and checks the caller.
   */
  public function taskIn(Request $request, string $workspaceUuid, string $taskUuid): Task {
    $actor = $this->caller->actor($request, $workspaceUuid);
    $task = $this->loadTask($taskUuid);
    $workspace = $this->caller->workspace($workspaceUuid);
    if ((string) $task->get('workspace')->getString() !== (string) $workspace->id()) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
    $board = $this->loadBoard($this->boardUuid((int) $task->get('board')->getString()));
    $this->visible($actor, $board, AgentTokenScope::TASKS_READ);
    return $task;
  }

  /**
   * Requires a readable board for an agent and read access for a human.
   */
  private function visible(ActorContext $actor, Board $board, string $scope): void {
    if ($actor->actorType !== ActorContext::TYPE_AGENT) {
      return;
    }
    if (!$this->grantHasRead($actor, $board)) {
      throw new ScopedResourceNotFoundException('Board was not found.');
    }
    $this->gate->assert($actor, $board, $scope);
  }

  /**
   * Reports whether the agent can read this board.
   */
  private function grantHasRead(ActorContext $actor, Board $board): bool {
    $stored = $this->database->query(
      'SELECT g.permissions FROM {ab_agent_board_grant} g INNER JOIN {ab_agent} a ON a.id = g.agent_id WHERE a.uuid = :agent AND g.board_id = :board',
      [
        ':agent' => $actor->actorId,
        ':board' => (int) $board->id(),
      ],
    )->fetchField();
    if ($stored === FALSE) {
      return FALSE;
    }
    return in_array('read', explode(',', (string) $stored), TRUE);
  }

  /**
   * Returns boards the agent can read, ordered by name.
   *
   * @return \Drupal\ab_board\Entity\Board[]
   *   Readable boards.
   */
  private function grantedBoards(ActorContext $actor, Workspace $workspace): array {
    $ids = $this->database->query(
      'SELECT b.id FROM {ab_board} b INNER JOIN {ab_agent_board_grant} g ON g.board_id = b.id INNER JOIN {ab_agent} a ON a.id = g.agent_id WHERE b.workspace = :workspace AND a.uuid = :agent ORDER BY b.name, b.id',
      [
        ':workspace' => (int) $workspace->id(),
        ':agent' => $actor->actorId,
      ],
    )->fetchCol();
    $boards = [];
    foreach ($ids as $id) {
      $board = $this->uuids->load('ab_board', $this->boardUuid((int) $id));
      if ($board instanceof Board && $this->grantHasRead($actor, $board)) {
        $boards[] = $board;
      }
    }
    return $boards;
  }

  /**
   * Selects task ids inside one board.
   *
   * @param int $boardId
   *   Internal board id.
   * @param array<string, mixed> $filters
   *   Normalized filters.
   * @param array{position: int, id: int}|null $cursor
   *   The last item already returned.
   * @param int $range
   *   Maximum rows, including the extra cursor row.
   *
   * @return int[]
   *   Task ids in list order.
   */
  private function taskIds(int $boardId, array $filters, ?array $cursor, int $range): array {
    $query = $this->database->select('ab_task', 't');
    $query->addField('t', 'id');
    $query->condition('board', $boardId);
    if (isset($filters['status'])) {
      $query->condition('status', $filters['status']);
    }
    if (isset($filters['priority'])) {
      $query->condition('priority', $filters['priority']);
    }
    if (isset($filters['agent'])) {
      $query->condition('assigned_agent', $filters['agent']);
    }
    if (isset($filters['due'])) {
      $query->condition('due_at', $filters['due'], '<');
      $query->isNotNull('due_at');
    }
    if ($cursor !== NULL) {
      $query->where('(t.position > :pos OR (t.position = :pos AND t.id > :id))', [
        ':pos' => $cursor['position'],
        ':id' => $cursor['id'],
      ]);
    }
    $query->orderBy('position');
    $query->orderBy('id');
    $query->range(0, $range);
    $ids = [];
    foreach ($query->execute() as $row) {
      if (is_object($row)) {
        $ids[] = (int) $row->id;
      }
    }
    return $ids;
  }

  /**
   * Reads the execution columns the contract allows.
   *
   * @return array<string, mixed>|null
   *   The row, or NULL when it is outside the workspace.
   */
  private function executionRow(int $workspaceId, string $uuid): ?array {
    if (preg_match('/^[0-9a-f-]{36}$/', $uuid) !== 1) {
      return NULL;
    }
    $row = $this->database->query(
      'SELECT e.uuid AS id, t.uuid AS task_id, a.uuid AS agent_id, b.uuid AS board_uuid, e.status, e.attempt, e.version, e.started_at, e.lease_expires_at, e.heartbeat_at, e.ended_at, e.result_summary, e.failure_code FROM {ab_execution} e INNER JOIN {ab_task} t ON t.id = e.task INNER JOIN {ab_agent} a ON a.id = e.agent INNER JOIN {ab_board} b ON b.id = t.board WHERE e.uuid = :uuid AND e.workspace = :workspace',
      [
        ':uuid' => $uuid,
        ':workspace' => $workspaceId,
      ],
    )->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * Normalizes the task filters.
   *
   * @return array<string, mixed>
   *   Filter values.
   */
  private function filters(Request $request): array {
    $filters = [];
    $status = $request->query->get('status');
    if (is_string($status) && $status !== '') {
      if (!in_array($status, self::STATUSES, TRUE)) {
        throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
      }
      $filters['status'] = $status;
    }
    $priority = $request->query->get('priority');
    if (is_string($priority) && $priority !== '') {
      if (!in_array($priority, self::PRIORITIES, TRUE)) {
        throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
      }
      $filters['priority'] = $priority;
    }
    $agent = $request->query->get('assigned_agent');
    if (is_string($agent) && $agent !== '') {
      $filters['agent'] = $this->agentId($agent);
    }
    $due = $request->query->get('due_before');
    if (is_string($due) && $due !== '') {
      $filters['due'] = $this->due($due);
    }
    return $filters;
  }

  /**
   * Resolves an agent UUID to an internal id, or zero when it is unknown.
   */
  private function agentId(string $uuid): int {
    if (preg_match('/^[0-9a-f-]{36}$/', $uuid) !== 1) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $id = $this->database->query('SELECT id FROM {ab_agent} WHERE uuid = :uuid', [
      ':uuid' => $uuid,
    ])->fetchField();
    return $id === FALSE ? 0 : (int) $id;
  }

  /**
   * Parses an ISO timestamp.
   */
  private function due(string $value): int {
    try {
      $parsed = new \DateTimeImmutable($value);
    }
    catch (\Exception) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return $parsed->getTimestamp();
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
   * Decodes a numeric cursor.
   */
  private function idCursor(mixed $cursor): int {
    if ($cursor === NULL || $cursor === '') {
      return 0;
    }
    if (!is_string($cursor) || preg_match('/^[1-9][0-9]*$/', $cursor) !== 1) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return (int) $cursor;
  }

  /**
   * Decodes a board cursor.
   *
   * @return array{name: string, id: int}|null
   *   The last board already returned.
   */
  private function nameCursor(mixed $cursor): ?array {
    if ($cursor === NULL || $cursor === '') {
      return NULL;
    }
    if (!is_string($cursor)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $json = base64_decode($cursor, TRUE);
    $data = is_string($json) ? json_decode($json, TRUE) : NULL;
    if (!is_array($data) || !is_string($data['n'] ?? NULL) || !is_int($data['i'] ?? NULL)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return ['name' => $data['n'], 'id' => $data['i']];
  }

  /**
   * Decodes a task cursor.
   *
   * @return array{position: int, id: int}|null
   *   The last task already returned.
   */
  private function taskCursor(mixed $cursor): ?array {
    if ($cursor === NULL || $cursor === '') {
      return NULL;
    }
    if (!is_string($cursor)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $raw = base64_decode($cursor, TRUE);
    if (!is_string($raw) || preg_match('/^(\d+):(\d+)$/', $raw, $matches) !== 1) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return ['position' => (int) $matches[1], 'id' => (int) $matches[2]];
  }

  /**
   * Reports whether a board sorts after the cursor.
   *
   * @param \Drupal\ab_board\Entity\Board $board
   *   Board being compared.
   * @param array{name: string, id: int} $cursor
   *   The last board already returned.
   */
  private function afterName(Board $board, array $cursor): bool {
    $name = (string) $board->label();
    if ($name > $cursor['name']) {
      return TRUE;
    }
    return $name === $cursor['name'] && (int) $board->id() > $cursor['id'];
  }

  /**
   * Encodes a board cursor.
   */
  private function nameToken(Board $board): string {
    $json = json_encode([
      'n' => (string) $board->label(),
      'i' => (int) $board->id(),
    ]);
    return base64_encode(is_string($json) ? $json : '');
  }

  /**
   * Encodes a task cursor.
   */
  private function taskToken(Task $task): string {
    $token = (int) $task->get('position')->getString() . ':' . (int) $task->id();
    return base64_encode($token);
  }

  /**
   * Keeps one page and builds the next cursor from the extra row.
   *
   * @param mixed[] $rows
   *   Rows in order, possibly one past the limit.
   * @param int $limit
   *   Page size.
   * @param callable(mixed): string $cursorOf
   *   Encodes the last returned row.
   * @param callable(mixed): array<string, mixed> $document
   *   Builds the public document.
   *
   * @return array<string, mixed>
   *   The page.
   */
  private function slice(array $rows, int $limit, callable $cursorOf, callable $document): array {
    $more = count($rows) > $limit;
    if ($more) {
      $rows = array_slice($rows, 0, $limit);
    }
    $data = [];
    foreach ($rows as $row) {
      $data[] = $document($row);
    }
    $next = NULL;
    if ($more && $rows !== []) {
      $next = $cursorOf($rows[count($rows) - 1]);
    }
    return [
      'data' => $data,
      'page' => [
        'limit' => $limit,
        'next_cursor' => $next,
      ],
    ];
  }

  /**
   * Loads a board or hides it.
   */
  private function loadBoard(string $uuid): Board {
    try {
      $board = $this->uuids->load('ab_board', $uuid);
    }
    catch (\InvalidArgumentException) {
      throw new ScopedResourceNotFoundException('Board was not found.');
    }
    if (!$board instanceof Board) {
      throw new ScopedResourceNotFoundException('Board was not found.');
    }
    return $board;
  }

  /**
   * Loads a task or hides it.
   */
  private function loadTask(string $uuid): Task {
    try {
      $task = $this->uuids->load('ab_task', $uuid);
    }
    catch (\InvalidArgumentException) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
    if (!$task instanceof Task) {
      throw new ScopedResourceNotFoundException('Task was not found.');
    }
    return $task;
  }

  /**
   * Returns the UUID stored for a task id.
   */
  private function taskUuid(int $id): string {
    $uuid = $this->database->query('SELECT uuid FROM {ab_task} WHERE id = :id', [
      ':id' => $id,
    ])->fetchField();
    return is_string($uuid) ? $uuid : '';
  }

  /**
   * Returns the UUID stored for a board id.
   */
  private function boardUuid(int $id): string {
    $uuid = $this->database->query('SELECT uuid FROM {ab_board} WHERE id = :id', [
      ':id' => $id,
    ])->fetchField();
    return is_string($uuid) ? $uuid : '';
  }

}
