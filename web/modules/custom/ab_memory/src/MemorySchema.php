<?php

declare(strict_types=1);

namespace Drupal\ab_memory;

/**
 * Installs memory tables on new and existing sites.
 */
final class MemorySchema {

  /**
   * Schema for one memory entry.
   *
   * @return array<string, mixed>
   *   A hook_schema table definition.
   */
  public static function entryTable(): array {
    return [
      'description' => 'Shared memory entry scoped to one workspace.',
      'fields' => [
        'id' => [
          'description' => 'Primary identifier.',
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'size' => 'big',
        ],
        'uuid' => [
          'description' => 'Public identifier.',
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
        ],
        'workspace' => [
          'description' => 'Workspace that owns the entry.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'board_id' => [
          'description' => 'Optional board for board visibility.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
        'task_id' => [
          'description' => 'Optional task reference in the same workspace.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
        'title' => [
          'description' => 'Short title.',
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
        ],
        'kind' => [
          'description' => 'decision, fact, procedure, lesson or summary.',
          'type' => 'varchar',
          'length' => 32,
          'not null' => TRUE,
        ],
        'status' => [
          'description' => 'draft, published or archived.',
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
        ],
        'current_revision_id' => [
          'description' => 'Current revision row id.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
        'tags' => [
          'description' => 'JSON list of tags.',
          'type' => 'text',
          'size' => 'normal',
          'not null' => TRUE,
        ],
        'version' => [
          'description' => 'Optimistic concurrency version.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 1,
        ],
        'created' => [
          'description' => 'UTC timestamp when the entry was created.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'changed' => [
          'description' => 'UTC timestamp of the last mutation.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'uuid' => ['uuid'],
      ],
      'indexes' => [
        'workspace_status' => ['workspace', 'status'],
        'workspace_board' => ['workspace', 'board_id'],
      ],
    ];
  }

  /**
   * Schema for an immutable revision.
   *
   * @return array<string, mixed>
   *   A hook_schema table definition.
   */
  public static function revisionTable(): array {
    return [
      'description' => 'Immutable content revisions of a memory entry.',
      'fields' => [
        'id' => [
          'description' => 'Primary identifier.',
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'size' => 'big',
        ],
        'entry_id' => [
          'description' => 'Entry this revision belongs to.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'version' => [
          'description' => 'Monotonic revision number for the entry.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'content_markdown' => [
          'description' => 'Markdown body, at most 64 KiB.',
          'type' => 'text',
          'size' => 'big',
          'not null' => TRUE,
        ],
        'content_hash' => [
          'description' => 'SHA-256 of the normalized markdown.',
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
        ],
        'author_type' => [
          'description' => 'human, agent or system.',
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
        ],
        'author_id' => [
          'description' => 'Author taken from ActorContext.',
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
        ],
        'source_type' => [
          'description' => 'manual, task, execution or message.',
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
          'default' => 'manual',
        ],
        'source_id' => [
          'description' => 'Opaque source id in the same workspace.',
          'type' => 'varchar',
          'length' => 64,
          'not null' => FALSE,
        ],
        'change_reason' => [
          'description' => 'Optional reason for the revision.',
          'type' => 'varchar',
          'length' => 500,
          'not null' => FALSE,
        ],
        'created' => [
          'description' => 'UTC timestamp when the revision was written.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'entry_version' => ['entry_id', 'version'],
      ],
      'indexes' => [
        'entry_id' => ['entry_id'],
      ],
    ];
  }

  /**
   * Schema for typed source references.
   *
   * @return array<string, mixed>
   *   A hook_schema table definition.
   */
  public static function sourceTable(): array {
    return [
      'description' => 'Origin references for a memory entry.',
      'fields' => [
        'id' => [
          'description' => 'Primary identifier.',
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'size' => 'big',
        ],
        'entry_id' => [
          'description' => 'Entry that cites the source.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'revision_id' => [
          'description' => 'Revision that recorded the source.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'source_type' => [
          'description' => 'manual, task, execution or message.',
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
        ],
        'source_id' => [
          'description' => 'Opaque source id.',
          'type' => 'varchar',
          'length' => 64,
          'not null' => FALSE,
        ],
        'snapshot' => [
          'description' => 'Minimal JSON snapshot without secrets.',
          'type' => 'text',
          'size' => 'normal',
          'not null' => TRUE,
        ],
        'available' => [
          'description' => 'Whether the source may still be resolved.',
          'type' => 'int',
          'size' => 'tiny',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 1,
        ],
        'created' => [
          'description' => 'UTC timestamp when the source row was written.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['id'],
      'indexes' => [
        'entry_id' => ['entry_id'],
        'source' => ['source_type', 'source_id'],
      ],
    ];
  }

  /**
   * Schema for relations between entries in the same workspace.
   *
   * @return array<string, mixed>
   *   A hook_schema table definition.
   */
  public static function linkTable(): array {
    return [
      'description' => 'Typed links between memory entries.',
      'fields' => [
        'id' => [
          'description' => 'Primary identifier.',
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'size' => 'big',
        ],
        'workspace' => [
          'description' => 'Workspace both entries belong to.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'from_entry_id' => [
          'description' => 'Source entry.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'to_entry_id' => [
          'description' => 'Target entry.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'relation' => [
          'description' => 'related, supersedes, contradicts or supports.',
          'type' => 'varchar',
          'length' => 32,
          'not null' => TRUE,
        ],
        'created' => [
          'description' => 'UTC timestamp when the link was created.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'pair_relation' => ['from_entry_id', 'to_entry_id', 'relation'],
      ],
      'indexes' => [
        'workspace' => ['workspace'],
        'to_entry' => ['to_entry_id'],
      ],
    ];
  }

  /**
   * Creates every memory table when missing.
   */
  public static function install(): string {
    $schema = \Drupal::database()->schema();
    $messages = [];
    $tables = [
      'ab_memory_entry' => self::entryTable(),
      'ab_memory_revision' => self::revisionTable(),
      'ab_memory_source' => self::sourceTable(),
      'ab_memory_link' => self::linkTable(),
    ];
    foreach ($tables as $name => $definition) {
      if (!$schema->tableExists($name)) {
        $schema->createTable($name, $definition);
        $messages[] = sprintf('Created the %s table.', $name);
      }
    }
    if ($messages === []) {
      return 'Memory schema already present.';
    }
    return implode(' ', $messages);
  }

}
