<?php

declare(strict_types=1);

namespace Drupal\ab_core;

/**
 * Installs the membership entity on sites that already have ab_core.
 */
final class MembershipSchemaInstaller {

  /**
   * Creates the membership entity schema when the table is missing.
   */
  public static function install(): string {
    $schema = \Drupal::database()->schema();
    if ($schema->tableExists('ab_membership')) {
      return 'Membership schema already present.';
    }
    $entity_type_manager = \Drupal::entityTypeManager();
    $entity_type_manager->clearCachedDefinitions();
    $entity_type = $entity_type_manager->getDefinition('ab_membership');
    \Drupal::entityDefinitionUpdateManager()->installEntityType($entity_type);
    return 'Installed the membership entity schema.';
  }

}
