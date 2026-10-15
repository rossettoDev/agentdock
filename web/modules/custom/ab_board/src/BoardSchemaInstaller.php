<?php

declare(strict_types=1);

namespace Drupal\ab_board;

/**
 * Installs the board entity on sites that already have ab_board.
 */
final class BoardSchemaInstaller {

  /**
   * Creates the board entity schema when the table is missing.
   */
  public static function install(): string {
    $schema = \Drupal::database()->schema();
    if ($schema->tableExists('ab_board')) {
      return 'Board schema already present.';
    }
    $entity_type_manager = \Drupal::entityTypeManager();
    $entity_type_manager->clearCachedDefinitions();
    $entity_type = $entity_type_manager->getDefinition('ab_board');
    \Drupal::entityDefinitionUpdateManager()->installEntityType($entity_type);
    return 'Installed the board entity schema.';
  }

}
