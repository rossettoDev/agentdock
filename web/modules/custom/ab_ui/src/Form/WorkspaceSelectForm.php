<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceRepository;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Selects one accessible workspace for the current session.
 */
final class WorkspaceSelectForm extends FormBase {

  public function __construct(
    private readonly WorkspaceRepository $workspaces,
    private readonly WorkspaceContext $context,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_workspace_select';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $options = $this->options();
    $current = $this->context->workspace();
    $selected = $current instanceof Workspace ? $current->uuid() : NULL;
    $form['workspace'] = [
      '#type' => 'select',
      '#title' => $this->t('Escolher workspace'),
      '#required' => TRUE,
      '#options' => $options,
    ];
    if (is_string($selected) && isset($options[$selected])) {
      $form['workspace']['#default_value'] = $selected;
    }
    else {
      $form['workspace']['#empty_option'] = $this->t('Selecione');
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Usar este workspace'),
      '#attributes' => ['class' => ['ab-button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $uuid = (string) $form_state->getValue('workspace');
    if (!isset($this->options()[$uuid])) {
      $form_state->setErrorByName('workspace', $this->t('Workspace was not found.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      $this->context->select((string) $form_state->getValue('workspace'));
    }
    catch (ScopedResourceNotFoundException) {
      $form_state->setErrorByName('workspace', $this->t('Workspace was not found.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Workspace selecionado.'));
    $form_state->setRedirect('ab_ui.workspaces');
  }

  /**
   * Returns the workspaces the current user can read, keyed by UUID.
   *
   * @return array<string, string>
   *   UUID keys and workspace labels.
   */
  private function options(): array {
    $options = [];
    foreach ($this->workspaces->accessibleWorkspaces() as $workspace) {
      $options[$workspace->uuid()] = (string) $workspace->label();
    }
    return $options;
  }

}
