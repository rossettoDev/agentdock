<?php

declare(strict_types=1);

namespace Drupal\ab_memory;

use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Reads memory rows always filtered by workspace.
 */
final class MemoryRepository {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Loads an entry by public UUID inside one workspace.
   *
   * @return array<string, mixed>
   *   Entry row.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   */
  public function entryByUuid(string $workspaceUuid, string $memoryUuid): array {
    $workspace = $this->workspace($workspaceUuid);
    $row = $this->database->select('ab_memory_entry', 'e')
      ->fields('e')
      ->condition('e.workspace', (int) $workspace->id())
      ->condition('e.uuid', $memoryUuid)
      ->execute()
      ->fetchAssoc();
    if ($row === FALSE) {
      throw new ScopedResourceNotFoundException();
    }
    return $row;
  }

  /**
   * Locks one entry row for update inside the caller's transaction.
   *
   * @return array<string, mixed>
   *   Locked entry row.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   */
  public function lockEntry(string $workspaceUuid, string $memoryUuid): array {
    $workspace = $this->workspace($workspaceUuid);
    $row = $this->database->query(
      'SELECT * FROM {ab_memory_entry} WHERE workspace = :workspace AND uuid = :uuid FOR UPDATE',
      [
        ':workspace' => (int) $workspace->id(),
        ':uuid' => $memoryUuid,
      ],
    )->fetchAssoc();
    if ($row === FALSE) {
      throw new ScopedResourceNotFoundException();
    }
    return $row;
  }

  /**
   * Loads the current revision for an entry id.
   *
   * @return array<string, mixed>
   *   Revision row.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   */
  public function currentRevision(array $entry): array {
    $revisionId = (int) ($entry['current_revision_id'] ?? 0);
    if ($revisionId <= 0) {
      throw new ScopedResourceNotFoundException();
    }
    $row = $this->database->select('ab_memory_revision', 'r')
      ->fields('r')
      ->condition('r.id', $revisionId)
      ->condition('r.entry_id', (int) $entry['id'])
      ->execute()
      ->fetchAssoc();
    if ($row === FALSE) {
      throw new ScopedResourceNotFoundException();
    }
    return $row;
  }

  /**
   * Lists revisions for an entry the caller already authorized, newest first.
   *
   * Callers must run MemoryAccessPolicy before invoking this method.
   *
   * @return list<array<string, mixed>>
   *   Revision rows.
   */
  public function revisions(int $entryId, int $limit = 50): array {
    $limit = max(1, min(100, $limit));
    return $this->database->select('ab_memory_revision', 'r')
      ->fields('r')
      ->condition('r.entry_id', $entryId)
      ->orderBy('r.version', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Counts entries visible to an agent on granted boards.
   *
   * @param int $workspaceId
   *   Internal workspace id.
   * @param int[] $boardIds
   *   Internal board ids with read grant.
   * @param string[] $statuses
   *   Allowed statuses.
   */
  public function countForBoards(int $workspaceId, array $boardIds, array $statuses): int {
    if ($boardIds === [] || $statuses === []) {
      return 0;
    }
    $query = $this->database->select('ab_memory_entry', 'e');
    $query->condition('e.workspace', $workspaceId);
    $query->condition('e.board_id', $boardIds, 'IN');
    $query->condition('e.status', $statuses, 'IN');
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Lists entry UUIDs visible to an agent on granted boards.
   *
   * @param int $workspaceId
   *   Internal workspace id.
   * @param int[] $boardIds
   *   Internal board ids with read grant.
   * @param string[] $statuses
   *   Allowed statuses.
   * @param int $limit
   *   Maximum UUIDs to return.
   * @param string|null $kind
   *   Optional kind filter.
   * @param string|null $query
   *   Optional title substring (case-insensitive).
   *
   * @return string[]
   *   Entry UUIDs.
   */
  public function uuidsForBoards(
    int $workspaceId,
    array $boardIds,
    array $statuses,
    int $limit = 50,
    ?string $kind = NULL,
    ?string $query = NULL,
  ): array {
    if ($boardIds === [] || $statuses === []) {
      return [];
    }
    $limit = max(1, min(100, $limit));
    $select = $this->database->select('ab_memory_entry', 'e')
      ->fields('e', ['uuid'])
      ->condition('e.workspace', $workspaceId)
      ->condition('e.board_id', $boardIds, 'IN')
      ->condition('e.status', $statuses, 'IN')
      ->orderBy('e.changed', 'DESC')
      ->range(0, $limit);
    if ($kind !== NULL && $kind !== '') {
      $select->condition('e.kind', $kind);
    }
    if ($query !== NULL && $query !== '') {
      $select->condition('e.title', '%' . $this->database->escapeLike($query) . '%', 'LIKE');
    }
    return $select->execute()->fetchCol();
  }

  /**
   * Lists entry UUIDs for a human reader inside one workspace.
   *
   * @param int $workspaceId
   *   Internal workspace id.
   * @param string[] $statuses
   *   Allowed statuses.
   * @param int $limit
   *   Maximum UUIDs to return.
   * @param string|null $kind
   *   Optional kind filter.
   * @param string|null $query
   *   Optional title substring (case-insensitive).
   *
   * @return string[]
   *   Entry UUIDs newest first.
   */
  public function uuidsForWorkspace(
    int $workspaceId,
    array $statuses,
    int $limit = 50,
    ?string $kind = NULL,
    ?string $query = NULL,
  ): array {
    if ($statuses === []) {
      return [];
    }
    $limit = max(1, min(100, $limit));
    $select = $this->database->select('ab_memory_entry', 'e')
      ->fields('e', ['uuid'])
      ->condition('e.workspace', $workspaceId)
      ->condition('e.status', $statuses, 'IN')
      ->orderBy('e.changed', 'DESC')
      ->range(0, $limit);
    if ($kind !== NULL && $kind !== '') {
      $select->condition('e.kind', $kind);
    }
    if ($query !== NULL && $query !== '') {
      $select->condition('e.title', '%' . $this->database->escapeLike($query) . '%', 'LIKE');
    }
    return $select->execute()->fetchCol();
  }

  /**
   * Resolves the workspace entity for a public UUID.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   */
  public function workspace(string $workspaceUuid): Workspace {
    $storage = $this->entityTypeManager->getStorage('ab_workspace');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $workspaceUuid)
      ->range(0, 1)
      ->execute();
    $workspace = $ids === [] ? NULL : $storage->load(reset($ids));
    if (!$workspace instanceof Workspace) {
      throw new ScopedResourceNotFoundException();
    }
    return $workspace;
  }

}
