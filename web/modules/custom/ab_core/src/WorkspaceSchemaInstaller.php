<?php

declare(strict_types=1);

namespace Drupal\ab_core;

/**
 * Installs the workspace entity on sites that already have ab_core.
 */
final class WorkspaceSchemaInstaller {

  /**
   * Creates the workspace entity schema when the table is missing.
   */
  public static function install(): string {
    $schema = \Drupal::database()->schema();
    if ($schema->tableExists('ab_workspace')) {
      return 'Workspace schema already present.';
    }
    $entity_type_manager = \Drupal::entityTypeManager();
    $entity_type_manager->clearCachedDefinitions();
    $entity_type = $entity_type_manager->getDefinition('ab_workspace');
    \Drupal::entityDefinitionUpdateManager()->installEntityType($entity_type);
    return 'Installed the workspace entity schema.';
  }

}
