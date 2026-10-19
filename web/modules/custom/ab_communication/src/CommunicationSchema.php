<?php

declare(strict_types=1);

namespace Drupal\ab_communication;

/**
 * Installs conversation tables on sites that already have the module.
 */
final class CommunicationSchema {

  /**
   * Schema for the one thread of a board or a task.
   *
   * A board thread stores task_id 0. A nullable task would let
   * PostgreSQL keep more than one board thread.
   *
   * @return array<string, mixed>
   *   A hook_schema table definition.
   */
  public static function threadTable(): array {
    return [
      'description' => 'One conversation thread for a board or a task.',
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
          'description' => 'Workspace that owns the thread.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'board_id' => [
          'description' => 'Board this thread belongs to.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'task_id' => [
          'description' => 'Task for a task thread, or 0 for the board thread.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
        'created' => [
          'description' => 'UTC timestamp when the thread was created.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'uuid' => ['uuid'],
        'board_task' => ['board_id', 'task_id'],
      ],
      'indexes' => [
        'workspace' => ['workspace'],
      ],
    ];
  }

  /**
   * Schema for an immutable message.
   *
   * @return array<string, mixed>
   *   A hook_schema table definition.
   */
  public static function messageTable(): array {
    return [
      'description' => 'Messages appended to a conversation thread.',
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
          'description' => 'Workspace copied from the thread.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'thread_id' => [
          'description' => 'Thread that holds the message.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'actor_type' => [
          'description' => 'human, agent or system.',
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
        ],
        'actor_id' => [
          'description' => 'Authenticated actor id.',
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
        ],
        'kind' => [
          'description' => 'Allowed message kind.',
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
        ],
        'body' => [
          'description' => 'Markdown source, at most 16 KiB.',
          'type' => 'text',
          'size' => 'big',
          'not null' => TRUE,
        ],
        'reply_to' => [
          'description' => 'Message in the same thread, when this is a reply.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
        'execution_id' => [
          'description' => 'Execution of the same task, when the agent links one.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
        'created' => [
          'description' => 'UTC timestamp when the message was stored.',
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
        'thread_created' => ['thread_id', 'created', 'id'],
        'workspace' => ['workspace'],
      ],
    ];
  }

  /**
   * Schema for one structured question tied to an execution.
   *
   * @return array<string, mixed>
   *   A hook_schema table definition.
   */
  public static function inputTable(): array {
    return [
      'description' => 'A question that holds an execution until a human answers.',
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
          'description' => 'Workspace that owns the request.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'task_id' => [
          'description' => 'Task the question belongs to.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'execution_id' => [
          'description' => 'Execution that asked the question.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'question_message_id' => [
          'description' => 'Message that carries the question.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'status' => [
          'description' => 'open or answered.',
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
        ],
        'answer_message_id' => [
          'description' => 'Message that resolved the question.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
        'created' => [
          'description' => 'UTC timestamp when the request was stored.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'resolved_at' => [
          'description' => 'UTC timestamp when a human answered.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'uuid' => ['uuid'],
        'question_message' => ['question_message_id'],
      ],
      'indexes' => [
        'execution_status' => ['execution_id', 'status'],
        'task' => ['task_id'],
      ],
    ];
  }

  /**
   * Creates the thread and message tables.
   */
  public static function install(): string {
    $schema = \Drupal::database()->schema();
    $messages = [];
    if (!$schema->tableExists('ab_thread')) {
      $schema->createTable('ab_thread', self::threadTable());
      $messages[] = 'Created the thread table.';
    }
    if (!$schema->tableExists('ab_message')) {
      $schema->createTable('ab_message', self::messageTable());
      $messages[] = 'Created the message table.';
    }
    if ($messages === []) {
      return 'Conversation schema already present.';
    }
    return implode(' ', $messages);
  }

  /**
   * Creates the input request table on an existing site.
   */
  public static function installInput(): string {
    $schema = \Drupal::database()->schema();
    if ($schema->tableExists('ab_input_request')) {
      return 'Input request schema already present.';
    }
    $schema->createTable('ab_input_request', self::inputTable());
    return 'Created the input request table.';
  }

}
