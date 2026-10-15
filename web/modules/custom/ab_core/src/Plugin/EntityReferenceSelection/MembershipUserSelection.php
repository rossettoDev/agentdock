<?php

declare(strict_types=1);

namespace Drupal\ab_core\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Validates a membership user by existence.
 *
 * Adding a registered user must not depend on the actor being allowed to view
 * that account. The query used for autocomplete stays access-checked.
 */
#[EntityReferenceSelection(
  id: 'ab_membership:user',
  label: new TranslatableMarkup('Membership user selection'),
  entity_types: ['user'],
  group: 'ab_membership',
  weight: 0,
)]
final class MembershipUserSelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  public function validateReferenceableEntities(array $ids) {
    if ($ids === []) {
      return [];
    }
    $entity_type = $this->entityTypeManager->getDefinition('user');
    return $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition($entity_type->getKey('id'), $ids, 'IN')
      ->execute();
  }

}
