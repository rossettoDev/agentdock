<?php

declare(strict_types=1);

namespace Drupal\ab_api\Http;

use Drupal\ab_agent\AgentAccessDeniedException;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_api\ApiException;
use Drupal\ab_api\IdempotencyKey;
use Drupal\ab_api\IdempotencyService;
use Drupal\ab_api\JsonPayload;
use Drupal\ab_api\PageQuery;
use Drupal\ab_api\ProblemDetails;
use Drupal\ab_api\StoredResponse;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_memory\MemoryRecord;
use Drupal\ab_memory\MemoryRejectedException;
use Drupal\ab_memory\MemoryService;
use Drupal\ab_memory\MemoryVersionConflictException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves memory search, read and mutations through MemoryService.
 *
 * Authorization stays in the domain policy and AgentOperationGate. This
 * class only maps HTTP concerns: CSRF, scopes, If-Match, idempotency and
 * Problem Details. Secrets never leave fixedDetail messages.
 */
final class MemoryCommands {

  private const CREATE_FIELDS = [
    'title',
    'content_markdown',
    'kind',
    'board_id',
    'task_id',
    'tags',
    'source_type',
    'source_id',
    'change_reason',
  ];

  private const PATCH_FIELDS = [
    'title',
    'content_markdown',
    'tags',
    'change_reason',
    'version',
  ];

  public function __construct(
    private readonly ApiCaller $caller,
    private readonly IdempotencyService $idempotency,
    private readonly MemoryService $memory,
    private readonly MemoryDocument $documents,
  ) {}

  /**
   * Searches authorized memory. Empty list is still 200.
   *
   * @return array{data: list<array<string, mixed>>, page: array<string, mixed>}
   *   Search page.
   */
  public function search(Request $request, string $workspaceUuid): array {
    $actor = $this->caller->actor($request, $workspaceUuid);
    $this->caller->assertScope($actor, AgentTokenScope::MEMORY_READ);
    $limit = $this->limit($request);
    $filters = $this->searchFilters($request);
    $board = $filters['board'] ?? NULL;
    unset($filters['board']);
    $after = $this->uuidCursor($request->query->get('cursor'));
    // Over-fetch so board and cursor filters can still fill one page.
    $records = $this->memory->listVisible($actor, $filters, 100);
    $records = $this->applyBoardFilter($records, is_string($board) ? $board : NULL);
    if ($after !== NULL) {
      $records = $this->afterUuid($records, $after);
    }
    $more = count($records) > $limit;
    if ($more) {
      $records = array_slice($records, 0, $limit);
    }
    $data = [];
    foreach ($records as $record) {
      $data[] = $this->documents->hit($record);
    }
    $next = NULL;
    if ($more && $records !== []) {
      $next = $this->encodeCursor($records[count($records) - 1]->uuid);
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
   * Reads one memory entry.
   *
   * @return array{data: array<string, mixed>}
   *   Memory envelope body.
   */
  public function get(Request $request, string $workspaceUuid, string $memoryUuid): array {
    $actor = $this->caller->actor($request, $workspaceUuid);
    $this->caller->assertScope($actor, AgentTokenScope::MEMORY_READ);
    $record = $this->run(fn (): MemoryRecord => $this->memory->get($actor, $memoryUuid));
    return ['data' => $this->documents->memory($record)];
  }

  /**
   * Lists revisions of one entry the caller may read.
   *
   * @return array{data: list<array<string, mixed>>, page: array<string, mixed>}
   *   Revision page.
   */
  public function revisions(Request $request, string $workspaceUuid, string $memoryUuid): array {
    $actor = $this->caller->actor($request, $workspaceUuid);
    $this->caller->assertScope($actor, AgentTokenScope::MEMORY_READ);
    $limit = $this->limit($request);
    $after = $this->intCursor($request->query->get('cursor'));
    $rows = $this->run(fn (): array => $this->memory->listRevisions($actor, $memoryUuid, 100));
    if ($after > 0) {
      $rows = array_values(array_filter(
        $rows,
        static fn (array $row): bool => (int) $row['version'] < $after,
      ));
    }
    $more = count($rows) > $limit;
    if ($more) {
      $rows = array_slice($rows, 0, $limit);
    }
    $data = [];
    foreach ($rows as $row) {
      $data[] = $this->documents->revision($row);
    }
    $next = NULL;
    if ($more && $rows !== []) {
      $next = (string) (int) $rows[count($rows) - 1]['version'];
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
   * Creates a draft.
   */
  public function create(Request $request, string $workspaceUuid): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    $this->caller->assertScope($actor, AgentTokenScope::MEMORY_WRITE);
    $this->assertMayWrite($actor);
    $input = $this->createInput(JsonPayload::object($request->getContent()), $actor);
    return $this->idempotency->execute(
      $actor,
      'memory.create',
      $workspaceUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($actor, $input): StoredResponse {
        $created = $this->run(fn (): MemoryRecord => $this->memory->createDraft($actor, $input));
        return new StoredResponse(201, $this->encode($this->documents->memory($created)));
      },
    );
  }

  /**
   * Appends a draft revision.
   */
  public function revise(Request $request, string $workspaceUuid, string $memoryUuid): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    $this->caller->assertScope($actor, AgentTokenScope::MEMORY_WRITE);
    $this->assertMayWrite($actor);
    $expected = $this->version($request);
    $input = $this->patchInput(JsonPayload::object($request->getContent()));
    return $this->idempotency->execute(
      $actor,
      'memory.revise',
      $memoryUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($actor, $memoryUuid, $input, $expected): StoredResponse {
        $updated = $this->run(fn (): MemoryRecord => $this->memory->revise($actor, $memoryUuid, $input, $expected));
        return new StoredResponse(200, $this->encode($this->documents->memory($updated)));
      },
    );
  }

  /**
   * Publishes a draft. Humans with review only.
   */
  public function publish(Request $request, string $workspaceUuid, string $memoryUuid): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    $this->assertHumanReview($actor);
    $expected = $this->version($request);
    $this->assertStatusBody($request);
    return $this->idempotency->execute(
      $actor,
      'memory.publish',
      $memoryUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($actor, $memoryUuid, $expected): StoredResponse {
        $published = $this->run(fn (): MemoryRecord => $this->memory->publish($actor, $memoryUuid, $expected));
        return new StoredResponse(200, $this->encode($this->documents->memory($published)));
      },
    );
  }

  /**
   * Archives a draft or published entry. Humans with review only.
   */
  public function archive(Request $request, string $workspaceUuid, string $memoryUuid): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    $this->assertHumanReview($actor);
    $expected = $this->version($request);
    $this->assertStatusBody($request);
    return $this->idempotency->execute(
      $actor,
      'memory.archive',
      $memoryUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($actor, $memoryUuid, $expected): StoredResponse {
        $archived = $this->run(fn (): MemoryRecord => $this->memory->archive($actor, $memoryUuid, $expected));
        return new StoredResponse(200, $this->encode($this->documents->memory($archived)));
      },
    );
  }

