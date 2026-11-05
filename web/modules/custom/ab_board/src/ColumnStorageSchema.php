<?php

declare(strict_types=1);

namespace Drupal\ab_board;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Keeps one machine key per board.
 */
class ColumnStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $entity_type->getBaseTable();
    $schema[$base_table]['unique keys']['board_machine'] = [
      'board',
      'machine',
    ];
    $schema[$base_table]['indexes']['board_position'] = [
      'board',
      'position',
    ];
    return $schema;
  }

}
