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
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A private work area owned by one user.
 */
#[ContentEntityType(
  id: 'ab_workspace',
  label: new TranslatableMarkup('Workspace'),
  label_collection: new TranslatableMarkup('Workspaces'),
  label_singular: new TranslatableMarkup('workspace'),
  label_plural: new TranslatableMarkup('workspaces'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'owner' => 'owner',
  ],
  handlers: [
    'storage_schema' => 'Drupal\ab_core\WorkspaceStorageSchema',
    'access' => 'Drupal\ab_core\Authorization\WorkspaceAccessControlHandler',
  ],
  base_table: 'ab_workspace',
)]
class Workspace extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['owner'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner'))
      ->setDescription(new TranslatableMarkup('User who owns the workspace.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->addPropertyConstraints('target_id', [
        'AbWorkspaceOwner' => [],
      ]);

    $fields['timezone'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Timezone'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->addPropertyConstraints('value', [
        'AbWorkspaceTimezone' => [],
      ]);

    $fields['personal'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Personal'))
      ->setDescription(new TranslatableMarkup('Marks the one personal workspace of its owner.'))
      ->setDefaultValue(FALSE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('active')
      ->setSetting('allowed_values', [
        'active' => 'Active',
        'archived' => 'Archived',
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
