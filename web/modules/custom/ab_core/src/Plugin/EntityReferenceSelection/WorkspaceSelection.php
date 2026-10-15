<?php

declare(strict_types=1);

namespace Drupal\ab_core\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Selects workspaces by existence, and lists only visible ones.
 *
 * Reference validation must not depend on membership access: the first
 * membership is what grants that access. Autocomplete still uses the
 * access-checked query.
 */
#[EntityReferenceSelection(
  id: 'default:ab_workspace',
  label: new TranslatableMarkup('Workspace selection'),
  entity_types: ['ab_workspace'],
  group: 'default',
  weight: 1,
)]
final class WorkspaceSelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  public function validateReferenceableEntities(array $ids) {
    if ($ids === []) {
      return [];
    }
    $entity_type = $this->entityTypeManager->getDefinition('ab_workspace');
    return $this->entityTypeManager->getStorage('ab_workspace')->getQuery()
      ->accessCheck(FALSE)
      ->condition($entity_type->getKey('id'), $ids, 'IN')
      ->execute();
  }

}
