<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_board\BoardRepository;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_memory\MemoryRejectedException;
use Drupal\ab_memory\MemoryService;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;

/**
 * Creates a draft memory entry in the selected workspace.
 *
 * Authorship comes from the session ActorContext. author_id stays out.
 */
final class MemoryDraftCreateForm extends FormBase {

  public function __construct(
    private readonly MemoryService $memory,
    private readonly HumanActorContextFactory $actors,
    private readonly WorkspaceContext $context,
    private readonly MembershipScope $scope,
    private readonly BoardRepository $boards,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_memory_draft_create';
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
    $this->assertWriter($workspace);
    $form['#cache']['tags'][] = 'ab_workspace_memory:' . $workspace->id();
    $this->fields($form, [
      'title' => '',
      'kind' => 'fact',
      'content_markdown' => '',
      'board_id' => '',
      'tags' => '',
    ], $this->boardOptions($workspace));
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Criar rascunho'),
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
      $input = $this->inputFrom($form_state);
      $created = $this->memory->createDraft($this->actors->create($workspace), $input);
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
    $this->messenger()->addStatus($this->t('Rascunho criado.'));
    $form_state->setRedirect('ab_ui.memory_view', ['uuid' => $created->uuid]);
  }

  /**
   * Shared draft fields for create and edit.
   *
   * @param array<string, mixed> $form
   *   Form array.
   * @param array<string, string> $defaults
   *   Default values.
   * @param array<string, string> $boards
   *   Board options keyed by UUID.
   */
  protected function fields(array &$form, array $defaults, array $boards): void {
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Título'),
      '#required' => TRUE,
      '#maxlength' => MemoryService::TITLE_MAX,
      '#default_value' => $defaults['title'],
    ];
    $form['kind'] = [
      '#type' => 'select',
      '#title' => $this->t('Tipo'),
      '#required' => TRUE,
      '#options' => [
        'decision' => $this->t('Decisão'),
        'fact' => $this->t('Fato'),
        'procedure' => $this->t('Procedimento'),
        'lesson' => $this->t('Lição'),
        'summary' => $this->t('Resumo'),
      ],
      '#default_value' => $defaults['kind'],
    ];
    $form['content_markdown'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Conteúdo (Markdown)'),
      '#required' => TRUE,
      '#rows' => 12,
      '#default_value' => $defaults['content_markdown'],
    ];
    $form['board_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Quadro'),
      '#options' => ['' => $this->t('Somente workspace')] + $boards,
      '#default_value' => $defaults['board_id'],
      '#description' => $this->t('Agentes só leem memória ligada a um quadro com grant.'),
    ];
    $form['tags'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tags'),
      '#default_value' => $defaults['tags'],
      '#description' => $this->t('Separe por vírgula. No máximo @n.', [
        '@n' => MemoryService::TAGS_MAX,
      ]),
      '#maxlength' => 500,
    ];
  }

  /**
   * Builds the MemoryService input whitelist from the form values.
   *
   * @return array<string, mixed>
   *   Whitelisted fields.
   */
  protected function inputFrom(FormStateInterface $form_state): array {
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
      'kind' => (string) $form_state->getValue('kind'),
      'content_markdown' => (string) $form_state->getValue('content_markdown'),
      'tags' => $tags,
      'source_type' => 'manual',
    ];
    $board = trim((string) $form_state->getValue('board_id'));
    if ($board !== '') {
      $input['board_id'] = $board;
    }
    $reason = trim((string) $form_state->getValue('change_reason'));
    if ($reason !== '') {
      $input['change_reason'] = $reason;
    }
    return $input;
  }

  /**
   * Board options inside the workspace.
   *
   * @return array<string, string>
   *   UUID => label.
   */
  protected function boardOptions(Workspace $workspace): array {
    $options = [];
    foreach ($this->boards->accessibleBoards($workspace) as $board) {
      if ($board instanceof Board) {
        $options[(string) $board->uuid()] = (string) $board->label();
      }
    }
    return $options;
  }

  /**
   * Refuses readers and outsiders.
   */
  protected function assertWriter(Workspace $workspace): void {
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
