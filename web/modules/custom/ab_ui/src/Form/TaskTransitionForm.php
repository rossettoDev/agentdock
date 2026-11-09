<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_board\ColumnRepository;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskManager;
use Drupal\ab_board\TaskRepository;
use Drupal\ab_board\TaskTransitionPolicy;
use Drupal\ab_board\TaskTransitionRejectedException;
use Drupal\ab_board\TaskVersionConflictException;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\BaseFormIdInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Offers only the human status changes for one task.
 *
 * The buttons come from the policy. The in_progress, in_review and done
 * statuses are not choices, so the screen cannot imitate an execution.
 * The hidden version is the one rendered for this editor.
 *
 * Each card gets its own form id. Drupal rebuilds a POST against every
 * form that shares an id, and the task id on this form is not taken from
 * the browser. One shared id would move whichever card was built first.
 */
final class TaskTransitionForm extends FormBase implements BaseFormIdInterface {

  /**
   * Allowed human targets for the task being shown.
   */
  private readonly TaskTransitionPolicy $policy;

  /**
   * Task this instance edits. Empty until the board binds a card.
   */
  private string $taskUuid = '';

  public function __construct(
    private readonly TaskManager $tasks,
    private readonly TaskRepository $repository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ColumnRepository $columns,
  ) {
    $this->policy = new TaskTransitionPolicy();
  }

  /**
   * Binds this instance to one task before the form id is chosen.
   */
  public function setTaskUuid(string $uuid): void {
    $this->taskUuid = $uuid;
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseFormId() {
    return 'ab_ui_task_transition';
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    if ($this->taskUuid === '') {
      return 'ab_ui_task_transition';
    }
    return 'ab_ui_task_transition_' . str_replace('-', '', $this->taskUuid);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    string $task_uuid = '',
    string $return_to = '',
  ) {
    $task = $this->repository->loadAccessibleByUuid($task_uuid);
    if ($task === NULL) {
      return $form;
    }
    $from = $task->get('status')->getString();
    $human = ['backlog', 'ready'];
    $labels = [];
    $board = $this->boardOf($task);
    if ($board instanceof Board) {
      $human = $this->columns->humanKeys($board);
      foreach ($this->columns->ensure($board) as $column) {
        $labels[$column->get('machine')->getString()] = $column->get('name')->getString();
      }
    }
    $form['#id'] = 'ab-ui-task-transition-' . $task_uuid;
    $form['task_uuid'] = [
      '#type' => 'hidden',
      '#value' => $task_uuid,
    ];
    if ($return_to === 'board') {
      $form['return_to'] = [
        '#type' => 'hidden',
        '#value' => 'board',
      ];
    }
    $form['version'] = [
      '#type' => 'hidden',
      '#default_value' => $task->get('version')->getString(),
    ];
    $form['actions'] = ['#type' => 'actions'];
    foreach ($this->policy->targets($from, $human) as $target) {
      $form['actions'][$target] = [
        '#type' => 'submit',
        '#value' => $this->buttonLabel($from, $target, $labels[$target] ?? ''),
        '#name' => 'transition_' . $target,
        '#task_status' => $target,
        '#attributes' => ['class' => ['ab-button']],
      ];
    }
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
    $trigger = $form_state->getTriggeringElement();
    $target = '';
    if (is_array($trigger) && isset($trigger['#task_status'])) {
      $target = (string) $trigger['#task_status'];
    }
    $from = $task->get('status')->getString();
    $version = (int) $form_state->getValue('version');
    try {
      $task = $this->tasks->transition($task, $target, $version);
    }
    catch (TaskVersionConflictException) {
      $this->messenger()->addError($this->t('A tarefa foi alterada. Recarregue e tente de novo.'));
      return;
    }
    catch (TaskTransitionRejectedException) {
      $reserved = in_array($target, TaskTransitionPolicy::RESERVED, TRUE);
      $message = $reserved
        ? $this->t('Essa mudança fica no ciclo de execução.')
        : $this->t('Transição não permitida.');
      $this->messenger()->addError($message);
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
    $this->messenger()->addStatus($this->successMessage($from, $target));
    if ($form_state->getValue('return_to') === 'board') {
      $this->redirectToBoard($form_state, $task);
      return;
    }
    $form_state->setRedirect('ab_ui.task_view', ['uuid' => $task->uuid()]);
  }

  /**
   * Sends the editor back to the board that owns the task.
   */
  private function redirectToBoard(FormStateInterface $form_state, Task $task): void {
    $board_id = $task->get('board')->getString();
    $board = $this->entityTypeManager->getStorage('ab_board')->load($board_id);
    if (!$board instanceof Board) {
      $form_state->setRedirect('ab_ui.task_view', ['uuid' => $task->uuid()]);
      return;
    }
    $options = [];
    if ($task->get('status')->getString() === 'cancelled') {
      $options['query'] = ['cancelled' => '1'];
    }
    $form_state->setRedirect('ab_ui.board_tasks', [
      'uuid' => $board->uuid(),
    ], $options);
  }

  /**
   * Loads the board stored on the task.
   */
  private function boardOf(Task $task): ?Board {
    $board = $this->entityTypeManager->getStorage('ab_board')->load($task->get('board')->getString());
    return $board instanceof Board ? $board : NULL;
  }

  /**
   * Returns the button label for one allowed target.
   */
  private function buttonLabel(string $from, string $target, string $name = ''): string {
    $custom = $name !== '' && !in_array($target, ['backlog', 'ready', 'cancelled'], TRUE);
    if ($custom) {
      return $name;
    }
    if ($target === 'ready') {
      return $from === 'done'
        ? (string) $this->t('Reabrir')
        : (string) $this->t('Liberar');
    }
    if ($target === 'cancelled') {
      return (string) $this->t('Cancelar');
    }
    if ($from === 'cancelled') {
      return (string) $this->t('Reabrir');
    }
    return (string) $this->t('Devolver ao backlog');
  }

  /**
   * Returns the status message for a stored transition.
   */
  private function successMessage(string $from, string $target): string {
    $reopen = ($from === 'cancelled' && $target === 'backlog')
      || ($from === 'done' && $target === 'ready');
    if ($reopen) {
      return (string) $this->t('Tarefa reaberta.');
    }
    return match ($target) {
      'ready' => (string) $this->t('Tarefa liberada.'),
      'cancelled' => (string) $this->t('Tarefa cancelada.'),
      'backlog' => (string) $this->t('Tarefa devolvida ao backlog.'),
      default => (string) $this->t('Tarefa atualizada.'),
    };
  }

}
