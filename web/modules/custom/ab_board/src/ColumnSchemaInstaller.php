<?php

declare(strict_types=1);

namespace Drupal\ab_board;

/**
 * Installs the column entity on sites that already have ab_board.
 */
final class ColumnSchemaInstaller {

  /**
   * Creates the column entity schema when the table is missing.
   */
  public static function install(): string {
    $schema = \Drupal::database()->schema();
    if ($schema->tableExists('ab_column')) {
      return 'Column schema already present.';
    }
    $entity_type_manager = \Drupal::entityTypeManager();
    $entity_type_manager->clearCachedDefinitions();
    $entity_type = $entity_type_manager->getDefinition('ab_column');
    \Drupal::entityDefinitionUpdateManager()->installEntityType($entity_type);
    return 'Installed the column entity schema.';
  }

}
