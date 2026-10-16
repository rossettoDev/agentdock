<?php

declare(strict_types=1);

namespace Drupal\ab_board\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A project board that belongs to one workspace.
 */
#[ContentEntityType(
  id: 'ab_board',
  label: new TranslatableMarkup('Board'),
  label_collection: new TranslatableMarkup('Boards'),
  label_singular: new TranslatableMarkup('board'),
  label_plural: new TranslatableMarkup('boards'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'workspace' => 'workspace',
  ],
  handlers: [
    'access' => 'Drupal\ab_board\Authorization\BoardAccessControlHandler',
  ],
  base_table: 'ab_board',
  constraints: [
    'AbSameWorkspace' => [],
  ],
)]
class Board extends ContentEntityBase {

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

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDefaultValue('');

    $fields['archived'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Archived'))
      ->setDefaultValue(FALSE);

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
    $original = $this->getOriginal();
    $workspace_changed = $original instanceof self
      && $original->get('workspace')->getString() !== $this->get('workspace')->getString();
    if ($workspace_changed) {
      throw new EntityStorageException('A board cannot move to another workspace.');
    }
    $violations = $this->validate();
    if ($violations->count() > 0) {
      throw new EntityStorageException((string) $violations);
    }
  }

}
