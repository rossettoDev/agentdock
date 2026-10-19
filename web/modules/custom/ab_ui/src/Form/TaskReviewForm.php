<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_agent\TaskReview;
use Drupal\ab_agent\TaskReviewRejectedException;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskRepository;
use Drupal\ab_board\TaskVersionConflictException;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Human approval, rejection and cancellation for one delivery.
 *
 * The version is the one rendered for this editor. The agent does not
 * get these buttons. Attachments and checklists stay out.
 */
final class TaskReviewForm extends FormBase {

  public function __construct(
    private readonly TaskReview $reviews,
    private readonly TaskRepository $repository,
    private readonly MembershipScope $scope,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_task_review';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $task_uuid = '') {
    $task = $this->repository->loadAccessibleByUuid($task_uuid);
    if (!$task instanceof Task) {
      return $form;
    }
    $status = $task->get('status')->getString();
    $workspace = $this->workspaceOf($task);
    $can_review = $workspace instanceof Workspace && $this->scope->allows(
      $this->currentUser(),
      $workspace,
      WorkspacePermission::REVIEW,
    );
    $can_write = $workspace instanceof Workspace && $this->scope->allows(
      $this->currentUser(),
      $workspace,
      WorkspacePermission::WRITE,
    );
    $form['#id'] = 'ab-ui-task-review-' . $task_uuid;
    $form['task_uuid'] = [
      '#type' => 'hidden',
      '#value' => $task_uuid,
    ];
    $form['version'] = [
      '#type' => 'hidden',
      '#default_value' => $task->get('version')->getString(),
    ];
    $form['actions'] = ['#type' => 'actions'];
    if ($status === 'in_review' && $can_review) {
      $form['actions']['approve'] = [
        '#type' => 'submit',
        '#value' => $this->t('Aprovar'),
        '#name' => 'review_approve',
        '#review_op' => 'approve',
        '#attributes' => ['class' => ['ab-button']],
      ];
      $form['reason'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Motivo'),
        '#maxlength' => 500,
      ];
      $form['actions']['reject'] = [
        '#type' => 'submit',
        '#value' => $this->t('Rejeitar'),
        '#name' => 'review_reject',
        '#review_op' => 'reject',
        '#attributes' => ['class' => ['ab-button']],
      ];
    }
    if (in_array($status, ['in_progress', 'in_review'], TRUE) && $can_write) {
      $form['actions']['cancel'] = [
        '#type' => 'submit',
        '#value' => $this->t('Cancelar execução'),
        '#name' => 'review_cancel',
        '#review_op' => 'cancel',
        '#attributes' => ['class' => ['ab-button']],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($this->operation($form_state) !== 'reject') {
      return;
    }
    $reason = trim((string) $form_state->getValue('reason'));
    if ($reason === '') {
      $form_state->setErrorByName('reason', $this->t('Informe o motivo.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uuid = (string) $form_state->getValue('task_uuid');
    $version = (int) $form_state->getValue('version');
    $operation = $this->operation($form_state);
    try {
      if ($operation === 'approve') {
        $this->reviews->approve($uuid, $version);
        $this->messenger()->addStatus($this->t('Tarefa aprovada.'));
      }
      elseif ($operation === 'reject') {
        $this->reviews->reject($uuid, $version, (string) $form_state->getValue('reason'));
        $this->messenger()->addStatus($this->t('Tarefa rejeitada.'));
      }
      elseif ($operation === 'cancel') {
        $this->reviews->cancel($uuid, $version);
        $this->messenger()->addStatus($this->t('Execução cancelada.'));
      }
    }
    catch (TaskVersionConflictException) {
      $this->messenger()->addError($this->t('A tarefa foi alterada. Recarregue e tente de novo.'));
      return;
    }
    catch (TaskReviewRejectedException) {
      $this->messenger()->addError($this->t('Essa revisão não está disponível.'));
      return;
    }
    catch (\InvalidArgumentException) {
      $this->messenger()->addError($this->t('Informe o motivo.'));
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
    $form_state->setRedirect('ab_ui.task_view', ['uuid' => $uuid]);
  }

  /**
   * Returns the review operation chosen by the pressed button.
   */
  private function operation(FormStateInterface $form_state): string {
    $trigger = $form_state->getTriggeringElement();
    if (is_array($trigger) && isset($trigger['#review_op'])) {
      return (string) $trigger['#review_op'];
    }
    return '';
  }

  /**
   * Loads the workspace stored on the task.
   */
  private function workspaceOf(Task $task): ?Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($task->get('workspace')->getString());
    return $workspace instanceof Workspace ? $workspace : NULL;
  }

}
