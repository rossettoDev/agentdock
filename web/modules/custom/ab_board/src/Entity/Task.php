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
 * A task whose workspace is taken from its board.
 *
 * Assigned agent and active execution stay nullable integers until the agent
 * and execution entities exist. The conversion is documented in US-09/T01.
 */
#[ContentEntityType(
  id: 'ab_task',
  label: new TranslatableMarkup('Task'),
  label_collection: new TranslatableMarkup('Tasks'),
  label_singular: new TranslatableMarkup('task'),
  label_plural: new TranslatableMarkup('tasks'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'title',
    'workspace' => 'workspace',
    'board' => 'board',
  ],
  handlers: [
    'storage_schema' => 'Drupal\ab_board\TaskStorageSchema',
    'access' => 'Drupal\ab_board\Authorization\TaskAccessControlHandler',
  ],
  base_table: 'ab_task',
  constraints: [
    'AbSameWorkspace' => [],
  ],
)]
class Task extends ContentEntityBase {

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

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDefaultValue('');

    $fields['acceptance_criteria'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Acceptance criteria'))
      ->setDefaultValue('');

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('backlog')
      ->setSetting('allowed_values', [
        'backlog' => 'Backlog',
        'ready' => 'Ready',
        'in_progress' => 'In progress',
        'in_review' => 'In review',
        'done' => 'Done',
        'cancelled' => 'Cancelled',
      ]);

    $fields['priority'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Priority'))
      ->setRequired(TRUE)
      ->setDefaultValue('normal')
      ->setSetting('allowed_values', [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
      ]);

    $fields['human_owner'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Human owner'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'ab_membership');

    $fields['assigned_agent'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Assigned agent'))
      ->setDescription(new TranslatableMarkup(
        'Numeric id of the assigned agent. The column stays an integer.',
      ))
      ->setSetting('unsigned', TRUE);

    $fields['due_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Due'));

    $fields['position'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Position'))
      ->setRequired(TRUE)
      ->setDefaultValue(0)
      ->setSetting('unsigned', TRUE);

    $fields['version'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Version'))
      ->setRequired(TRUE)
      ->setDefaultValue(1)
      ->setSetting('unsigned', TRUE);

    $fields['active_execution'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Active execution'))
      ->setDescription(new TranslatableMarkup(
        'Numeric id of the active execution. The column stays an integer.',
      ))
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
    $this->keepWorkspaceFromBoard();
    $violations = $this->validate();
    if ($violations->count() > 0) {
      throw new EntityStorageException((string) $violations);
    }
  }

  /**
   * Copies the workspace from the board and rejects a later move.
   */
  private function keepWorkspaceFromBoard(): void {
    $board_id = $this->get('board')->getString();
    if ($board_id === '') {
      return;
    }
    $board = $this->entityTypeManager()->getStorage('ab_board')->load($board_id);
    if (!$board instanceof Board) {
      throw new EntityStorageException('The board does not exist.');
    }
    $board_workspace = $board->get('workspace')->getString();
    $original = $this->getOriginal();
    if ($original instanceof self) {
      $this->rejectMove($original, $board_id, $board_workspace);
      return;
    }
    $requested = $this->get('workspace')->getString();
    $mismatch = $requested !== '' && $requested !== $board_workspace;
    if ($mismatch) {
      throw new EntityStorageException('A task workspace must match its board.');
    }
    $this->set('workspace', $board_workspace);
  }

  /**
   * Rejects a stored task that points at another board or workspace.
   */
  private function rejectMove(self $original, string $board_id, string $board_workspace): void {
    if ($original->get('board')->getString() !== $board_id) {
      throw new EntityStorageException('A task cannot move to another board.');
    }
    $workspace = $this->get('workspace')->getString();
    $changed = $original->get('workspace')->getString() !== $workspace;
    if ($changed || $workspace !== $board_workspace) {
      throw new EntityStorageException('A task cannot move to another workspace.');
    }
  }

}
