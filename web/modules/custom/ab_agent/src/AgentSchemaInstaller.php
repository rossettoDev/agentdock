<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * Installs agent storage on sites that already have the module.
 */
final class AgentSchemaInstaller {

  /**
   * Schema for the grant table.
   *
   * @return array<string, mixed>
   *   A hook_schema table definition.
   */
  public static function grantTable(): array {
    return [
      'description' => 'Permissions an agent has on one board of its workspace.',
      'fields' => [
        'workspace' => [
          'description' => 'Workspace id copied from the agent.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'agent_id' => [
          'description' => 'Agent that receives the grant.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'board_id' => [
          'description' => 'Board in the same workspace.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'permissions' => [
          'description' => 'Comma-separated permissions inside the allowed set.',
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['agent_id', 'board_id'],
      'indexes' => [
        'workspace' => ['workspace'],
        'board_id' => ['board_id'],
      ],
    ];
  }

  /**
   * Creates the missing agent storage.
   */
  public static function install(): string {
    $schema = \Drupal::database()->schema();
    $messages = [];
    if (!$schema->tableExists('ab_agent')) {
      $manager = \Drupal::entityTypeManager();
      $manager->clearCachedDefinitions();
      $entity_type = $manager->getDefinition('ab_agent');
      \Drupal::entityDefinitionUpdateManager()->installEntityType($entity_type);
      $messages[] = 'Installed the agent entity schema.';
    }
    if (!$schema->tableExists('ab_agent_board_grant')) {
      $schema->createTable('ab_agent_board_grant', self::grantTable());
      $messages[] = 'Created the agent grant table.';
    }
    if ($messages === []) {
      return 'Agent schema already present.';
    }
    return implode(' ', $messages);
  }

  /**
   * Schema for the token table.
   *
   * The secret itself is not a column. Only its hash is stored.
   *
   * @return array<string, mixed>
   *   A hook_schema table definition.
   */
  public static function tokenTable(): array {
    return [
      'description' => 'Hashed bearer credentials for one agent.',
      'fields' => [
        'token_id' => [
          'description' => 'Public id. The secret is not stored.',
          'type' => 'varchar',
          'length' => 32,
          'not null' => TRUE,
        ],
        'workspace' => [
          'description' => 'Workspace id copied from the agent.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'agent_id' => [
          'description' => 'Agent that owns the credential.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'secret_hash' => [
          'description' => 'SHA-256 of the secret, hex encoded.',
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
        ],
        'scopes' => [
          'description' => 'Comma-separated token scopes.',
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
        ],
        'expires_at' => [
          'description' => 'UTC timestamp when the credential stops working.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'revoked_at' => [
          'description' => 'UTC timestamp when the credential was revoked.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
        'last_used_at' => [
          'description' => 'UTC timestamp of the last accepted use.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
        'created' => [
          'description' => 'UTC timestamp when the credential was issued.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['token_id'],
      'indexes' => [
        'workspace' => ['workspace'],
        'agent_id' => ['agent_id'],
      ],
    ];
  }

  /**
   * Creates the token table and the blocked runtime account.
   */
  public static function installTokens(): string {
    $schema = \Drupal::database()->schema();
    $messages = [];
    if (!$schema->tableExists('ab_agent_token')) {
      $schema->createTable('ab_agent_token', self::tokenTable());
      $messages[] = 'Created the agent token table.';
    }
    AgentRuntimeAccount::ensure();
    $messages[] = 'Ensured the blocked agent runtime account.';
    return implode(' ', $messages);
  }

  /**
   * Schema for the one lease row of a task.
   *
   * The secret itself is not a column. Only its hash is stored.
   *
   * @return array<string, mixed>
   *   A hook_schema table definition.
   */
  public static function leaseTable(): array {
    return [
      'description' => 'Exclusive lease that reserves one task for one execution.',
      'fields' => [
        'task_id' => [
          'description' => 'Task this lease reserves. One row per task.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'execution_id' => [
          'description' => 'Execution that holds the lease.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'workspace' => [
          'description' => 'Workspace id copied from the task.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'agent_id' => [
          'description' => 'Agent that claimed the task.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'token_hash' => [
          'description' => 'SHA-256 of the lease secret, hex encoded.',
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
        ],
        'expires_at' => [
          'description' => 'UTC timestamp when the lease stops being current.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'generation' => [
          'description' => 'Increments when a later story replaces the row.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'created' => [
          'description' => 'UTC timestamp when the lease was written.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['task_id'],
      'indexes' => [
        'execution_id' => ['execution_id'],
        'workspace' => ['workspace'],
      ],
    ];
  }

  /**
   * Installs the execution entity and the lease table.
   */
  public static function installExecution(): string {
    $schema = \Drupal::database()->schema();
    $messages = [];
    if (!$schema->tableExists('ab_execution')) {
      $manager = \Drupal::entityTypeManager();
      $manager->clearCachedDefinitions();
      $entity_type = $manager->getDefinition('ab_execution');
      \Drupal::entityDefinitionUpdateManager()->installEntityType($entity_type);
      $messages[] = 'Installed the execution entity schema.';
    }
    if (!$schema->tableExists('ab_execution_lease')) {
      $schema->createTable('ab_execution_lease', self::leaseTable());
      $messages[] = 'Created the execution lease table.';
    }
    if ($messages === []) {
      return 'Execution schema already present.';
    }
    return implode(' ', $messages);
  }

  /**
   * Schema for short progress notes on one execution.
   *
   * The note is plain text. A conversation thread is a later story.
   *
   * @return array<string, mixed>
   *   A hook_schema table definition.
   */
  public static function progressTable(): array {
    return [
      'description' => 'Limited progress notes recorded while a lease is valid.',
      'fields' => [
        'execution_id' => [
          'description' => 'Execution the note belongs to.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'sequence' => [
          'description' => 'Order of the note within the execution.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'workspace' => [
          'description' => 'Workspace id copied from the execution.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'body' => [
          'description' => 'Plain progress note, at most 500 characters.',
          'type' => 'varchar',
          'length' => 500,
          'not null' => TRUE,
        ],
        'created' => [
          'description' => 'UTC timestamp when the note was written.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['execution_id', 'sequence'],
      'indexes' => [
        'workspace' => ['workspace'],
      ],
    ];
  }

  /**
   * Schema for the links delivered with a result.
   *
   * Only http and https addresses are stored.
   *
   * @return array<string, mixed>
   *   A hook_schema table definition.
   */
  public static function linkTable(): array {
    return [
      'description' => 'Result links stored when an execution is submitted.',
      'fields' => [
        'execution_id' => [
          'description' => 'Execution that delivered the link.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'delta' => [
          'description' => 'Position of the link in the delivery.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'workspace' => [
          'description' => 'Workspace id copied from the execution.',
          'type' => 'int',
          'size' => 'big',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'url' => [
          'description' => 'http or https address.',
          'type' => 'varchar',
          'length' => 500,
          'not null' => TRUE,
        ],
        'label' => [
          'description' => 'Short label. Empty when the address is the label.',
          'type' => 'varchar',
          'length' => 80,
          'not null' => TRUE,
          'default' => '',
        ],
      ],
      'primary key' => ['execution_id', 'delta'],
      'indexes' => [
        'workspace' => ['workspace'],
      ],
    ];
  }

  /**
   * Creates the progress and result-link tables.
   */
  public static function installReview(): string {
    $schema = \Drupal::database()->schema();
    $messages = [];
    if (!$schema->tableExists('ab_execution_progress')) {
      $schema->createTable('ab_execution_progress', self::progressTable());
      $messages[] = 'Created the execution progress table.';
    }
    if (!$schema->tableExists('ab_execution_link')) {
      $schema->createTable('ab_execution_link', self::linkTable());
      $messages[] = 'Created the execution link table.';
    }
    if ($messages === []) {
      return 'Review schema already present.';
    }
    return implode(' ', $messages);
  }

}
