<?php

declare(strict_types=1);

namespace Drupal\ab_board;

/**
 * Installs the task entity on sites that already have ab_board.
 */
final class TaskSchemaInstaller {

  /**
   * Creates the task entity schema when the table is missing.
   */
  public static function install(): string {
    $schema = \Drupal::database()->schema();
    if ($schema->tableExists('ab_task')) {
      return 'Task schema already present.';
    }
    $entity_type_manager = \Drupal::entityTypeManager();
    $entity_type_manager->clearCachedDefinitions();
    $entity_type = $entity_type_manager->getDefinition('ab_task');
    \Drupal::entityDefinitionUpdateManager()->installEntityType($entity_type);
    return 'Installed the task entity schema.';
  }

}
