<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_agent\AgentAccessDeniedException;
use Drupal\ab_board\BoardRepository;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskRepository;
use Drupal\ab_communication\MessageRejectedException;
use Drupal\ab_communication\MessageService;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Appends a human comment to a board or task conversation.
 *
 * The author is the authenticated account. There is no direct-message
 * field and no author id.
 */
final class MessageComposeForm extends FormBase {

  public function __construct(
    private readonly MessageService $messages,
    private readonly HumanActorContextFactory $actors,
    private readonly BoardRepository $boards,
    private readonly TaskRepository $tasks,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_message_compose';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $scope = '', string $target = '', string $return_uuid = '') {
    $form['#id'] = 'ab-ui-message-' . $scope . '-' . $target;
    $form['scope'] = [
      '#type' => 'hidden',
      '#value' => $scope,
    ];
    $form['target'] = [
      '#type' => 'hidden',
      '#value' => $target,
    ];
    $form['return_uuid'] = [
      '#type' => 'hidden',
      '#value' => $return_uuid,
    ];
    $form['reply_to'] = [
      '#type' => 'select',
      '#title' => $this->t('Em resposta a'),
      '#options' => $this->replyOptions($scope, $target),
      '#empty_option' => $this->t('Nenhuma'),
      '#empty_value' => '',
    ];
    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Mensagem'),
      '#maxlength' => MessageService::BODY_LIMIT,
    ];
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Enviar'),
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
      $form_state->setErrorByName('body', $this->t('Informe uma mensagem.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $scope = (string) $form_state->getValue('scope');
    $target = (string) $form_state->getValue('target');
    $workspace = $this->workspaceOf($scope, $target);
    if (!$workspace instanceof Workspace) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return;
    }
    $reply = (string) $form_state->getValue('reply_to');
    try {
      $this->messages->post(
        $this->actors->create($workspace),
        $scope,
        $target,
        'comment',
        (string) $form_state->getValue('body'),
        $reply === '' ? NULL : $reply,
      );
    }
    catch (MessageRejectedException) {
      $this->messenger()->addError($this->t('Essa resposta não está nesta conversa.'));
      return;
    }
    catch (\InvalidArgumentException) {
      $this->messenger()->addError($this->t('A mensagem passou do limite.'));
      return;
    }
    catch (ScopedResourceNotFoundException) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return;
    }
    catch (ScopedOperationDeniedException | AgentAccessDeniedException) {
      $this->messenger()->addError($this->t('Operação não permitida.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Mensagem enviada.'));
    $return = (string) $form_state->getValue('return_uuid');
    if ($return !== '') {
      $form_state->setRedirect('ab_ui.conversation', ['uuid' => $return]);
      return;
    }
    if ($scope === 'task') {
      $form_state->setRedirect('ab_ui.task_conversation', ['uuid' => $target]);
      return;
    }
    $form_state->setRedirect('ab_ui.board_conversation', ['uuid' => $target]);
  }

  /**
   * Builds reply choices from the messages already stored.
   *
   * @return array<string, string>
   *   Option labels keyed by message uuid.
   */
  private function replyOptions(string $scope, string $target): array {
    $workspace = $this->workspaceOf($scope, $target);
    if (!$workspace instanceof Workspace) {
      return [];
    }
    try {
      $thread = $this->messages->findThreadUuid($this->actors->create($workspace), $scope, $target);
      if ($thread === NULL) {
        return [];
      }
      $page = $this->messages->read(
        $this->actors->create($workspace),
        $thread,
        NULL,
        MessageService::PAGE_DEFAULT,
      );
    }
    catch (ScopedResourceNotFoundException | ScopedOperationDeniedException | \InvalidArgumentException) {
      return [];
    }
    $options = [];
    foreach ($page->messages as $message) {
      $flat = trim(preg_replace('/\s+/', ' ', $message->body) ?? '');
      if (mb_strlen($flat) > 40) {
        $flat = mb_substr($flat, 0, 40);
      }
      $options[$message->uuid] = $flat === '' ? $message->uuid : $flat;
    }
    return $options;
  }

  /**
   * Loads the workspace that owns the board or task.
   */
  private function workspaceOf(string $scope, string $target): ?Workspace {
    if ($scope === 'board') {
      $board = $this->boards->loadAccessibleByUuid($target);
      $id = $board instanceof Board ? $board->get('workspace')->getString() : '';
    }
    elseif ($scope === 'task') {
      $task = $this->tasks->loadAccessibleByUuid($target);
      $id = $task instanceof Task ? $task->get('workspace')->getString() : '';
    }
    else {
      return NULL;
    }
    if ($id === '') {
      return NULL;
    }
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($id);
    return $workspace instanceof Workspace ? $workspace : NULL;
  }

}
