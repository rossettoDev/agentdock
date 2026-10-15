<?php

declare(strict_types=1);

namespace Drupal\ab_core;

use Drupal\Core\Database\Connection;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Installs the personal-workspace field and its owner index.
 */
final class PersonalWorkspaceSchema {

  /**
   * Adds the field and the partial unique index when they are missing.
   */
  public static function install(): string {
    $database = \Drupal::database();
    $schema = $database->schema();
    $messages = [];
    if (!$schema->fieldExists('ab_workspace', 'personal')) {
      $definitions = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('ab_workspace');
      $definition = $definitions['personal'] ?? NULL;
      if (!$definition instanceof BaseFieldDefinition) {
        throw new \RuntimeException('The personal workspace field definition is missing.');
      }
      $definition->setInitialValue(0);
      \Drupal::entityDefinitionUpdateManager()->installFieldStorageDefinition('personal', 'ab_workspace', 'ab_core', $definition);
      $messages[] = 'Installed the personal workspace field.';
    }
    if (!self::indexExists($database)) {
      self::ensureIndex($database);
      $messages[] = 'Installed the personal owner index.';
    }
    if ($messages === []) {
      return 'Personal workspace schema already present.';
    }
    return implode(' ', $messages);
  }

  /**
   * Creates the partial unique index when it is missing.
   */
  public static function ensureIndex(Connection $database): void {
    if (self::indexExists($database)) {
      return;
    }
    $index = self::indexName($database);
    $database->query('CREATE UNIQUE INDEX [' . $index . '] ON {ab_workspace} (owner) WHERE personal = 1');
  }

  /**
   * Checks whether the partial unique index exists.
   */
  public static function indexExists(Connection $database): bool {
    return (bool) $database->query('SELECT 1 FROM pg_indexes WHERE indexname = :name', [
      ':name' => self::indexName($database),
    ])->fetchField();
  }

  /**
   * Builds an index name that stays unique under the test table prefix.
   */
  public static function indexName(Connection $database): string {
    $table = trim($database->prefixTables('{ab_workspace}'), '"');
    return $table . '__personal_owner__key';
  }

}
