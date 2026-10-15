<?php

declare(strict_types=1);

namespace Drupal\ab_core\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Associates one user with one workspace.
 */
#[ContentEntityType(
  id: 'ab_membership',
  label: new TranslatableMarkup('Membership'),
  label_collection: new TranslatableMarkup('Memberships'),
  label_singular: new TranslatableMarkup('membership'),
  label_plural: new TranslatableMarkup('memberships'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'workspace' => 'workspace',
    'user' => 'user',
  ],
  handlers: [
    'storage_schema' => 'Drupal\ab_core\MembershipStorageSchema',
    'access' => 'Drupal\ab_core\Authorization\MembershipAccessControlHandler',
  ],
  base_table: 'ab_membership',
  constraints: [
    'AbMembershipUnique' => [],
    'AbSameWorkspace' => [],
  ],
)]
class Membership extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['workspace'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Workspace'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'ab_workspace')
      ->addPropertyConstraints('target_id', [
        'AbMembershipReference' => [],
      ]);

    $fields['user'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('User'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'ab_membership')
      ->addPropertyConstraints('target_id', [
        'AbMembershipReference' => [],
      ]);

    $fields['role'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Role'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'member' => 'Member',
        'viewer' => 'Viewer',
      ]);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('active')
      ->setSetting('allowed_values', [
        'active' => 'Active',
        'revoked' => 'Revoked',
      ]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    $violations = $this->validate();
    if ($violations->count() > 0) {
      throw new EntityStorageException((string) $violations);
    }
  }

}
