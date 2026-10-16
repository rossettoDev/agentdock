<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskManager;
use Drupal\ab_board\TaskReorderRejectedException;
use Drupal\ab_board\TaskRepository;
use Drupal\ab_board\TaskVersionConflictException;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Moves one task up or down inside its column.
 *
 * The hidden version is the one rendered for this editor. Dropping a
 * card on the same column submits this form with the card that should
 * follow it.
 */
final class TaskReorderForm extends FormBase {

  public function __construct(
    private readonly TaskManager $tasks,
    private readonly TaskRepository $repository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_task_reorder';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    string $task_uuid = '',
    string $before_up = '',
    string $before_down = '',
    string $can_down = '0',
    string $return_to = '',
  ) {
    $task = $this->repository->loadAccessibleByUuid($task_uuid);
    if ($task === NULL || ($before_up === '' && $can_down !== '1')) {
      return $form;
    }
    $form['#id'] = 'ab-ui-task-reorder-' . $task_uuid;
    $form['task_uuid'] = [
      '#type' => 'hidden',
      '#value' => $task_uuid,
    ];
    $form['version'] = [
      '#type' => 'hidden',
      '#default_value' => $task->get('version')->getString(),
    ];
    $form['before_uuid'] = [
      '#type' => 'hidden',
      '#default_value' => '',
    ];
    if ($return_to === 'today') {
      $form['return_to'] = [
        '#type' => 'hidden',
        '#value' => 'today',
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    if ($before_up !== '') {
      $form['actions']['up'] = $this->step((string) $this->t('Subir'), 'up', $before_up);
    }
    if ($can_down === '1') {
      $form['actions']['down'] = $this->step((string) $this->t('Descer'), 'down', $before_down);
    }
    $form['actions']['reorder'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reordenar'),
      '#name' => 'reorder',
      '#attributes' => [
        'class' => ['visually-hidden'],
        'tabindex' => '-1',
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uuid = (string) $form_state->getValue('task_uuid');
    $task = $this->repository->loadAccessibleByUuid($uuid);
    if ($task === NULL) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return;
    }
    $before = $this->beforeUuid($form_state);
    $version = (int) $form_state->getValue('version');
    try {
      $task = $this->tasks->reorder($task, $version, $before);
    }
    catch (TaskVersionConflictException) {
      $this->messenger()->addError($this->t('A tarefa foi alterada. Recarregue e tente de novo.'));
      return;
    }
    catch (TaskReorderRejectedException) {
      $this->messenger()->addError($this->t('A ordem fica na mesma coluna.'));
      return;
    }
    catch (ScopedResourceNotFoundException) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return;
    }
    catch (ScopedOperationDeniedException) {
      $this->messenger()->addError($this->t('Operação não permitida.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Ordem atualizada.'));
    $this->redirectToBoard($form_state, $task);
  }

  /**
   * Builds one keyboard step.
   *
   * @param string $label
   *   Button label.
   * @param string $name
   *   Button name.
   * @param string $before
   *   UUID of the task that should follow this one. Empty moves it last.
   *
   * @return array<string, mixed>
   *   A submit button.
   */
  private function step(string $label, string $name, string $before): array {
    return [
      '#type' => 'submit',
      '#value' => $label,
      '#name' => $name,
      '#before_uuid' => $before,
      '#attributes' => ['class' => ['ab-button']],
    ];
  }

  /**
   * Reads the card that should follow this one.
   */
  private function beforeUuid(FormStateInterface $form_state): ?string {
    $trigger = $form_state->getTriggeringElement();
    $before = $form_state->getValue('before_uuid');
    if (is_array($trigger) && array_key_exists('#before_uuid', $trigger)) {
      $before = $trigger['#before_uuid'];
    }
    return is_string($before) && $before !== '' ? $before : NULL;
  }

  /**
   * Sends the editor back to the board that owns the task.
   */
  private function redirectToBoard(FormStateInterface $form_state, Task $task): void {
    $board_id = $task->get('board')->getString();
    $board = $this->entityTypeManager->getStorage('ab_board')->load($board_id);
    if (!$board instanceof Board) {
      return;
    }
    $options = [];
    if ($form_state->getValue('return_to') === 'today') {
      $options['query'] = ['today' => '1'];
    }
    $form_state->setRedirect('ab_ui.board_tasks', [
      'uuid' => $board->uuid(),
    ], $options);
  }

}