  /**
   * Builds search filters from the query string.
   *
   * @return array{status?: string, kind?: string, q?: string, board?: string}
   *   Filters for listVisible plus optional board post-filter.
   */
  private function searchFilters(Request $request): array {
    $filters = [];
    $status = $request->query->get('status', 'published');
    if (!is_string($status) || !in_array($status, ['draft', 'published', 'archived'], TRUE)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $filters['status'] = $status;
    $kind = $request->query->get('kind', '');
    if ($kind !== NULL && $kind !== '') {
      if (!is_string($kind) || !in_array($kind, MemoryService::KINDS, TRUE)) {
        throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
      }
      $filters['kind'] = $kind;
    }
    $q = $request->query->get('q', '');
    if ($q !== NULL && $q !== '') {
      if (!is_string($q) || strlen($q) > 256) {
        throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
      }
      $filters['q'] = $q;
    }
    $board = $request->query->get('board', '');
    if ($board !== NULL && $board !== '') {
      if (!is_string($board) || preg_match('/^[0-9a-f-]{36}$/', $board) !== 1) {
        throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
      }
      $filters['board'] = $board;
    }
    return $filters;
  }

  /**
   * Applies the optional board filter after discovery.
   *
   * @param list<\Drupal\ab_memory\MemoryRecord> $records
   *   Visible records.
   * @param string|null $board
   *   Board UUID filter when set.
   *
   * @return list<\Drupal\ab_memory\MemoryRecord>
   *   Filtered records.
   */
  private function applyBoardFilter(array $records, ?string $board): array {
    if ($board === NULL || $board === '') {
      return $records;
    }
    return array_values(array_filter(
      $records,
      static fn (MemoryRecord $record): bool => $record->boardUuid === $board,
    ));
  }

  /**
   * Drops records until after the cursor UUID.
   *
   * @param list<\Drupal\ab_memory\MemoryRecord> $records
   *   Ordered records.
   * @param string $after
   *   Exclusive cursor UUID.
   *
   * @return list<\Drupal\ab_memory\MemoryRecord>
   *   Remaining records.
   */
  private function afterUuid(array $records, string $after): array {
    $seen = FALSE;
    $kept = [];
    foreach ($records as $record) {
      if ($seen) {
        $kept[] = $record;
        continue;
      }
      if ($record->uuid === $after) {
        $seen = TRUE;
      }
    }
    return $kept;
  }

  /**
   * Whitelists create body fields.
   *
   * @param array<string, mixed> $body
   *   JSON object.
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   Authenticated caller (agents must send board_id).
   *
   * @return array<string, mixed>
   *   MemoryService input.
   */
  private function createInput(array $body, ActorContext $actor): array {
    if (array_diff(array_keys($body), self::CREATE_FIELDS) !== []) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    foreach (['author_id', 'agent_id', 'workspace_id', 'status'] as $forbidden) {
      if (array_key_exists($forbidden, $body)) {
        throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
      }
    }
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      $board = $body['board_id'] ?? NULL;
      if (!is_string($board) || $board === '') {
        throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
      }
    }
    return $body;
  }

