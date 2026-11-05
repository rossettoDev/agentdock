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
 * One column of a board.
 *
 * The machine key is the task status. Renaming changes the label only.
 */
#[ContentEntityType(
  id: 'ab_column',
  label: new TranslatableMarkup('Column'),
  label_collection: new TranslatableMarkup('Columns'),
  label_singular: new TranslatableMarkup('column'),
  label_plural: new TranslatableMarkup('columns'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'workspace' => 'workspace',
    'board' => 'board',
  ],
  handlers: [
    'storage_schema' => 'Drupal\ab_board\ColumnStorageSchema',
    'access' => 'Drupal\ab_board\Authorization\ColumnAccessControlHandler',
  ],
  base_table: 'ab_column',
  constraints: [
    'AbSameWorkspace' => [],
  ],
)]
class Column extends ContentEntityBase {

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

    $fields['board'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Board'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'ab_board')
      ->addPropertyConstraints('target_id', [
        'AbMembershipReference' => [],
      ]);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 80);

    $fields['machine'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Key'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);

    $fields['position'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Position'))
      ->setRequired(TRUE)
      ->setDefaultValue(0)
      ->setSetting('unsigned', TRUE);

    $fields['archived'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Archived'))
      ->setDefaultValue(FALSE);

    $fields['locked'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Locked'))
      ->setDefaultValue(FALSE);

    $fields['system'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('System'))
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
    if ($original instanceof self) {
      $moved = $original->get('workspace')->getString() !== $this->get('workspace')->getString()
        || $original->get('board')->getString() !== $this->get('board')->getString();
      $rekeyed = $original->get('machine')->getString() !== $this->get('machine')->getString();
      if ($moved || $rekeyed) {
        throw new EntityStorageException('A column cannot change its board or key.');
      }
    }
    $violations = $this->validate();
    if ($violations->count() > 0) {
      throw new EntityStorageException((string) $violations);
    }
  }

}
