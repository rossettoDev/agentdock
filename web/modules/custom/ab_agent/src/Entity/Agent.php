<?php

declare(strict_types=1);

namespace Drupal\ab_agent\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * An agent that belongs to one workspace and does not start work while off.
 */
#[ContentEntityType(
  id: 'ab_agent',
  label: new TranslatableMarkup('Agent'),
  label_collection: new TranslatableMarkup('Agents'),
  label_singular: new TranslatableMarkup('agent'),
  label_plural: new TranslatableMarkup('agents'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'workspace' => 'workspace',
  ],
  handlers: [
    'access' => 'Drupal\ab_agent\Authorization\AgentAccessControlHandler',
  ],
  base_table: 'ab_agent',
  constraints: [
    'AbSameWorkspace' => [],
  ],
)]
class Agent extends ContentEntityBase {

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

    $fields['capabilities'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Capabilities'))
      ->setDefaultValue('')
      ->setSetting('max_length', 255);

    $fields['enabled'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Enabled'))
      ->setDefaultValue(TRUE);

    $fields['concurrency_limit'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Concurrency limit'))
      ->setRequired(TRUE)
      ->setDefaultValue(1)
      ->setSetting('unsigned', TRUE)
      ->addPropertyConstraints('value', [
        'Range' => ['min' => 1],
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
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $original = $this->getOriginal();
    $workspace_changed = $original instanceof self
      && $original->get('workspace')->getString() !== $this->get('workspace')->getString();
    if ($workspace_changed) {
      throw new EntityStorageException('An agent cannot move to another workspace.');
    }
    $violations = $this->validate();
    if ($violations->count() > 0) {
      throw new EntityStorageException((string) $violations);
    }
  }

}
