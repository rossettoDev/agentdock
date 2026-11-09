<?php

declare(strict_types=1);

namespace Drupal\ab_memory;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Reconstructible lexical projection for published memory.
 *
 * Access filters (workspace, boards, status) are applied in SQL before
 * ranking. The index is not the source of truth; rebuild() rebuilds it
 * from entries and revisions.
 */
final class MemorySearchIndex {

  public const EXCERPT_MAX = 480;

  public const QUERY_MAX = 256;

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Ensures PostgreSQL full-text columns exist when the driver supports them.
   */
  public function ensureFts(): void {
    if ($this->database->databaseType() !== 'pgsql') {
      return;
    }
    if (!$this->database->schema()->tableExists('ab_memory_search_index')) {
      return;
    }
    $this->database->query('ALTER TABLE {ab_memory_search_index} ADD COLUMN IF NOT EXISTS document_tsv tsvector');
    $this->database->query('CREATE INDEX IF NOT EXISTS ab_memory_search_index__tsv ON {ab_memory_search_index} USING GIN (document_tsv)');
  }

  /**
   * Upserts one entry into the projection.
   *
   * @param array<string, mixed> $entry
   *   Entry row with id, uuid, workspace, board_id, title, kind, status,
   *   version and changed.
   * @param array<string, mixed> $revision
   *   Current revision row.
   */
  public function sync(array $entry, array $revision): void {
    $status = (string) $entry['status'];
    if (!in_array($status, ['published', 'archived'], TRUE)) {
      $this->remove((int) $entry['id']);
      return;
    }
    $document = trim((string) $entry['title'] . "\n" . (string) $revision['content_markdown']);
    $excerpt = $this->excerpt((string) $revision['content_markdown']);
    $now = $this->time->getRequestTime();
    $fields = [
      'entry_uuid' => (string) $entry['uuid'],
      'workspace' => (int) $entry['workspace'],
      'board_id' => isset($entry['board_id']) ? (int) $entry['board_id'] : NULL,
      'revision_id' => (int) $revision['id'],
      'revision_version' => (int) $revision['version'],
      'status' => $status,
      'kind' => (string) $entry['kind'],
      'title' => (string) $entry['title'],
      'document' => $document,
      'excerpt' => $excerpt,
      'source_type' => (string) $revision['source_type'],
      'source_id' => $revision['source_id'] !== NULL ? (string) $revision['source_id'] : NULL,
      'changed' => (int) $entry['changed'],
      'indexed_at' => $now,
    ];
    $this->database->merge('ab_memory_search_index')
      ->keys(['entry_id' => (int) $entry['id']])
      ->fields($fields)
      ->execute();
    if ($this->database->databaseType() === 'pgsql') {
      $this->ensureFts();
      if ($this->hasTsv()) {
        $this->database->query(
          'UPDATE {ab_memory_search_index} SET document_tsv = to_tsvector(\'simple\', coalesce(document, \'\')) WHERE entry_id = :id',
          [':id' => (int) $entry['id']],
        );
      }
    }
  }

  /**
   * Drops one entry from the projection.
   */
  public function remove(int $entryId): void {
    $this->database->delete('ab_memory_search_index')
      ->condition('entry_id', $entryId)
      ->execute();
  }

  /**
   * Rebuilds the projection from durable entries.
   *
   * @param int|null $workspaceId
   *   When set, only that workspace is rebuilt.
   *
   * @return int
   *   Number of indexed rows.
   */
  public function rebuild(?int $workspaceId = NULL): int {
    $this->ensureFts();
    $delete = $this->database->delete('ab_memory_search_index');
    if ($workspaceId !== NULL) {
      $delete->condition('workspace', $workspaceId);
    }
    $delete->execute();

    $select = $this->database->select('ab_memory_entry', 'e');
    $select->fields('e');
    $select->condition('e.status', ['published', 'archived'], 'IN');
    if ($workspaceId !== NULL) {
      $select->condition('e.workspace', $workspaceId);
    }
    $count = 0;
    foreach ($select->execute() as $entry) {
      $row = (array) $entry;
      $revision = $this->database->select('ab_memory_revision', 'r')
        ->fields('r')
        ->condition('r.id', (int) $row['current_revision_id'])
        ->condition('r.entry_id', (int) $row['id'])
        ->execute()
        ->fetchAssoc();
      if ($revision === FALSE) {
        continue;
      }
      $this->sync($row, $revision);
      $count++;
    }
    return $count;
  }

  /**
   * Searches the projection with access filters applied in SQL.
   *
   * @param int $workspaceId
   *   Internal workspace id.
   * @param int[]|null $boardIds
   *   Board ids the actor may read. NULL means any board (human). Empty
   *   list means nothing is visible.
   * @param string[] $statuses
   *   Allowed statuses. Default search should pass only published.
   * @param string $query
   *   Lexical query (already trimmed, non-empty).
   * @param string|null $kind
   *   Optional kind filter.
   * @param int $limit
   *   Maximum hits.
   *
   * @return list<array{uuid: string, excerpt: string, revision_version: int, rank: float}>
   *   Hits ordered by relevance then recency.
   */
  public function search(
    int $workspaceId,
    ?array $boardIds,
    array $statuses,
    string $query,
    ?string $kind,
    int $limit = 50,
  ): array {
    if ($statuses === [] || ($boardIds !== NULL && $boardIds === [])) {
      return [];
    }
    $query = trim($query);
    if ($query === '' || strlen($query) > self::QUERY_MAX) {
      return [];
    }
    $limit = max(1, min(100, $limit));
    if ($this->database->databaseType() === 'pgsql' && $this->hasTsv()) {
      return $this->searchPgsql($workspaceId, $boardIds, $statuses, $query, $kind, $limit);
    }
    return $this->searchLike($workspaceId, $boardIds, $statuses, $query, $kind, $limit);
  }

