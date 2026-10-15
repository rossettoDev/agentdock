<?php

declare(strict_types=1);

namespace Drupal\ab_core;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Adds the workspace/user uniqueness required by membership.
 */
class MembershipStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $entity_type->getBaseTable();
    $schema[$base_table]['unique keys']['workspace_user'] = [
      'workspace',
      'user',
    ];
    return $schema;
  }

}
