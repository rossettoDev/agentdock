<?php

declare(strict_types=1);

namespace Drupal\ab_api\Http;

use Drupal\ab_memory\MemoryRecord;

/**
 * Maps MemoryRecord rows to the OpenAPI memory documents.
 */
final class MemoryDocument {

  /**
   * Full memory envelope data.
   *
   * @return array<string, mixed>
   *   Public Memory object.
   */
  public function memory(MemoryRecord $record): array {
    $document = [
      'id' => $record->uuid,
      'workspace_id' => $record->workspaceUuid,
      'board_id' => $record->boardUuid,
      'task_id' => $record->taskUuid,
      'title' => $record->title,
      'kind' => $record->kind,
      'status' => $record->status,
      'version' => $record->version,
      'tags' => $record->tags,
      'revision' => $this->revisionFromRecord($record),
      'source' => $this->source($record->sourceType, $record->sourceId),
      'created_at' => $this->iso($record->created),
      'updated_at' => $this->iso($record->changed),
    ];
    return $document;
  }

  /**
   * Compact search hit.
   *
   * @return array<string, mixed>
   *   Public MemorySearchHit object.
   */
  public function hit(MemoryRecord $record): array {
    return [
      'memory_id' => $record->uuid,
      'revision' => $record->revisionVersion,
      'title' => $record->title,
      'excerpt' => $this->excerpt($record->contentMarkdown),
      'source' => $this->source($record->sourceType, $record->sourceId),
      'board_id' => $record->boardUuid,
      'kind' => $record->kind,
      'status' => $record->status,
      'updated_at' => $this->iso($record->changed),
    ];
  }

  /**
   * One revision row from listRevisions.
   *
   * @param array<string, mixed> $row
   *   Stored revision row.
   *
   * @return array<string, mixed>
   *   Public MemoryRevision object.
   */
  public function revision(array $row): array {
    return [
      'version' => (int) $row['version'],
      'content_markdown' => (string) $row['content_markdown'],
      'content_hash' => (string) $row['content_hash'],
      'author_type' => (string) $row['author_type'],
      'author_id' => (string) $row['author_id'],
      'source_type' => (string) $row['source_type'],
      'source_id' => $row['source_id'] !== NULL ? (string) $row['source_id'] : NULL,
      'change_reason' => $row['change_reason'] !== NULL ? (string) $row['change_reason'] : NULL,
      'created_at' => $this->iso((int) $row['created']),
    ];
  }

  /**
   * Builds the nested revision from the current record.
   *
   * @return array<string, mixed>
   *   Public MemoryRevision object.
   */
  private function revisionFromRecord(MemoryRecord $record): array {
    return [
      'version' => $record->revisionVersion,
      'content_markdown' => $record->contentMarkdown,
      'content_hash' => $record->contentHash,
      'author_type' => $record->authorType,
      'author_id' => $record->authorId,
      'source_type' => $record->sourceType,
      'source_id' => $record->sourceId,
      'change_reason' => $record->changeReason,
      'created_at' => $this->iso($record->changed),
    ];
  }

  /**
   * Builds the source projection.
   *
   * @return array<string, mixed>
   *   Public MemorySource object.
   */
  private function source(string $type, ?string $id): array {
    return [
      'source_type' => $type,
      'source_id' => $id,
      'available' => TRUE,
    ];
  }

  /**
   * Truncates markdown to the OpenAPI excerpt limit.
   */
  private function excerpt(string $markdown): string {
    $plain = trim(preg_replace('/\s+/', ' ', $markdown) ?? $markdown);
    if (strlen($plain) <= 480) {
      return $plain;
    }
    return substr($plain, 0, 480);
  }

  /**
   * Formats a UTC unix timestamp as ISO-8601.
   */
  private function iso(int $stamp): string {
    return gmdate('Y-m-d\TH:i:s\Z', $stamp);
  }

}