  /**
   * Whitelists patch body fields without version.
   *
   * @param array<string, mixed> $body
   *   JSON object.
   *
   * @return array<string, mixed>
   *   MemoryService input.
   */
  private function patchInput(array $body): array {
    if (array_diff(array_keys($body), self::PATCH_FIELDS) !== []) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    foreach (['author_id', 'agent_id', 'workspace_id', 'board_id', 'status'] as $forbidden) {
      if (array_key_exists($forbidden, $body)) {
        throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
      }
    }
    unset($body['version']);
    return $body;
  }

  /**
   * Requires If-Match and matching body version.
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
   * Requires a status-change body with only version.
   */
  private function assertStatusBody(Request $request): void {
    $body = JsonPayload::object($request->getContent());
    if (array_diff(array_keys($body), ['version']) !== []) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
  }

  /**
   * Humans need write; agents rely on MEMORY_WRITE + board gate in the service.
   */
  private function assertMayWrite(ActorContext $actor): void {
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      return;
    }
    if (!$actor->allows(WorkspacePermission::WRITE)) {
      throw new ScopedOperationDeniedException();
    }
  }

  /**
   * Publish and archive stay human review.
   */
  private function assertHumanReview(ActorContext $actor): void {
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      throw new ScopedOperationDeniedException();
    }
    if (!$actor->allows(WorkspacePermission::REVIEW)) {
      throw new ScopedOperationDeniedException();
    }
  }

  /**
   * Runs a domain call and maps failures without leaking secrets.
   */
  private function run(callable $call): mixed {
    try {
      return $call();
    }
    catch (MemoryVersionConflictException) {
      throw new ApiException(412, 'stale_version', ProblemDetails::fixedDetail(412));
    }
    catch (MemoryRejectedException) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    catch (\InvalidArgumentException) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    catch (AgentAccessDeniedException $exception) {
      throw $exception;
    }
    catch (ScopedResourceNotFoundException | ScopedOperationDeniedException $exception) {
      throw $exception;
    }
  }

  /**
   * Reads the shared page limit.
   */
  private function limit(Request $request): int {
    $raw = $request->query->get('limit');
    return PageQuery::limit(is_string($raw) ? $raw : NULL);
  }

  /**
   * Decodes an opaque UUID cursor.
   */
  private function uuidCursor(mixed $cursor): ?string {
    if ($cursor === NULL || $cursor === '') {
      return NULL;
    }
    if (!is_string($cursor)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $raw = base64_decode($cursor, TRUE);
    if (!is_string($raw) || preg_match('/^[0-9a-f-]{36}$/', $raw) !== 1) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return $raw;
  }

  /**
   * Encodes a UUID cursor.
   */
  private function encodeCursor(string $uuid): string {
    return base64_encode($uuid);
  }

  /**
   * Decodes a numeric revision cursor (exclusive upper bound).
   */
  private function intCursor(mixed $cursor): int {
    if ($cursor === NULL || $cursor === '') {
      return 0;
    }
    if (!is_string($cursor) || preg_match('/^[1-9][0-9]*$/', $cursor) !== 1) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return (int) $cursor;
  }

  /**
   * Encodes a data envelope.
   *
   * @param array<string, mixed> $data
   *   Public document.
   */
  private function encode(array $data): string {
    return json_encode(['data' => $data], JSON_THROW_ON_ERROR);
  }

}
