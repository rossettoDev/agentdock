<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_agent\AgentRepository;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenRejectedException;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;

/**
 * Issues, revokes and rotates an agent token.
 *
 * The secret is placed in the status message and is not a form value.
 */
final class AgentTokenForm extends FormBase {

  public function __construct(
    private readonly AgentTokenManager $tokens,
    private readonly AgentRepository $agents,
    private readonly MembershipScope $scope,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_agent_token';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $agent_uuid = '') {
    $agent = $this->agents->loadAccessibleByUuid($agent_uuid);
    if (!$agent instanceof Agent) {
      return $form;
    }
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($agent->get('workspace')->getString());
    if (!$workspace instanceof Workspace) {
      return $form;
    }
    $this->assertOwner($workspace);
    $options = [];
    foreach ($this->tokens->list($agent) as $token) {
      $options[$token->publicId] = 'ab_' . $token->publicId;
    }
    $form['#id'] = 'ab-ui-agent-token-' . $agent_uuid;
    $form['agent_uuid'] = [
      '#type' => 'hidden',
      '#value' => $agent_uuid,
    ];
    $form['scopes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Escopos'),
      '#options' => [
        AgentTokenScope::BOARDS_READ => $this->t('Ler boards'),
        AgentTokenScope::TASKS_READ => $this->t('Ler tarefas'),
        AgentTokenScope::TASKS_WRITE => $this->t('Escrever tarefas'),
        AgentTokenScope::TASKS_CLAIM => $this->t('Assumir tarefas'),
        AgentTokenScope::EXECUTIONS_WRITE => $this->t('Escrever execução'),
        AgentTokenScope::MESSAGES_READ => $this->t('Ler mensagens'),
        AgentTokenScope::MESSAGES_WRITE => $this->t('Escrever mensagens'),
        AgentTokenScope::EVENTS_READ => $this->t('Ler eventos'),
      ],
    ];
    $form['token_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Token'),
      '#options' => $options,
      '#empty_option' => $this->t('Escolha um token'),
      '#required' => FALSE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['issue'] = [
      '#type' => 'submit',
      '#name' => 'issue',
      '#value' => $this->t('Criar token'),
      '#submit' => ['::issueSubmit'],
    ];
    $form['actions']['revoke'] = [
      '#type' => 'submit',
      '#name' => 'revoke',
      '#value' => $this->t('Revogar'),
      '#submit' => ['::revokeSubmit'],
      '#limit_validation_errors' => [['token_id'], ['agent_uuid']],
    ];
    $form['actions']['rotate'] = [
      '#type' => 'submit',
      '#name' => 'rotate',
      '#value' => $this->t('Rotacionar'),
      '#submit' => ['::rotateSubmit'],
      '#limit_validation_errors' => [
        ['token_id'],
        ['agent_uuid'],
        ['scopes'],
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $trigger = (string) ($form_state->getTriggeringElement()['#name'] ?? 'issue');
    if ($trigger === 'revoke' || $trigger === 'rotate') {
      if ((string) $form_state->getValue('token_id') === '') {
        $form_state->setErrorByName('token_id', $this->t('Escolha um token.'));
      }
    }
    if ($trigger === 'issue' || $trigger === 'rotate') {
      $scopes = array_values(array_filter((array) $form_state->getValue('scopes')));
      if ($scopes === []) {
        $form_state->setErrorByName('scopes', $this->t('Escolha um escopo.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {}

  /**
   * Issues a token and shows the secret once.
   */
  public function issueSubmit(array &$form, FormStateInterface $form_state): void {
    $agent = $this->agent($form_state);
    if (!$agent instanceof Agent) {
      return;
    }
    $scopes = array_values(array_filter((array) $form_state->getValue('scopes')));
    try {
      $issued = $this->tokens->issue($agent, $scopes);
    }
    catch (AgentTokenRejectedException) {
      $this->messenger()->addError($this->t('Escolha um escopo.'));
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
    $this->showOnce($issued->token);
    $form_state->setRedirect('ab_ui.agent_view', ['uuid' => $agent->uuid()]);
  }

  /**
   * Revokes the selected token.
   */
  public function revokeSubmit(array &$form, FormStateInterface $form_state): void {
    $agent = $this->agent($form_state);
    if (!$agent instanceof Agent) {
      return;
    }
    try {
      $this->tokens->revoke($agent, (string) $form_state->getValue('token_id'));
    }
    catch (ScopedResourceNotFoundException) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return;
    }
    catch (ScopedOperationDeniedException) {
      $this->messenger()->addError($this->t('Operação não permitida.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Token revogado.'));
    $form_state->setRedirect('ab_ui.agent_view', ['uuid' => $agent->uuid()]);
  }

  /**
   * Rotates the selected token and shows the new secret once.
   */
  public function rotateSubmit(array &$form, FormStateInterface $form_state): void {
    $agent = $this->agent($form_state);
    if (!$agent instanceof Agent) {
      return;
    }
    $scopes = array_values(array_filter((array) $form_state->getValue('scopes')));
    try {
      $issued = $this->tokens->rotate($agent, (string) $form_state->getValue('token_id'), $scopes);
    }
    catch (AgentTokenRejectedException) {
      $this->messenger()->addError($this->t('Escolha um escopo.'));
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
    $this->showOnce($issued->token);
    $form_state->setRedirect('ab_ui.agent_view', ['uuid' => $agent->uuid()]);
  }

  /**
   * Loads the posted agent.
   */
  private function agent(FormStateInterface $form_state): ?Agent {
    $agent = $this->agents->loadAccessibleByUuid((string) $form_state->getValue('agent_uuid'));
    if (!$agent instanceof Agent) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return NULL;
    }
    return $agent;
  }

  /**
   * Puts the secret in the status message for this response only.
   */
  private function showOnce(string $token): void {
    $this->messenger()->addStatus($this->t('Copie o token agora. Ele não será mostrado de novo: @token', [
      '@token' => $token,
    ]));
  }

  /**
   * Refuses readers and callers outside the workspace.
   */
  private function assertOwner(Workspace $workspace): void {
    $account = $this->currentUser();
    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'ab_memberships'])
      ->addCacheTags(['ab_membership_list:' . $account->id()]);
    if (!$this->scope->allows($account, $workspace, WorkspacePermission::READ)) {
      throw new CacheableNotFoundHttpException($cache);
    }
    if (!$this->scope->allows($account, $workspace, WorkspacePermission::MANAGE_CREDENTIALS)) {
      throw new CacheableAccessDeniedHttpException($cache);
    }
  }

}
