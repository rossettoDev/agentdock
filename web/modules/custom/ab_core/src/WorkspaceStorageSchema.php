<?php

declare(strict_types=1);

namespace Drupal\ab_core;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Adds the partial unique index for one personal workspace per owner.
 */
class WorkspaceStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  public function onEntityTypeCreate(EntityTypeInterface $entity_type) {
    parent::onEntityTypeCreate($entity_type);
    PersonalWorkspaceSchema::ensureIndex($this->database);
  }

}
