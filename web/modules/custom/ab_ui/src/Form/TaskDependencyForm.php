<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskDependencyManager;
use Drupal\ab_board\TaskDependencyRejectedException;
use Drupal\ab_board\TaskRepository;
use Drupal\ab_board\TaskVersionConflictException;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Adds and removes predecessors without leaving the task's board.
 */
final class TaskDependencyForm extends FormBase {

  public function __construct(
    private readonly TaskDependencyManager $dependencies,
    private readonly TaskRepository $tasks,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_task_dependency';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $task_uuid = '') {
    $task = $this->tasks->loadAccessibleByUuid($task_uuid);
    if (!$task instanceof Task) {
      $form['#access'] = FALSE;
      return $form;
    }
    $form['task_uuid'] = [
      '#type' => 'hidden',
      '#value' => $task_uuid,
    ];
    $form['version'] = [
      '#type' => 'hidden',
      '#value' => (int) $task->get('version')->getString(),
    ];
    $options = $this->options($task);
    $predecessors = $this->dependencies->predecessors($task);
    if ($options === [] && $predecessors === []) {
      $form['#access'] = FALSE;
      return $form;
    }
    if ($options !== []) {
      $form['dependency'] = [
        '#type' => 'select',
        '#title' => $this->t('Dependência'),
        '#options' => $options,
        '#empty_option' => $this->t('- Selecione -'),
      ];
      $form['add'] = [
        '#type' => 'submit',
        '#value' => $this->t('Adicionar dependência'),
        '#name' => 'add_dependency',
        '#attributes' => ['class' => ['ab-button']],
      ];
    }
    foreach ($predecessors as $predecessor) {
      $uuid = (string) $predecessor->uuid();
      $form['remove_' . $uuid] = [
        '#type' => 'submit',
        '#value' => $this->t('Remover @title', ['@title' => (string) $predecessor->label()]),
        '#name' => 'remove_' . $uuid,
        '#dependency_uuid' => $uuid,
        '#limit_validation_errors' => [
          ['task_uuid'],
          ['version'],
        ],
        '#attributes' => ['class' => ['ab-button', 'ab-button--secondary']],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    if (is_array($trigger) && isset($trigger['#dependency_uuid'])) {
      return;
    }
    $selected = (string) $form_state->getValue('dependency');
    if ($selected === '') {
      $form_state->setErrorByName('dependency', $this->t('Escolha uma tarefa.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uuid = (string) $form_state->getValue('task_uuid');
    $task = $this->tasks->loadAccessibleByUuid($uuid);
    if (!$task instanceof Task) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return;
    }
    $trigger = $form_state->getTriggeringElement();
    $removing = is_array($trigger) && isset($trigger['#dependency_uuid']);
    $dependency_uuid = $removing
      ? (string) $trigger['#dependency_uuid']
      : (string) $form_state->getValue('dependency');
    $dependency = $this->tasks->loadAccessibleByUuid($dependency_uuid);
    if (!$dependency instanceof Task) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return;
    }
    $version = (int) $form_state->getValue('version');
    try {
      if ($removing) {
        $task = $this->dependencies->remove($task, $dependency, $version);
      }
      else {
        $task = $this->dependencies->add($task, $dependency, $version);
      }
    }
    catch (TaskVersionConflictException) {
      $this->messenger()->addError($this->t('A tarefa foi alterada. Recarregue e tente de novo.'));
      return;
    }
    catch (TaskDependencyRejectedException $exception) {
      $this->messenger()->addError($this->rejectionMessage($exception->getMessage()));
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
    $message = $removing
      ? $this->t('Dependência removida.')
      : $this->t('Dependência adicionada.');
    $this->messenger()->addStatus($message);
    $form_state->setRedirect('ab_ui.task_view', ['uuid' => $task->uuid()]);
  }

  /**
   * Returns other tasks of the same board, keyed by UUID.
   *
   * @return array<string, string>
   *   Select options.
   */
  private function options(Task $task): array {
    $board = $this->entityTypeManager->getStorage('ab_board')->load($task->get('board')->getString());
    if (!$board instanceof Board) {
      return [];
    }
    $options = [];
    foreach ($this->tasks->accessibleTasks($board) as $candidate) {
      if ((string) $candidate->id() === (string) $task->id()) {
        continue;
      }
      $options[(string) $candidate->uuid()] = (string) $candidate->label();
    }
    return $options;
  }

  /**
   * Maps a rejection code to a Portuguese message.
   */
  private function rejectionMessage(string $code): string {
    return match ($code) {
      TaskDependencyRejectedException::SELF => (string) $this->t('Uma tarefa não depende dela mesma.'),
      TaskDependencyRejectedException::CYCLE => (string) $this->t('Essa dependência forma um ciclo.'),
      TaskDependencyRejectedException::BOARD => (string) $this->t('Dependências ficam no mesmo board.'),
      TaskDependencyRejectedException::DUPLICATE => (string) $this->t('Essa dependência já existe.'),
      default => (string) $this->t('O registro não foi encontrado.'),
    };
  }

}
