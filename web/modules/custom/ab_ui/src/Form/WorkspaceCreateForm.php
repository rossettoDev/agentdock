<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;

/**
 * Creates a workspace for the authenticated user.
 */
final class WorkspaceCreateForm extends FormBase {

  public function __construct(
    private readonly WorkspaceCreator $creator,
    private readonly WorkspaceContext $context,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_workspace_create';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $zones = \DateTimeZone::listIdentifiers();
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nome'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['timezone'] = [
      '#type' => 'select',
      '#title' => $this->t('Fuso horário'),
      '#required' => TRUE,
      '#options' => array_combine($zones, $zones),
      '#default_value' => WorkspaceCreator::DEFAULT_TIMEZONE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Criar workspace'),
      '#attributes' => ['class' => ['ab-button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $account = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    if (!$account instanceof UserInterface) {
      throw new \InvalidArgumentException('A workspace requires a user.');
    }
    try {
      $workspace = $this->creator->create(
        $account,
        (string) $form_state->getValue('name'),
        (string) $form_state->getValue('timezone'),
      );
    }
    catch (EntityStorageException) {
      $form_state->setErrorByName('name', $this->t('O workspace não pôde ser criado.'));
      return;
    }
    if (!$workspace instanceof Workspace) {
      $form_state->setErrorByName('name', $this->t('O workspace não pôde ser criado.'));
      return;
    }
    $this->context->select($workspace->uuid());
    $this->messenger()->addStatus($this->t('Workspace criado.'));
    $form_state->setRedirect('ab_ui.workspaces');
  }

}
