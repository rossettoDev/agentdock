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

}
