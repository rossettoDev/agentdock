<?php

declare(strict_types=1);

namespace Drupal\ab_memory;

/**
 * Public projection of a memory entry and its current revision.
 */
final class MemoryRecord {

  /**
   * Builds the projection returned by MemoryService.
   *
   * @param string $uuid
   *   Entry UUID.
   * @param string $workspaceUuid
   *   Workspace UUID.
   * @param string|null $boardUuid
   *   Board UUID when visibility is board.
   * @param string|null $taskUuid
   *   Optional task UUID.
   * @param string $title
   *   Entry title.
   * @param string $kind
   *   Memory kind.
   * @param string $status
   *   One of draft, published or archived.
   * @param int $version
   *   Optimistic concurrency version.
   * @param string[] $tags
   *   Normalized tags.
   * @param int $revisionVersion
   *   Current revision number.
   * @param string $contentMarkdown
   *   Current markdown body.
   * @param string $contentHash
   *   SHA-256 of the normalized markdown.
   * @param string $authorType
   *   Author actor type.
   * @param string $authorId
   *   Author actor id.
   * @param string $sourceType
   *   Source type of the current revision.
   * @param string|null $sourceId
   *   Opaque source id.
   * @param string|null $changeReason
   *   Optional change reason.
   * @param int $created
   *   Entry created timestamp.
   * @param int $changed
   *   Entry changed timestamp.
   */
  public function __construct(
    public readonly string $uuid,
    public readonly string $workspaceUuid,
    public readonly ?string $boardUuid,
    public readonly ?string $taskUuid,
    public readonly string $title,
    public readonly string $kind,
    public readonly string $status,
    public readonly int $version,
    public readonly array $tags,
    public readonly int $revisionVersion,
    public readonly string $contentMarkdown,
    public readonly string $contentHash,
    public readonly string $authorType,
    public readonly string $authorId,
    public readonly string $sourceType,
    public readonly ?string $sourceId,
    public readonly ?string $changeReason,
    public readonly int $created,
    public readonly int $changed,
  ) {}

}
