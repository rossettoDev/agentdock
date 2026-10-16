<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_agent\AgentManager;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Creates an agent in the workspace selected for this session.
 */
class AgentCreateForm extends FormBase {

  public function __construct(
    protected readonly AgentManager $agents,
    protected readonly WorkspaceContext $context,
    protected readonly MembershipScope $scope,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_agent_create';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $workspace = $this->context->workspace();
    $form['#cache'] = [
      'contexts' => ['user', 'ab_memberships', 'ab_workspace'],
      'tags' => ['ab_membership_list:' . $this->currentUser()->id()],
    ];
    if (!$workspace instanceof Workspace) {
      $form['empty'] = ['#markup' => $this->t('Nenhum workspace selecionado.')];
      return $form;
    }
    $this->assertManager($workspace);
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nome'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Descrição'),
      '#required' => FALSE,
    ];
    $form['capabilities'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Capacidades'),
      '#required' => FALSE,
      '#maxlength' => 255,
    ];
    $form['concurrency_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Limite de tarefas ao mesmo tempo'),
      '#default_value' => 1,
    ];
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Habilitado'),
      '#default_value' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Criar agente'),
      '#attributes' => ['class' => ['ab-button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (!$this->validLimit($form_state->getValue('concurrency_limit'))) {
      $form_state->setErrorByName('concurrency_limit', $this->t('Limite inválido.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $workspace = $this->context->workspace();
    if (!$workspace instanceof Workspace) {
      $this->messenger()->addError($this->t('Nenhum workspace selecionado.'));
      return;
    }
    try {
      $agent = $this->agents->create(
        $workspace,
        (string) $form_state->getValue('name'),
        (string) $form_state->getValue('description'),
        (string) $form_state->getValue('capabilities'),
        (int) $form_state->getValue('enabled') === 1,
        (int) $form_state->getValue('concurrency_limit'),
      );
    }
    catch (ScopedResourceNotFoundException) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return;
    }
    catch (ScopedOperationDeniedException) {
      $this->messenger()->addError($this->t('Operação não permitida.'));
      return;
    }
    catch (\InvalidArgumentException) {
      $this->messenger()->addError($this->t('Informe um nome.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Agente criado.'));
    $form_state->setRedirect('ab_ui.agent_view', ['uuid' => $agent->uuid()]);
  }

  /**
   * Reports whether the posted limit is a positive integer.
   */
  protected function validLimit(mixed $value): bool {
    if (is_int($value)) {
      return $value >= 1;
    }
    return is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1;
  }

  /**
   * Refuses readers and callers outside the workspace.
   */
  protected function assertManager(Workspace $workspace): void {
    $account = $this->currentUser();
    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'ab_memberships', 'ab_workspace'])
      ->addCacheTags(['ab_membership_list:' . $account->id()]);
    if (!$this->scope->allows($account, $workspace, WorkspacePermission::READ)) {
      throw new CacheableNotFoundHttpException($cache);
    }
    if (!$this->scope->allows($account, $workspace, WorkspacePermission::MANAGE_AGENTS)) {
      throw new CacheableAccessDeniedHttpException($cache);
    }
  }

}
