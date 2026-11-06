<?php

declare(strict_types=1);

namespace Drupal\ab_memory;

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

/**
 * Creates and revises shared memory with immutable revisions.
 *
 * Authorship comes from ActorContext. Events are recorded last inside the
 * caller transaction. Memory never claims a task or touches a lease.
 */
class MemoryService {

  public const TITLE_MAX = 255;

  public const CONTENT_MAX = 65536;

  public const TAGS_MAX = 20;

  public const TAG_MAX = 48;

  public const CHANGE_REASON_MAX = 500;

  public const KINDS = ['decision', 'fact', 'procedure', 'lesson', 'summary'];

  public const SOURCE_TYPES = ['manual', 'task', 'execution', 'message'];

  public const RELATIONS = ['related', 'supersedes', 'contradicts', 'supports'];

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventStoreInterface $events,
    private readonly MemoryAccessPolicy $access,
    private readonly MemoryRepository $repository,
    private readonly MemoryContentGuard $guard,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Creates a draft memory entry and its first revision.
   *
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   Authenticated actor.
   * @param array<string, mixed> $input
   *   Whitelisted fields. author_id and agent_id are refused.
   */
  public function createDraft(ActorContext $actor, array $input): MemoryRecord {
    $this->refuseForgedIdentity($input);
    $title = $this->title($input['title'] ?? NULL);
    $markdown = $this->markdown($input['content_markdown'] ?? NULL);
    $kind = $this->kind($input['kind'] ?? NULL);
    $tags = $this->tags($input['tags'] ?? []);
    $sourceType = $this->sourceType($input['source_type'] ?? 'manual');
    $changeReason = $this->changeReason($input['change_reason'] ?? NULL);
    $this->guard->assertSafe($title, $markdown, $tags, $changeReason);

    $workspace = $this->workspaceOfActor($actor);
    $board = $this->optionalBoard($workspace, $input['board_id'] ?? NULL);
    $task = $this->optionalTask($workspace, $board, $input['task_id'] ?? NULL);
    $sourceId = $this->validatedSourceId($workspace, $sourceType, $input['source_id'] ?? NULL);
    $this->access->assertCanCreate($actor, $board);

    $transaction = $this->database->startTransaction();
    try {
      if ($board instanceof Board) {
        $this->lockBoard((int) $board->id());
      }
      $now = $this->time->getRequestTime();
      $entryUuid = $this->uuid->generate();
      $entryId = (int) $this->database->insert('ab_memory_entry')
        ->fields([
          'uuid' => $entryUuid,
          'workspace' => (int) $workspace->id(),
          'board_id' => $board instanceof Board ? (int) $board->id() : NULL,
          'task_id' => $task instanceof Task ? (int) $task->id() : NULL,
          'title' => $title,
          'kind' => $kind,
          'status' => 'draft',
          'current_revision_id' => NULL,
          'tags' => $this->encodeTags($tags),
          'version' => 1,
          'created' => $now,
          'changed' => $now,
        ])
        ->execute();
      $revisionId = $this->insertRevision(
        $entryId,
        1,
        $markdown,
        $actor,
        $sourceType,
        $sourceId,
        $changeReason,
        $now,
      );
      $this->database->update('ab_memory_entry')
        ->fields(['current_revision_id' => $revisionId])
        ->condition('id', $entryId)
        ->execute();
      $this->storeSource($entryId, $revisionId, $sourceType, $sourceId, $now);
      $createdPayload = [
        'revision' => 1,
        'status' => 'draft',
        'kind' => $kind,
        'source_type' => $sourceType,
        'source_id' => $sourceId,
      ];
      if ($board instanceof Board && $task instanceof Task) {
        $createdPayload['task_id'] = (string) $task->uuid();
      }
      $this->recordEvent($actor, $workspace, $entryUuid, 'memory.created', $createdPayload, $board);
      $this->afterRecord();
      $this->invalidate($workspace);
      $entry = $this->repository->entryByUuid($actor->workspaceId, $entryUuid);
      $revision = $this->repository->currentRevision($entry);
      return $this->present(
        $entry,
        $revision,
        $workspace,
        $board,
        $task instanceof Task ? (string) $task->uuid() : NULL,
      );
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Appends an immutable revision while the entry stays draft.
   *
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   Authenticated actor.
   * @param string $memoryUuid
   *   Entry UUID.
   * @param array<string, mixed> $input
   *   Whitelisted fields.
   * @param int $expectedVersion
   *   Optimistic version the caller last read.
   */
  public function revise(ActorContext $actor, string $memoryUuid, array $input, int $expectedVersion): MemoryRecord {
    $this->refuseForgedIdentity($input);
    if ($expectedVersion < 1) {
      throw new \InvalidArgumentException('Expected version must be positive.');
    }
    $transaction = $this->database->startTransaction();
    try {
      $entry = $this->repository->lockEntry($actor->workspaceId, $memoryUuid);
      $board = $this->access->boardById(isset($entry['board_id']) ? (int) $entry['board_id'] : NULL);
      $this->access->assertCanRevise($actor, $entry, $board);
      if ((int) $entry['version'] !== $expectedVersion) {
        throw new MemoryVersionConflictException('The memory version is stale.');
      }
      if ($board instanceof Board) {
        $this->lockBoard((int) $board->id());
      }
      $current = $this->repository->currentRevision($entry);
      $title = array_key_exists('title', $input)
        ? $this->title($input['title'])
        : (string) $entry['title'];
      $markdown = array_key_exists('content_markdown', $input)
        ? $this->markdown($input['content_markdown'])
        : (string) $current['content_markdown'];
      $tags = array_key_exists('tags', $input)
        ? $this->tags($input['tags'])
        : $this->decodeTags((string) $entry['tags']);
      $sourceType = array_key_exists('source_type', $input)
        ? $this->sourceType($input['source_type'])
        : (string) $current['source_type'];
      $workspace = $this->workspaceOfActor($actor);
      $sourceId = array_key_exists('source_id', $input)
        ? $this->validatedSourceId($workspace, $sourceType, $input['source_id'])
        : ($current['source_id'] !== NULL ? (string) $current['source_id'] : NULL);
      $changeReason = $this->changeReason($input['change_reason'] ?? NULL);
      $this->guard->assertSafe($title, $markdown, $tags, $changeReason);

      $now = $this->time->getRequestTime();
      $revisionVersion = (int) $current['version'] + 1;
      $revisionId = $this->insertRevision(
        (int) $entry['id'],
        $revisionVersion,
        $markdown,
        $actor,
        $sourceType,
        $sourceId,
        $changeReason,
        $now,
      );
      $this->database->update('ab_memory_entry')
        ->fields([
          'title' => $title,
          'tags' => $this->encodeTags($tags),
          'current_revision_id' => $revisionId,
          'version' => $expectedVersion + 1,
          'changed' => $now,
        ])
        ->condition('id', (int) $entry['id'])
        ->condition('version', $expectedVersion)
        ->execute();
      $this->storeSource((int) $entry['id'], $revisionId, $sourceType, $sourceId, $now);
      $this->recordEvent($actor, $workspace, $memoryUuid, 'memory.revised', [
        'revision' => $revisionVersion,
        'status' => 'draft',
        'kind' => (string) $entry['kind'],
        'source_type' => $sourceType,
        'source_id' => $sourceId,
      ], $board);
      $this->afterRecord();
      $this->invalidate($workspace);
      $fresh = $this->repository->entryByUuid($actor->workspaceId, $memoryUuid);
      $revision = $this->repository->currentRevision($fresh);
      $taskUuid = $this->taskUuidOf($fresh);
      return $this->present($fresh, $revision, $workspace, $board, $taskUuid);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Publishes a draft. Human review only.
   */
  public function publish(ActorContext $actor, string $memoryUuid, int $expectedVersion): MemoryRecord {
    return $this->changeStatus($actor, $memoryUuid, $expectedVersion, 'published', 'memory.published');
  }

  /**
   * Archives a draft or published entry. Human review only.
   */
  public function archive(ActorContext $actor, string $memoryUuid, int $expectedVersion): MemoryRecord {
    return $this->changeStatus($actor, $memoryUuid, $expectedVersion, 'archived', 'memory.archived');
  }

  /**
   * Creates a typed link between two entries in the same workspace.
   */
  public function link(ActorContext $actor, string $fromUuid, string $toUuid, string $relation): void {
    $this->access->assertCanLink($actor);
    if (!in_array($relation, self::RELATIONS, TRUE)) {
      throw new \InvalidArgumentException('Memory relation is invalid.');
    }
    if ($fromUuid === $toUuid) {
      throw new \InvalidArgumentException('A memory cannot link to itself.');
    }
    $transaction = $this->database->startTransaction();
    try {
      $fromProbe = $this->repository->entryByUuid($actor->workspaceId, $fromUuid);
      $toProbe = $this->repository->entryByUuid($actor->workspaceId, $toUuid);
      if ((int) $fromProbe['workspace'] !== (int) $toProbe['workspace']) {
        throw new ScopedResourceNotFoundException();
      }
      $firstUuid = (int) $fromProbe['id'] <= (int) $toProbe['id'] ? $fromUuid : $toUuid;
      $secondUuid = $firstUuid === $fromUuid ? $toUuid : $fromUuid;
      $first = $this->repository->lockEntry($actor->workspaceId, $firstUuid);
      $second = $this->repository->lockEntry($actor->workspaceId, $secondUuid);
      $from = $fromUuid === $firstUuid ? $first : $second;
      $to = $toUuid === $secondUuid ? $second : $first;
      $now = $this->time->getRequestTime();
      try {
        $this->database->insert('ab_memory_link')
          ->fields([
            'workspace' => (int) $from['workspace'],
            'from_entry_id' => (int) $from['id'],
            'to_entry_id' => (int) $to['id'],
            'relation' => $relation,
            'created' => $now,
          ])
          ->execute();
      }
      catch (IntegrityConstraintViolationException $exception) {
        throw new MemoryRejectedException('Memory link already exists.', 0, $exception);
      }
      $workspace = $this->workspaceOfActor($actor);
      $board = $this->access->boardById(isset($from['board_id']) ? (int) $from['board_id'] : NULL);
      $this->recordEvent($actor, $workspace, $fromUuid, 'memory.linked', [
        'to_memory_id' => $toUuid,
        'relation' => $relation,
        'status' => (string) $from['status'],
        'kind' => (string) $from['kind'],
      ], $board);
      $this->afterRecord();
      $this->invalidate($workspace);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Loads one entry the actor may read.
   */
  public function get(ActorContext $actor, string $memoryUuid): MemoryRecord {
    return $this->loadRecord($actor, $memoryUuid);
  }

  /**
   * Lists revisions after the entry passes the access policy.
   *
   * @return list<array<string, mixed>>
   *   Revision rows newest first.
   */
  public function listRevisions(ActorContext $actor, string $memoryUuid, int $limit = 50): array {
    $entry = $this->repository->entryByUuid($actor->workspaceId, $memoryUuid);
    $board = $this->access->boardById(isset($entry['board_id']) ? (int) $entry['board_id'] : NULL);
    $this->assertReadable($actor, $entry, $board);
    return $this->repository->revisions((int) $entry['id'], $limit);
  }

  /**
   * Counts entries the actor may discover without widening grants.
   */
  public function countVisible(ActorContext $actor): int {
    $workspace = $this->workspaceOfActor($actor);
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      $boards = $this->access->readableBoardIds($actor, (int) $workspace->id());
      return $this->repository->countForBoards((int) $workspace->id(), $boards, [
        'published',
        'archived',
      ]);
    }
    if (!$actor->allows(WorkspacePermission::READ)) {
      throw new ScopedOperationDeniedException();
    }
    $statuses = ['published', 'archived'];
    if ($actor->allows(WorkspacePermission::REVIEW) || $actor->allows(WorkspacePermission::WRITE)) {
      $statuses[] = 'draft';
    }
    return (int) $this->database->select('ab_memory_entry', 'e')
      ->condition('e.workspace', (int) $workspace->id())
      ->condition('e.status', $statuses, 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Hook for kernel tests that force a post-event failure.
   */
  protected function afterRecord(): void {}

  /**
   * Publishes or archives under optimistic locking.
   */
  private function changeStatus(
    ActorContext $actor,
    string $memoryUuid,
    int $expectedVersion,
    string $status,
    string $eventType,
  ): MemoryRecord {
    if ($expectedVersion < 1) {
      throw new \InvalidArgumentException('Expected version must be positive.');
    }
    $transaction = $this->database->startTransaction();
    try {
      $entry = $this->repository->lockEntry($actor->workspaceId, $memoryUuid);
      $board = $this->access->boardById(isset($entry['board_id']) ? (int) $entry['board_id'] : NULL);
      if ($status === 'published') {
        $this->access->assertCanPublish($actor, $entry, $board);
      }
      else {
        $this->access->assertCanArchive($actor, $entry, $board);
      }
      if ((int) $entry['version'] !== $expectedVersion) {
        throw new MemoryVersionConflictException('The memory version is stale.');
      }
      if ($board instanceof Board) {
        $this->lockBoard((int) $board->id());
      }
      $revision = $this->repository->currentRevision($entry);
      $now = $this->time->getRequestTime();
      $updated = $this->database->update('ab_memory_entry')
        ->fields([
          'status' => $status,
          'version' => $expectedVersion + 1,
          'changed' => $now,
        ])
        ->condition('id', (int) $entry['id'])
        ->condition('version', $expectedVersion)
        ->execute();
      if ((int) $updated !== 1) {
        throw new MemoryVersionConflictException('The memory version is stale.');
      }
      $workspace = $this->workspaceOfActor($actor);
      $this->recordEvent($actor, $workspace, $memoryUuid, $eventType, [
        'revision' => (int) $revision['version'],
        'status' => $status,
        'kind' => (string) $entry['kind'],
        'source_type' => (string) $revision['source_type'],
        'source_id' => $revision['source_id'] !== NULL ? (string) $revision['source_id'] : NULL,
      ], $board);
      $this->afterRecord();
      $this->invalidate($workspace);
      return $this->loadRecord($actor, $memoryUuid);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Builds the public record after access checks.
   */
  private function loadRecord(ActorContext $actor, string $memoryUuid): MemoryRecord {
    $entry = $this->repository->entryByUuid($actor->workspaceId, $memoryUuid);
    $board = $this->access->boardById(isset($entry['board_id']) ? (int) $entry['board_id'] : NULL);
    try {
      $this->access->assertCanRead($actor, $entry, $board);
    }
    catch (ScopedOperationDeniedException $exception) {
      if ((string) $entry['status'] === 'draft'
        && $actor->actorType === ActorContext::TYPE_HUMAN
        && $actor->allows(WorkspacePermission::WRITE)) {
        $revision = $this->repository->currentRevision($entry);
        if ($revision['author_type'] !== $actor->actorType || $revision['author_id'] !== $actor->actorId) {
          throw $exception;
        }
      }
      else {
        throw $exception;
      }
    }
    $revision = $this->repository->currentRevision($entry);
    return $this->present(
      $entry,
      $revision,
      $this->workspaceOfActor($actor),
      $board,
      $this->taskUuidOf($entry),
    );
  }

  /**
   * Maps stored rows to the public record.
   *
   * @param array<string, mixed> $entry
   *   Entry row.
   * @param array<string, mixed> $revision
   *   Current revision row.
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace of the entry.
   * @param \Drupal\ab_board\Entity\Board|null $board
   *   Board when the entry is board-scoped.
   * @param string|null $taskUuid
   *   Optional task UUID.
   */
  private function present(
    array $entry,
    array $revision,
    Workspace $workspace,
    ?Board $board,
    ?string $taskUuid,
  ): MemoryRecord {
    return new MemoryRecord(
      (string) $entry['uuid'],
      (string) $workspace->uuid(),
      $board instanceof Board ? (string) $board->uuid() : NULL,
      $taskUuid,
      (string) $entry['title'],
      (string) $entry['kind'],
      (string) $entry['status'],
      (int) $entry['version'],
      $this->decodeTags((string) $entry['tags']),
      (int) $revision['version'],
      (string) $revision['content_markdown'],
      (string) $revision['content_hash'],
      (string) $revision['author_type'],
      (string) $revision['author_id'],
      (string) $revision['source_type'],
      $revision['source_id'] !== NULL ? (string) $revision['source_id'] : NULL,
      $revision['change_reason'] !== NULL ? (string) $revision['change_reason'] : NULL,
      (int) $entry['created'],
      (int) $entry['changed'],
    );
  }

  /**
   * Resolves the public task UUID for an entry row.
   *
   * @param array<string, mixed> $entry
   *   Entry row.
   */
  private function taskUuidOf(array $entry): ?string {
    if (empty($entry['task_id'])) {
      return NULL;
    }
    $task = $this->entityTypeManager->getStorage('ab_task')->load((int) $entry['task_id']);
    return $task instanceof Task ? (string) $task->uuid() : NULL;
  }

  /**
   * Inserts one immutable revision row.
   *
   * @param int $entryId
   *   Internal entry id.
   * @param int $version
   *   Revision number.
   * @param string $markdown
   *   Normalized markdown.
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   Author.
   * @param string $sourceType
   *   Source type.
   * @param string|null $sourceId
   *   Opaque source id.
   * @param string|null $changeReason
   *   Optional reason.
   * @param int $now
   *   UTC timestamp.
   */
  private function insertRevision(
    int $entryId,
    int $version,
    string $markdown,
    ActorContext $actor,
    string $sourceType,
    ?string $sourceId,
    ?string $changeReason,
    int $now,
  ): int {
    return (int) $this->database->insert('ab_memory_revision')
      ->fields([
        'entry_id' => $entryId,
        'version' => $version,
        'content_markdown' => $markdown,
        'content_hash' => hash('sha256', $markdown),
        'author_type' => $actor->actorType,
        'author_id' => $actor->actorId,
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'change_reason' => $changeReason,
        'created' => $now,
      ])
      ->execute();
  }

  /**
   * Persists the typed source snapshot for the revision.
   */
  private function storeSource(
    int $entryId,
    int $revisionId,
    string $sourceType,
    ?string $sourceId,
    int $now,
  ): void {
    $snapshot = json_encode([
      'source_type' => $sourceType,
      'source_id' => $sourceId,
    ], JSON_THROW_ON_ERROR);
    $this->database->insert('ab_memory_source')
      ->fields([
        'entry_id' => $entryId,
        'revision_id' => $revisionId,
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'snapshot' => $snapshot,
        'available' => 1,
        'created' => $now,
      ])
      ->execute();
  }

  /**
   * Records a memory event without Markdown or secrets.
   *
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   Actor stored on the event.
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace of the event.
   * @param string $resourceUuid
   *   Memory entry UUID.
   * @param string $type
   *   Event type.
   * @param array<string, mixed> $payload
   *   Minimal metadata. Null board_id is omitted for workspace visibility.
   * @param \Drupal\ab_board\Entity\Board|null $board
   *   Board for sync filtering.
   */
  private function recordEvent(
    ActorContext $actor,
    Workspace $workspace,
    string $resourceUuid,
    string $type,
    array $payload,
    ?Board $board,
  ): void {
    if ($board instanceof Board) {
      $payload['board_id'] = (string) $board->uuid();
    }
    else {
      unset($payload['board_id']);
    }
    foreach (['task_id', 'source_id', 'to_memory_id'] as $optional) {
      if (($payload[$optional] ?? NULL) === NULL) {
        unset($payload[$optional]);
      }
    }
    $this->events->record(new EventDraft(
      (string) $workspace->uuid(),
      $type,
      $resourceUuid,
      $actor->actorType,
      $actor->actorId,
      $payload,
    ));
  }

  /**
   * Rejects forged identity fields from the payload.
   *
   * @param array<string, mixed> $input
   *   Caller input.
   */
  private function refuseForgedIdentity(array $input): void {
    foreach (['author_id', 'agent_id', 'workspace_id', 'status'] as $forbidden) {
      if (array_key_exists($forbidden, $input)) {
        throw new \InvalidArgumentException(sprintf('%s cannot be set on memory input.', $forbidden));
      }
    }
  }

  /**
   * Validates the title.
   */
  private function title(mixed $value): string {
    if (!is_string($value)) {
      throw new \InvalidArgumentException('Memory title is required.');
    }
    $title = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if ($title === '' || strlen($title) > self::TITLE_MAX) {
      throw new \InvalidArgumentException('Memory title is invalid.');
    }
    return $title;
  }

  /**
   * Validates the markdown body.
   */
  private function markdown(mixed $value): string {
    if (!is_string($value)) {
      throw new \InvalidArgumentException('Memory content is required.');
    }
    $markdown = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if ($markdown === '' || strlen($markdown) > self::CONTENT_MAX) {
      throw new \InvalidArgumentException('Memory content is invalid.');
    }
    return $markdown;
  }

  /**
   * Validates the memory kind.
   */
  private function kind(mixed $value): string {
    if (!is_string($value) || !in_array($value, self::KINDS, TRUE)) {
      throw new \InvalidArgumentException('Memory kind is invalid.');
    }
    return $value;
  }

  /**
   * Validates and normalizes tags.
   *
   * @param mixed $value
   *   Raw tags.
   *
   * @return string[]
   *   Normalized tags.
   */
  private function tags(mixed $value): array {
    if ($value === NULL) {
      return [];
    }
    if (!is_array($value)) {
      throw new \InvalidArgumentException('Memory tags must be a list.');
    }
    if (count($value) > self::TAGS_MAX) {
      throw new \InvalidArgumentException('Memory tags exceed the limit.');
    }
    $tags = [];
    foreach ($value as $tag) {
      if (!is_string($tag)) {
        throw new \InvalidArgumentException('Memory tags must be strings.');
      }
      $normalized = trim($tag);
      if ($normalized === '' || strlen($normalized) > self::TAG_MAX) {
        throw new \InvalidArgumentException('Memory tag is invalid.');
      }
      $tags[] = $normalized;
    }
    return array_values(array_unique($tags));
  }

  /**
   * Validates the source type.
   */
  private function sourceType(mixed $value): string {
    if (!is_string($value) || !in_array($value, self::SOURCE_TYPES, TRUE)) {
      throw new \InvalidArgumentException('Memory source type is invalid.');
    }
    return $value;
  }

  /**
   * Validates the optional source id.
   */
  private function sourceId(mixed $value): ?string {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (!is_string($value) || strlen($value) > 64) {
      throw new \InvalidArgumentException('Memory source id is invalid.');
    }
    return $value;
  }

  /**
   * Validates source_id against the workspace when the type requires it.
   *
   * Task, execution and message ids must exist in the same workspace. Manual
   * sources stay opaque. Message checks the schema table when present so
   * ab_memory does not depend on ab_communication.
   */
  private function validatedSourceId(Workspace $workspace, string $sourceType, mixed $value): ?string {
    $sourceId = $this->sourceId($value);
    if ($sourceId === NULL) {
      return NULL;
    }
    if ($sourceType === 'manual') {
      return $sourceId;
    }
    if ($sourceType === 'task') {
      $this->assertSourceInWorkspace('ab_task', $workspace, $sourceId);
      return $sourceId;
    }
    if ($sourceType === 'execution') {
      $this->assertSourceInWorkspace('ab_execution', $workspace, $sourceId);
      return $sourceId;
    }
    if ($sourceType === 'message') {
      if (!$this->database->schema()->tableExists('ab_message')) {
        throw new ScopedResourceNotFoundException();
      }
      $found = $this->database->select('ab_message', 'm')
        ->fields('m', ['id'])
        ->condition('m.uuid', $sourceId)
        ->condition('m.workspace', (int) $workspace->id())
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if ($found === FALSE) {
        throw new ScopedResourceNotFoundException();
      }
      return $sourceId;
    }
    throw new \InvalidArgumentException('Memory source type is invalid.');
  }

  /**
   * Ensures a content entity UUID exists inside the workspace.
   */
  private function assertSourceInWorkspace(string $entityTypeId, Workspace $workspace, string $uuid): void {
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $uuid)
      ->condition('workspace', (int) $workspace->id())
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      throw new ScopedResourceNotFoundException();
    }
  }

  /**
   * Validates the optional change reason.
   */
  private function changeReason(mixed $value): ?string {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (!is_string($value) || strlen($value) > self::CHANGE_REASON_MAX) {
      throw new \InvalidArgumentException('Memory change reason is invalid.');
    }
    return $value;
  }

  /**
   * Applies the same read policy used by get(), including own-draft humans.
   *
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   Authenticated actor.
   * @param array<string, mixed> $entry
   *   Entry row.
   * @param \Drupal\ab_board\Entity\Board|null $board
   *   Board of the entry when present.
   */
  private function assertReadable(ActorContext $actor, array $entry, ?Board $board): void {
    try {
      $this->access->assertCanRead($actor, $entry, $board);
    }
    catch (ScopedOperationDeniedException $exception) {
      if ((string) $entry['status'] === 'draft'
        && $actor->actorType === ActorContext::TYPE_HUMAN
        && $actor->allows(WorkspacePermission::WRITE)) {
        $revision = $this->repository->currentRevision($entry);
        if ($revision['author_type'] !== $actor->actorType || $revision['author_id'] !== $actor->actorId) {
          throw $exception;
        }
        return;
      }
      throw $exception;
    }
  }

  /**
   * Busts workspace memory cache tags after a mutation.
   */
  private function invalidate(Workspace $workspace): void {
    $this->cacheTagsInvalidator->invalidateTags([
      'ab_workspace_memory:' . $workspace->id(),
    ]);
  }

  /**
   * Encodes tags as JSON.
   *
   * @param string[] $tags
   *   Tags.
   */
  private function encodeTags(array $tags): string {
    return json_encode(array_values($tags), JSON_THROW_ON_ERROR);
  }

  /**
   * Decodes the tags JSON column.
   *
   * @return string[]
   *   Tags.
   */
  private function decodeTags(string $json): array {
    $decoded = json_decode($json, TRUE);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
  }

  /**
   * Loads the workspace of the actor.
   */
  private function workspaceOfActor(ActorContext $actor): Workspace {
    return $this->repository->workspace($actor->workspaceId);
  }

  /**
   * Loads an optional board inside the workspace.
   */
  private function optionalBoard(Workspace $workspace, mixed $boardUuid): ?Board {
    if ($boardUuid === NULL || $boardUuid === '') {
      return NULL;
    }
    if (!is_string($boardUuid)) {
      throw new \InvalidArgumentException('board_id must be a UUID.');
    }
    $storage = $this->entityTypeManager->getStorage('ab_board');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $boardUuid)
      ->range(0, 1)
      ->execute();
    $board = $ids === [] ? NULL : $storage->load(reset($ids));
    if (!$board instanceof Board) {
      throw new ScopedResourceNotFoundException();
    }
    if ((string) $board->get('workspace')->getString() !== (string) $workspace->id()) {
      throw new ScopedResourceNotFoundException();
    }
    return $board;
  }

  /**
   * Loads an optional task inside the workspace.
   */
  private function optionalTask(Workspace $workspace, ?Board $board, mixed $taskUuid): ?Task {
    if ($taskUuid === NULL || $taskUuid === '') {
      return NULL;
    }
    if (!is_string($taskUuid)) {
      throw new \InvalidArgumentException('task_id must be a UUID.');
    }
    $storage = $this->entityTypeManager->getStorage('ab_task');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $taskUuid)
      ->range(0, 1)
      ->execute();
    $task = $ids === [] ? NULL : $storage->load(reset($ids));
    if (!$task instanceof Task) {
      throw new ScopedResourceNotFoundException();
    }
    if ((string) $task->get('workspace')->getString() !== (string) $workspace->id()) {
      throw new ScopedResourceNotFoundException();
    }
    if ($board instanceof Board && (string) $task->get('board')->getString() !== (string) $board->id()) {
      throw new ScopedResourceNotFoundException();
    }
    return $task;
  }

  /**
   * Locks a board row for update.
   */
  private function lockBoard(int $boardId): void {
    $this->database->query('SELECT id FROM {ab_board} WHERE id = :id FOR UPDATE', [
      ':id' => $boardId,
    ])->fetchField();
  }

}
