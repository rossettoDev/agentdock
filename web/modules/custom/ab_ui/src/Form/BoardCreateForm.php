<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_board\BoardManager;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;

/**
 * Creates a board in the workspace selected for this session.
 */
final class BoardCreateForm extends FormBase {

  public function __construct(
    private readonly BoardManager $boards,
    private readonly WorkspaceContext $context,
    private readonly MembershipScope $scope,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_board_create';
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
      $form['empty'] = [
        '#markup' => $this->t('Nenhum workspace selecionado.'),
      ];
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
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Criar board'),
      '#attributes' => ['class' => ['ab-button']],
    ];
    return $form;
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
      $this->boards->create(
        $workspace,
        (string) $form_state->getValue('name'),
        (string) $form_state->getValue('description'),
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
      $form_state->setErrorByName('name', $this->t('Informe um nome.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Board criado.'));
    $form_state->setRedirect('ab_ui.boards');
  }

  /**
   * Refuses readers and callers outside the workspace.
   */
  private function assertManager(Workspace $workspace): void {
    $account = $this->currentUser();
    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'ab_memberships', 'ab_workspace'])
      ->addCacheTags(['ab_membership_list:' . $account->id()]);
    $can_read = $this->scope->allows($account, $workspace, WorkspacePermission::READ);
    if (!$can_read) {
      throw new CacheableNotFoundHttpException($cache);
    }
    $can_manage = $this->scope->allows($account, $workspace, WorkspacePermission::MANAGE_BOARDS);
    if (!$can_manage) {
      throw new CacheableAccessDeniedHttpException($cache);
    }
  }

}