  /**
   * Counts rows currently projected.
   */
  public function count(?int $workspaceId = NULL): int {
    $select = $this->database->select('ab_memory_search_index', 'i');
    if ($workspaceId !== NULL) {
      $select->condition('i.workspace', $workspaceId);
    }
    return (int) $select->countQuery()->execute()->fetchField();
  }

  /**
   * PostgreSQL tsvector search with rank and headline snippet.
   *
   * @param int $workspaceId
   *   Internal workspace id.
   * @param int[]|null $boardIds
   *   Board filter.
   * @param string[] $statuses
   *   Status filter.
   * @param string $query
   *   Lexical query.
   * @param string|null $kind
   *   Optional kind filter.
   * @param int $limit
   *   Maximum hits.
   *
   * @return list<array{uuid: string, excerpt: string, revision_version: int, rank: float}>
   *   Ranked hits.
   */
  private function searchPgsql(
    int $workspaceId,
    ?array $boardIds,
    array $statuses,
    string $query,
    ?string $kind,
    int $limit,
  ): array {
    $sql = 'SELECT i.entry_uuid AS uuid, i.revision_version,
        ts_rank(i.document_tsv, plainto_tsquery(\'simple\', :q)) AS rank,
        ts_headline(\'simple\', i.document, plainto_tsquery(\'simple\', :q),
          \'MaxFragments=1, MaxWords=35, MinWords=12, FragmentDelimiter=" ... "\') AS excerpt
      FROM {ab_memory_search_index} i
      WHERE i.workspace = :workspace
        AND i.status IN (:statuses[])
        AND i.document_tsv @@ plainto_tsquery(\'simple\', :q)';
    $args = [
      ':q' => $query,
      ':workspace' => $workspaceId,
      ':statuses[]' => $statuses,
    ];
    if ($boardIds !== NULL) {
      $sql .= ' AND i.board_id IN (:boards[])';
      $args[':boards[]'] = $boardIds;
    }
    if ($kind !== NULL && $kind !== '') {
      $sql .= ' AND i.kind = :kind';
      $args[':kind'] = $kind;
    }
    $sql .= ' ORDER BY rank DESC, i.changed DESC LIMIT ' . $limit;
    $rows = $this->database->query($sql, $args)->fetchAll(\PDO::FETCH_ASSOC);
    $hits = [];
    foreach ($rows as $row) {
      $excerpt = trim(strip_tags((string) $row['excerpt']));
      if ($excerpt === '') {
        $excerpt = $this->excerpt((string) ($row['excerpt'] ?? ''));
      }
      $hits[] = [
        'uuid' => (string) $row['uuid'],
        'excerpt' => $this->clip($excerpt),
        'revision_version' => (int) $row['revision_version'],
        'rank' => (float) $row['rank'],
      ];
    }
    return $hits;
  }

  /**
   * Fallback substring search when FTS is unavailable.
   *
   * @param int $workspaceId
   *   Internal workspace id.
   * @param int[]|null $boardIds
   *   Board filter.
   * @param string[] $statuses
   *   Status filter.
   * @param string $query
   *   Lexical query.
   * @param string|null $kind
   *   Optional kind filter.
   * @param int $limit
   *   Maximum hits.
   *
   * @return list<array{uuid: string, excerpt: string, revision_version: int, rank: float}>
   *   Hits newest first.
   */
  private function searchLike(
    int $workspaceId,
    ?array $boardIds,
    array $statuses,
    string $query,
    ?string $kind,
    int $limit,
  ): array {
    $select = $this->database->select('ab_memory_search_index', 'i')
      ->fields('i', ['entry_uuid', 'excerpt', 'revision_version', 'changed'])
      ->condition('i.workspace', $workspaceId)
      ->condition('i.status', $statuses, 'IN')
      ->condition('i.document', '%' . $this->database->escapeLike($query) . '%', 'LIKE')
      ->orderBy('i.changed', 'DESC')
      ->range(0, $limit);
    if ($boardIds !== NULL) {
      $select->condition('i.board_id', $boardIds, 'IN');
    }
    if ($kind !== NULL && $kind !== '') {
      $select->condition('i.kind', $kind);
    }
    $hits = [];
    foreach ($select->execute() as $row) {
      $hits[] = [
        'uuid' => (string) $row->entry_uuid,
        'excerpt' => $this->clip((string) $row->excerpt),
        'revision_version' => (int) $row->revision_version,
        'rank' => 0.0,
      ];
    }
    return $hits;
  }

  /**
   * Whether the tsvector column is present.
   */
  private function hasTsv(): bool {
    return $this->database->schema()->fieldExists('ab_memory_search_index', 'document_tsv');
  }

  /**
   * Builds a plain excerpt under the OpenAPI limit.
   */
  private function excerpt(string $markdown): string {
    $plain = trim(preg_replace('/\s+/', ' ', $markdown) ?? $markdown);
    return $this->clip($plain);
  }

  /**
   * Clips text to EXCERPT_MAX bytes.
   */
  private function clip(string $text): string {
    if (strlen($text) <= self::EXCERPT_MAX) {
      return $text;
    }
    return substr($text, 0, self::EXCERPT_MAX);
  }

}
