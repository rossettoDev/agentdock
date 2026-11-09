<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_memory\MemoryRecord;
use Drupal\ab_memory\MemoryRejectedException;
use Drupal\ab_memory\MemoryService;
use Drupal\ab_memory\MemoryVersionConflictException;
use Drupal\ab_ui\MemoryDiff;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;

/**
 * Revises a draft memory entry with a simple previous-revision diff.
 */
final class MemoryDraftEditForm extends FormBase {

  public function __construct(
    private readonly MemoryService $memory,
    private readonly HumanActorContextFactory $actors,
    private readonly WorkspaceContext $context,
    private readonly MembershipScope $scope,
    private readonly MemoryDiff $diff,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_memory_draft_edit';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $uuid = '') {
    $workspace = $this->context->workspace();
    $form['#cache'] = [
      'contexts' => ['user', 'ab_memberships', 'ab_workspace'],
      'tags' => ['ab_membership_list:' . $this->currentUser()->id()],
    ];
    if (!$workspace instanceof Workspace) {
      throw new CacheableNotFoundHttpException(CacheableMetadata::createFromRenderArray([
        '#cache' => $form['#cache'],
      ]));
    }
    $this->assertWriter($workspace);
    $form['#cache']['tags'][] = 'ab_workspace_memory:' . $workspace->id();
    $record = $this->loadDraft($workspace, $uuid);
    $form['memory_uuid'] = [
      '#type' => 'hidden',
      '#value' => $record->uuid,
    ];
    $form['version'] = [
      '#type' => 'hidden',
      '#default_value' => (string) $record->version,
    ];
    $form['kind_label'] = [
      '#type' => 'item',
      '#title' => $this->t('Tipo'),
      '#markup' => $this->kindLabel($record->kind),
    ];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Título'),
      '#required' => TRUE,
      '#maxlength' => MemoryService::TITLE_MAX,
      '#default_value' => $record->title,
    ];
    $form['content_markdown'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Conteúdo (Markdown)'),
      '#required' => TRUE,
      '#rows' => 12,
      '#default_value' => $record->contentMarkdown,
    ];
    $form['tags'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tags'),
      '#default_value' => implode(', ', $record->tags),
      '#maxlength' => 500,
    ];
    $form['change_reason'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Motivo da revisão'),
      '#maxlength' => MemoryService::CHANGE_REASON_MAX,
    ];
    $previous = $this->previousMarkdown($workspace, $record);
    if ($previous !== NULL) {
      $table = $this->diff->table(
        $previous,
        $record->contentMarkdown,
        (string) $this->t('Revisão anterior'),
        (string) $this->t('Atual'),
      );
      $form['diff'] = [
        '#type' => 'details',
        '#title' => $this->t('Diff da última alteração'),
        '#open' => TRUE,
        'table' => $table !== [] ? $table : [
          '#markup' => '<p>' . $this->t('Sem diferenças.') . '</p>',
        ],
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Salvar revisão'),
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
    $uuid = (string) $form_state->getValue('memory_uuid');
    $tags = [];
    $raw = trim((string) $form_state->getValue('tags'));
    if ($raw !== '') {
      foreach (explode(',', $raw) as $tag) {
        $normalized = trim($tag);
        if ($normalized !== '') {
          $tags[] = $normalized;
        }
      }
    }
    $input = [
      'title' => (string) $form_state->getValue('title'),
      'content_markdown' => (string) $form_state->getValue('content_markdown'),
      'tags' => $tags,
    ];
    $reason = trim((string) $form_state->getValue('change_reason'));
    if ($reason !== '') {
      $input['change_reason'] = $reason;
    }
    try {
      $saved = $this->memory->revise(
        $this->actors->create($workspace),
        $uuid,
        $input,
        (int) $form_state->getValue('version'),
      );
    }
    catch (MemoryVersionConflictException) {
      $this->messenger()->addError($this->t('A memória foi alterada. Recarregue e tente de novo.'));
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
    catch (MemoryRejectedException) {
      $this->messenger()->addError($this->t('O conteúdo parece sensível e não foi salvo.'));
      return;
    }
    catch (\InvalidArgumentException $exception) {
      $this->messenger()->addError($this->t('Dados inválidos: @message', [
        '@message' => $exception->getMessage(),
      ]));
      return;
    }
    $this->messenger()->addStatus($this->t('Revisão salva.'));
    $form_state->setRedirect('ab_ui.memory_view', ['uuid' => $saved->uuid]);
  }

  /**
   * Loads a draft the caller may revise.
   */
  private function loadDraft(Workspace $workspace, string $uuid): MemoryRecord {
    try {
      $record = $this->memory->get($this->actors->create($workspace), $uuid);
    }
    catch (ScopedResourceNotFoundException) {
      throw new CacheableNotFoundHttpException(CacheableMetadata::createFromRenderArray([
        '#cache' => [
          'contexts' => ['user', 'ab_memberships', 'ab_workspace'],
          'tags' => ['ab_membership_list:' . $this->currentUser()->id()],
        ],
      ]));
    }
    catch (ScopedOperationDeniedException) {
      throw new CacheableAccessDeniedHttpException(CacheableMetadata::createFromRenderArray([
        '#cache' => [
          'contexts' => ['user', 'ab_memberships', 'ab_workspace'],
          'tags' => ['ab_membership_list:' . $this->currentUser()->id()],
        ],
      ]));
    }
    if ($record->status !== 'draft') {
      throw new CacheableAccessDeniedHttpException(CacheableMetadata::createFromRenderArray([
        '#cache' => [
          'contexts' => ['user', 'ab_memberships', 'ab_workspace'],
          'tags' => ['ab_workspace_memory:' . $workspace->id()],
        ],
      ]));
    }
    return $record;
  }

  /**
   * Previous revision markdown when more than one revision exists.
   */
  private function previousMarkdown(Workspace $workspace, MemoryRecord $record): ?string {
    if ($record->revisionVersion < 2) {
      return NULL;
    }
    try {
      $revisions = $this->memory->listRevisions($this->actors->create($workspace), $record->uuid, 5);
    }
    catch (ScopedResourceNotFoundException | ScopedOperationDeniedException) {
      return NULL;
    }
    foreach ($revisions as $row) {
      if ((int) $row['version'] === $record->revisionVersion - 1) {
        return (string) $row['content_markdown'];
      }
    }
    return NULL;
  }

  /**
   * Human label for a kind.
   */
  private function kindLabel(string $kind): string {
    return match ($kind) {
      'decision' => (string) $this->t('Decisão'),
      'fact' => (string) $this->t('Fato'),
      'procedure' => (string) $this->t('Procedimento'),
      'lesson' => (string) $this->t('Lição'),
      'summary' => (string) $this->t('Resumo'),
      default => $kind,
    };
  }

  /**
   * Refuses readers and outsiders.
   */
  private function assertWriter(Workspace $workspace): void {
    $account = $this->currentUser();
    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'ab_memberships', 'ab_workspace'])
      ->addCacheTags(['ab_membership_list:' . $account->id()]);
    if (!$this->scope->allows($account, $workspace, WorkspacePermission::READ)) {
      throw new CacheableNotFoundHttpException($cache);
    }
    if (!$this->scope->allows($account, $workspace, WorkspacePermission::WRITE)) {
      throw new CacheableAccessDeniedHttpException($cache);
    }
  }

}
