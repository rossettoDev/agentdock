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
 * One attempt by an agent to carry a task.
 *
 * Claim writes the row. Heartbeat, submit and recovery come later.
 */
#[ContentEntityType(
  id: 'ab_execution',
  label: new TranslatableMarkup('Execution'),
  label_collection: new TranslatableMarkup('Executions'),
  label_singular: new TranslatableMarkup('execution'),
  label_plural: new TranslatableMarkup('executions'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'workspace' => 'workspace',
  ],
  handlers: [
    'access' => 'Drupal\ab_agent\Authorization\ExecutionAccessControlHandler',
  ],
  base_table: 'ab_execution',
)]
class Execution extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['workspace'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Workspace'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'ab_workspace');

    $fields['task'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Task'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'ab_task');

    $fields['agent'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Agent'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'ab_agent');

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('running')
      ->setSetting('allowed_values', [
        'running' => 'Running',
        'waiting_input' => 'Waiting for input',
        'submitted' => 'Submitted',
        'failed' => 'Failed',
        'interrupted' => 'Interrupted',
        'cancelled' => 'Cancelled',
      ]);

    $fields['lease_expires_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Lease expiry'))
      ->setRequired(TRUE);

    $fields['heartbeat_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Heartbeat'));

    $fields['started_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Started'))
      ->setRequired(TRUE);

    $fields['ended_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Ended'));

    $fields['attempt'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Attempt'))
      ->setRequired(TRUE)
      ->setDefaultValue(1)
      ->setSetting('unsigned', TRUE);

    $fields['result_summary'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Result summary'))
      ->setDefaultValue('');

    $fields['failure_code'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Failure code'))
      ->setDefaultValue('')
      ->setSetting('max_length', 64);

    $fields['version'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Version'))
      ->setRequired(TRUE)
      ->setDefaultValue(1)
      ->setSetting('unsigned', TRUE);

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
    if (!$original instanceof self) {
      return;
    }
    $moved = $original->get('workspace')->getString() !== $this->get('workspace')->getString()
      || $original->get('task')->getString() !== $this->get('task')->getString()
      || $original->get('agent')->getString() !== $this->get('agent')->getString();
    if ($moved) {
      throw new EntityStorageException('An execution cannot move to another task.');
    }
  }

}
