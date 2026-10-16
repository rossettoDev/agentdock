<?php

declare(strict_types=1);

namespace Drupal\ab_board\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Selects boards by existence, and lists only visible ones.
 *
 * Reference validation must not use an access-checked query. That query
 * loads memberships while the referencing entity is already in a transaction,
 * and PostgreSQL rejects the nested savepoint.
 */
#[EntityReferenceSelection(
  id: 'default:ab_board',
  label: new TranslatableMarkup('Board selection'),
  entity_types: ['ab_board'],
  group: 'default',
  weight: 1,
)]
final class BoardSelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  public function validateReferenceableEntities(array $ids) {
    if ($ids === []) {
      return [];
    }
    $entity_type = $this->entityTypeManager->getDefinition('ab_board');
    return $this->entityTypeManager->getStorage('ab_board')->getQuery()
      ->accessCheck(FALSE)
      ->condition($entity_type->getKey('id'), $ids, 'IN')
      ->execute();
  }

}
