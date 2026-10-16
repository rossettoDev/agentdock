<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskVersionConflictException;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\user\UserInterface;

/**
 * Edits the whitelist fields of one readable task.
 */
final class TaskEditForm extends TaskCreateForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_task_edit';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $uuid = '') {
    $task = $this->visibleTask($uuid);
    $workspace = $this->workspaceOf($task);
    $this->assertWriter($workspace);
    $this->cache($workspace)->applyTo($form);
    $form['task_uuid'] = [
      '#type' => 'hidden',
      '#value' => $task->uuid(),
    ];
    $form['version'] = [
      '#type' => 'hidden',
      '#default_value' => (string) $task->get('version')->getString(),
    ];
    $this->fields($form, [
      'title' => (string) $task->label(),
      'description' => $task->get('description')->getString(),
      'acceptance_criteria' => $task->get('acceptance_criteria')->getString(),
      'priority' => $task->get('priority')->getString(),
      'human_owner' => $this->ownerName($task),
      'due_at' => $this->dueValue($workspace, $task->get('due_at')->getString()),
    ]);
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Salvar'),
      '#attributes' => ['class' => ['ab-button']],
    ];
    return $form;
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
    try {
      $workspace = $this->workspaceOf($task);
      $saved = $this->manager->update($task, [
        'title' => (string) $form_state->getValue('title'),
        'description' => (string) $form_state->getValue('description'),
        'acceptance_criteria' => (string) $form_state->getValue('acceptance_criteria'),
        'priority' => (string) $form_state->getValue('priority'),
        'human_owner' => $this->ownerId((string) $form_state->getValue('human_owner')),
        'due_at' => $this->dueTimestamp($workspace, (string) $form_state->getValue('due_at')),
      ], (int) $form_state->getValue('version'));
    }
    catch (TaskVersionConflictException) {
      $this->messenger()->addError($this->t('A tarefa foi alterada. Recarregue e tente de novo.'));
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
    catch (\InvalidArgumentException $exception) {
      $this->flag($form_state, $exception);
      return;
    }
    $this->messenger()->addStatus($this->t('Tarefa atualizada.'));
    $form_state->setRedirect('ab_ui.task_view', ['uuid' => $saved->uuid()]);
  }

  /**
   * Loads a task the current user can read, or answers 404.
   */
  private function visibleTask(string $uuid): Task {
    $task = $this->tasks->loadAccessibleByUuid($uuid);
    if ($task instanceof Task) {
      return $task;
    }
    throw new CacheableNotFoundHttpException($this->cache(NULL));
  }

  /**
   * Loads the workspace stored on the task.
   */
  private function workspaceOf(Task $task): Workspace {
    $storage = $this->entityTypeManager->getStorage('ab_workspace');
    $workspace = $storage->load($task->get('workspace')->getString());
    if (!$workspace instanceof Workspace) {
      throw new CacheableNotFoundHttpException($this->cache(NULL));
    }
    return $workspace;
  }

  /**
   * Returns the responsible account name.
   */
  private function ownerName(Task $task): string {
    $id = $task->get('human_owner')->getString();
    if ($id === '') {
      return '';
    }
    $user = $this->entityTypeManager->getStorage('user')->load($id);
    return $user instanceof UserInterface ? $user->getAccountName() : '';
  }

  /**
   * Formats a stored timestamp as a date input value.
   */
  private function dueValue(Workspace $workspace, string $raw): string {
    if ($raw === '') {
      return '';
    }
    try {
      $zone = new \DateTimeZone($workspace->get('timezone')->getString());
    }
    catch (\Exception) {
      return '';
    }
    $date = new \DateTimeImmutable('@' . $raw);
    return $date->setTimezone($zone)->format('Y-m-d');
  }

}
