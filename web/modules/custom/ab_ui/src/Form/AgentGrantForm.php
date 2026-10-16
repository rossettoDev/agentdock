<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentGrantRejectedException;
use Drupal\ab_agent\AgentRepository;
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_board\BoardRepository;
use Drupal\ab_board\Entity\Board;
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
 * Adds or removes a board grant on one agent.
 *
 * The board list is the agent's workspace. A board from another
 * workspace is not offered and is rejected if it is posted anyway.
 */
final class AgentGrantForm extends FormBase {

  public function __construct(
    private readonly AgentGrantManager $grants,
    private readonly AgentRepository $agents,
    private readonly BoardRepository $boards,
    private readonly MembershipScope $scope,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_agent_grant';
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
    $this->assertManager($workspace);
    $options = [];
    foreach ($this->boards->accessibleBoards($workspace) as $board) {
      $options[(string) $board->uuid()] = $board->label();
    }
    $form['#id'] = 'ab-ui-agent-grant-' . $agent_uuid;
    $form['agent_uuid'] = [
      '#type' => 'hidden',
      '#value' => $agent_uuid,
    ];
    $form['board'] = [
      '#type' => 'select',
      '#title' => $this->t('Board'),
      '#options' => $options,
      '#empty_option' => $this->t('Escolha um board'),
      '#required' => FALSE,
    ];
    $form['permissions'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Permissões'),
      '#options' => [
        WorkspacePermission::READ => $this->t('Ler'),
        WorkspacePermission::WRITE => $this->t('Escrever'),
        WorkspacePermission::REVIEW => $this->t('Revisar'),
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['grant'] = [
      '#type' => 'submit',
      '#value' => $this->t('Adicionar grant'),
      '#submit' => ['::grantSubmit'],
    ];
    $form['actions']['revoke'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remover'),
      '#submit' => ['::revokeSubmit'],
      '#limit_validation_errors' => [['board'], ['agent_uuid']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ((string) $form_state->getValue('board') === '') {
      $form_state->setErrorByName('board', $this->t('Escolha um board.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {}

  /**
   * Stores the selected permissions.
   */
  public function grantSubmit(array &$form, FormStateInterface $form_state): void {
    [$agent, $board] = $this->pair($form_state);
    if (!$agent instanceof Agent || !$board instanceof Board) {
      return;
    }
    $permissions = array_values(array_filter((array) $form_state->getValue('permissions')));
    try {
      $this->grants->grant($agent, $board, $permissions);
    }
    catch (AgentGrantRejectedException $exception) {
      $this->messenger()->addError($this->rejection($exception));
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
    $this->messenger()->addStatus($this->t('Grant adicionado.'));
    $form_state->setRedirect('ab_ui.agent_view', ['uuid' => $agent->uuid()]);
  }

  /**
   * Removes the grant for the selected board.
   */
  public function revokeSubmit(array &$form, FormStateInterface $form_state): void {
    [$agent, $board] = $this->pair($form_state);
    if (!$agent instanceof Agent || !$board instanceof Board) {
      return;
    }
    try {
      $this->grants->revoke($agent, $board);
    }
    catch (AgentGrantRejectedException $exception) {
      $this->messenger()->addError($this->rejection($exception));
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
    $this->messenger()->addStatus($this->t('Grant removido.'));
    $form_state->setRedirect('ab_ui.agent_view', ['uuid' => $agent->uuid()]);
  }

  /**
   * Loads the posted agent and a board of the same workspace.
   *
   * @return array{0: ?\Drupal\ab_agent\Entity\Agent, 1: ?\Drupal\ab_board\Entity\Board}
   *   The pair, with NULL when either side is missing.
   */
  private function pair(FormStateInterface $form_state): array {
    $agent = $this->agents->loadAccessibleByUuid((string) $form_state->getValue('agent_uuid'));
    if (!$agent instanceof Agent) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return [NULL, NULL];
    }
    $board = $this->boards->loadAccessibleByUuid((string) $form_state->getValue('board'));
    if (!$board instanceof Board) {
      $this->messenger()->addError($this->t('Escolha um board.'));
      return [$agent, NULL];
    }
    if ($board->get('workspace')->getString() !== $agent->get('workspace')->getString()) {
      $this->messenger()->addError($this->t('O board não é deste workspace.'));
      return [$agent, NULL];
    }
    return [$agent, $board];
  }

  /**
   * Maps a grant rejection to a screen message.
   */
  private function rejection(AgentGrantRejectedException $exception): string {
    return match ($exception->getMessage()) {
      AgentGrantRejectedException::BOARD => (string) $this->t('O board não é deste workspace.'),
      AgentGrantRejectedException::MISSING => (string) $this->t('Esse grant não existe.'),
      default => (string) $this->t('Permissão inválida.'),
    };
  }

  /**
   * Refuses readers and callers outside the workspace.
   */
  private function assertManager(Workspace $workspace): void {
    $account = $this->currentUser();
    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'ab_memberships'])
      ->addCacheTags(['ab_membership_list:' . $account->id()]);
    if (!$this->scope->allows($account, $workspace, WorkspacePermission::READ)) {
      throw new CacheableNotFoundHttpException($cache);
    }
    if (!$this->scope->allows($account, $workspace, WorkspacePermission::MANAGE_AGENTS)) {
      throw new CacheableAccessDeniedHttpException($cache);
    }
  }

}
