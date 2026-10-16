<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentRepository;
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Edits one agent, including whether it is enabled.
 *
 * The screen does not report execution or heartbeat. Those signals do
 * not exist yet. Opening the agent does not change the selected workspace.
 */
final class AgentEditForm extends AgentCreateForm {

  public function __construct(
    AgentManager $agents,
    WorkspaceContext $context,
    MembershipScope $scope,
    private readonly AgentRepository $repository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($agents, $context, $scope);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_agent_edit';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $agent_uuid = '') {
    $agent = $this->repository->loadAccessibleByUuid($agent_uuid);
    if (!$agent instanceof Agent) {
      return $form;
    }
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($agent->get('workspace')->getString());
    if (!$workspace instanceof Workspace) {
      return $form;
    }
    $this->assertManager($workspace);
    $form['#id'] = 'ab-ui-agent-edit-' . $agent_uuid;
    $form['agent_uuid'] = [
      '#type' => 'hidden',
      '#value' => $agent_uuid,
    ];
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nome'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $agent->label(),
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Descrição'),
      '#required' => FALSE,
      '#default_value' => $agent->get('description')->getString(),
    ];
    $form['capabilities'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Capacidades'),
      '#required' => FALSE,
      '#maxlength' => 255,
      '#default_value' => $agent->get('capabilities')->getString(),
    ];
    $form['concurrency_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Limite de tarefas ao mesmo tempo'),
      '#default_value' => $agent->get('concurrency_limit')->getString(),
    ];
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Habilitado'),
      '#default_value' => (int) $agent->get('enabled')->getString() === 1,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Salvar'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $agent = $this->repository->loadAccessibleByUuid((string) $form_state->getValue('agent_uuid'));
    if (!$agent instanceof Agent) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return;
    }
    try {
      $agent = $this->agents->update(
        $agent,
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
    $this->messenger()->addStatus($this->t('Agente atualizado.'));
    $form_state->setRedirect('ab_ui.agent_view', ['uuid' => $agent->uuid()]);
  }

}
