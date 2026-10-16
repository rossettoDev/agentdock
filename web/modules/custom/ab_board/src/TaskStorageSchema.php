<?php

declare(strict_types=1);

namespace Drupal\ab_board;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Adds the board listing index for tasks.
 */
class TaskStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $entity_type->getBaseTable();
    $schema[$base_table]['indexes']['workspace_board_status_position'] = [
      'workspace',
      'board',
      'status',
      'position',
    ];
    return $schema;
  }

}
