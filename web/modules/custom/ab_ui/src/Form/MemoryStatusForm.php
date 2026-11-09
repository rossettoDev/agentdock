<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_memory\MemoryRejectedException;
use Drupal\ab_memory\MemoryService;
use Drupal\ab_memory\MemoryVersionConflictException;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Publishes or archives one memory entry. Human review only.
 *
 * The version is the one rendered for this editor. Agents do not get
 * these buttons. Each successful action records a memory.* event.
 */
final class MemoryStatusForm extends FormBase {

  public function __construct(
    private readonly MemoryService $memory,
    private readonly HumanActorContextFactory $actors,
    private readonly WorkspaceContext $context,
    private readonly MembershipScope $scope,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_memory_status';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $uuid = '') {
    $workspace = $this->context->workspace();
    if (!$workspace instanceof Workspace) {
      return $form;
    }
    if (!$this->scope->allows($this->currentUser(), $workspace, WorkspacePermission::REVIEW)) {
      return $form;
    }
    try {
      $record = $this->memory->get($this->actors->create($workspace), $uuid);
    }
    catch (ScopedResourceNotFoundException | ScopedOperationDeniedException) {
      return $form;
    }
    $form['#id'] = 'ab-ui-memory-status-' . $uuid;
    $form['memory_uuid'] = [
      '#type' => 'hidden',
      '#value' => $record->uuid,
    ];
    $form['version'] = [
      '#type' => 'hidden',
      '#default_value' => (string) $record->version,
    ];
    $form['actions'] = ['#type' => 'actions'];
    if ($record->status === 'draft') {
      $form['actions']['publish'] = [
        '#type' => 'submit',
        '#value' => $this->t('Publicar'),
        '#name' => 'memory_publish',
        '#memory_op' => 'publish',
        '#attributes' => ['class' => ['ab-button']],
      ];
    }
    if (in_array($record->status, ['draft', 'published'], TRUE)) {
      $form['actions']['archive'] = [
        '#type' => 'submit',
        '#value' => $this->t('Arquivar'),
        '#name' => 'memory_archive',
        '#memory_op' => 'archive',
        '#attributes' => ['class' => ['ab-button']],
      ];
    }
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
    $uuid = (string) $form_state->getValue('memory_uuid');
    $version = (int) $form_state->getValue('version');
    $operation = $this->operation($form_state);
    $actor = $this->actors->create($workspace);
    try {
      if ($operation === 'publish') {
        $this->memory->publish($actor, $uuid, $version);
        $this->messenger()->addStatus($this->t('Memória publicada.'));
      }
      elseif ($operation === 'archive') {
        $this->memory->archive($actor, $uuid, $version);
        $this->messenger()->addStatus($this->t('Memória arquivada.'));
      }
    }
    catch (MemoryVersionConflictException) {
      $this->messenger()->addError($this->t('A memória foi alterada. Recarregue e tente de novo.'));
      return;
    }
    catch (MemoryRejectedException) {
      $this->messenger()->addError($this->t('Essa transição não está disponível.'));
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
    catch (\InvalidArgumentException) {
      $this->messenger()->addError($this->t('Essa transição não está disponível.'));
      return;
    }
    $form_state->setRedirect('ab_ui.memory_view', ['uuid' => $uuid]);
  }

  /**
   * Returns the status operation chosen by the pressed button.
   */
  private function operation(FormStateInterface $form_state): string {
    $trigger = $form_state->getTriggeringElement();
    if (is_array($trigger) && isset($trigger['#memory_op'])) {
      return (string) $trigger['#memory_op'];
    }
    return '';
  }

}
