<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_board\Entity\Task;
use Drupal\ab_communication\InputRequestRejectedException;
use Drupal\ab_communication\InputRequestService;
use Drupal\ab_communication\MessageService;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Resolves one open question with the authenticated human.
 *
 * The form does not offer an execution status. The service decides
 * whether the live lease returns to running.
 */
final class InputAnswerForm extends FormBase {

  public function __construct(
    private readonly InputRequestService $requests,
    private readonly HumanActorContextFactory $actors,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_input_answer';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $request_uuid = '') {
    $form['#id'] = 'ab-ui-answer-' . $request_uuid;
    $form['request'] = [
      '#type' => 'hidden',
      '#value' => $request_uuid,
    ];
    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Resposta'),
      '#maxlength' => MessageService::BODY_LIMIT,
    ];
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Responder'),
        '#attributes' => ['class' => ['ab-button']],
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $body = trim((string) $form_state->getValue('body'));
    if ($body === '') {
      $form_state->setErrorByName('body', $this->t('Informe uma resposta.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uuid = (string) $form_state->getValue('request');
    $workspace = $this->workspaceOf($uuid);
    if (!$workspace instanceof Workspace) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return;
    }
    $task = '';
    try {
      $this->requests->answer($this->actors->create($workspace), $uuid, (string) $form_state->getValue('body'));
      $task = $this->taskUuid($uuid);
    }
    catch (InputRequestRejectedException) {
      $this->messenger()->addError($this->t('Essa dúvida já foi respondida.'));
      return;
    }
    catch (\InvalidArgumentException) {
      $this->messenger()->addError($this->t('A resposta passou do limite.'));
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
    $this->messenger()->addStatus($this->t('Resposta enviada.'));
    if ($task !== '') {
      $form_state->setRedirect('ab_ui.task_view', ['uuid' => $task]);
    }
  }

  /**
   * Loads the workspace that owns the request.
   */
  private function workspaceOf(string $uuid): ?Workspace {
    $workspaceId = $this->databaseId($uuid, 'workspace');
    if ($workspaceId === '') {
      return NULL;
    }
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($workspaceId);
    return $workspace instanceof Workspace ? $workspace : NULL;
  }

  /**
   * Returns the task uuid stored for the request.
   */
  private function taskUuid(string $uuid): string {
    $taskId = $this->databaseId($uuid, 'task_id');
    if ($taskId === '') {
      return '';
    }
    $task = $this->entityTypeManager->getStorage('ab_task')->load($taskId);
    return $task instanceof Task ? (string) $task->uuid() : '';
  }

  /**
   * Reads one column of the request row.
   */
  private function databaseId(string $uuid, string $field): string {
    $value = $this->database->select('ab_input_request', 'r')
      ->fields('r', [$field])
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchField();
    return is_numeric($value) ? (string) $value : '';
  }

}
