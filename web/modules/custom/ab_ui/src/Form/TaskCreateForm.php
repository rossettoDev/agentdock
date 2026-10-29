<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_board\BoardRepository;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\TaskManager;
use Drupal\ab_board\TaskRepository;
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
 * Creates a task on a board the current user can edit.
 */
class TaskCreateForm extends FormBase {

  public function __construct(
    protected readonly BoardRepository $boards,
    protected readonly TaskRepository $tasks,
    protected readonly TaskManager $manager,
    protected readonly MembershipScope $scope,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_task_create';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $uuid = '') {
    $board = $this->visibleBoard($uuid);
    $workspace = $this->tasks->workspaceOf($board);
    $this->assertWriter($workspace);
    $this->cache($workspace)->applyTo($form);
    $form['board_uuid'] = [
      '#type' => 'hidden',
      '#value' => $board->uuid(),
    ];
    $this->fields($form, NULL);
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Criar tarefa'),
      '#attributes' => ['class' => ['ab-button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $board = $this->boards->loadAccessibleByUuid((string) $form_state->getValue('board_uuid'));
    if (!$board instanceof Board) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return;
    }
    try {
      $workspace = $this->tasks->workspaceOf($board);
      $task = $this->manager->create($board, [
        'title' => (string) $form_state->getValue('title'),
        'description' => (string) $form_state->getValue('description'),
        'acceptance_criteria' => (string) $form_state->getValue('acceptance_criteria'),
        'priority' => (string) $form_state->getValue('priority'),
        'human_owner' => $this->ownerId((string) $form_state->getValue('human_owner')),
        'due_at' => $this->dueTimestamp($workspace, (string) $form_state->getValue('due_at')),
      ]);
    }
    catch (ScopedResourceNotFoundException) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return;
    }
    catch (ScopedOperationDeniedException) {
      $this->messenger()->addError($this->t('Operação não permitida.'));
      return;
    }
    catch (\InvalidArgumentException $exception) {
      $this->flag($form_state, $exception);
      return;
    }
    $this->messenger()->addStatus($this->t('Tarefa criada.'));
    $form_state->setRedirect('ab_ui.board_task', [
      'uuid' => $board->uuid(),
      'task' => $task->uuid(),
    ]);
  }

  /**
   * Adds the editable fields.
   *
   * @param array<string, mixed> $form
   *   Form structure.
   * @param array<string, mixed>|null $defaults
   *   Stored values for an edit, or NULL when creating.
   */
  protected function fields(array &$form, ?array $defaults): void {
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Título'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $defaults['title'] ?? '',
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Descrição'),
      '#description' => $this->t('Markdown. HTML é exibido como texto.'),
      '#default_value' => $defaults['description'] ?? '',
    ];
    $form['acceptance_criteria'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Critérios de aceite'),
      '#description' => $this->t('Markdown. HTML é exibido como texto.'),
      '#default_value' => $defaults['acceptance_criteria'] ?? '',
    ];
    $form['priority'] = [
      '#type' => 'select',
      '#title' => $this->t('Prioridade'),
      '#options' => [
        'low' => $this->t('Baixa'),
        'normal' => $this->t('Normal'),
        'high' => $this->t('Alta'),
        'urgent' => $this->t('Urgente'),
      ],
      '#default_value' => $defaults['priority'] ?? 'normal',
    ];
    $form['human_owner'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Responsável'),
      '#maxlength' => 60,
      '#default_value' => $defaults['human_owner'] ?? '',
    ];
    $form['due_at'] = [
      '#type' => 'date',
      '#title' => $this->t('Prazo'),
      '#default_value' => $defaults['due_at'] ?? '',
    ];
  }

  /**
   * Resolves a username to an active account id.
   */
  protected function ownerId(string $name): ?int {
    $name = trim($name);
    if ($name === '') {
      return NULL;
    }
    $ids = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $name)
      ->condition('status', 1)
      ->execute();
    $id = reset($ids);
    if ($id === FALSE) {
      throw new \InvalidArgumentException('Human owner was not found.');
    }
    return (int) $id;
  }

  /**
   * Converts a date in the workspace timezone to a UTC timestamp.
   */
  protected function dueTimestamp(Workspace $workspace, string $value): ?int {
    if ($value === '') {
      return NULL;
    }
    try {
      $zone = new \DateTimeZone($workspace->get('timezone')->getString());
    }
    catch (\Exception) {
      throw new \InvalidArgumentException('Due must be a timestamp.');
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
    $errors = \DateTimeImmutable::getLastErrors();
    $invalid = $date === FALSE
      || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0));
    if ($invalid) {
      throw new \InvalidArgumentException('Due must be a timestamp.');
    }
    return $date->getTimestamp();
  }

  /**
   * Maps an editor rejection to the field that caused it.
   */
  protected function flag(FormStateInterface $form_state, \InvalidArgumentException $exception): void {
    $message = $exception->getMessage();
    if (str_contains($message, 'title')) {
      $form_state->setErrorByName('title', $this->t('Informe um título.'));
      return;
    }
    if (str_contains($message, 'Priority')) {
      $form_state->setErrorByName('priority', $this->t('Prioridade inválida.'));
      return;
    }
    if (str_contains($message, 'Human owner')) {
      $form_state->setErrorByName('human_owner', $this->t('Responsável não encontrado.'));
      return;
    }
    if (str_contains($message, 'Due')) {
      $form_state->setErrorByName('due_at', $this->t('Prazo inválido.'));
      return;
    }
    $this->messenger()->addError($this->t('Operação não permitida.'));
  }

  /**
   * Loads a board the current user can read, or answers 404.
   */
  private function visibleBoard(string $uuid): Board {
    $board = $this->boards->loadAccessibleByUuid($uuid);
    if ($board instanceof Board) {
      return $board;
    }
    throw new CacheableNotFoundHttpException($this->cache(NULL));
  }

  /**
   * Refuses readers and callers outside the workspace.
   */
  protected function assertWriter(Workspace $workspace): void {
    $account = $this->currentUser();
    $cache = $this->cache($workspace);
    if (!$this->scope->allows($account, $workspace, WorkspacePermission::READ)) {
      throw new CacheableNotFoundHttpException($cache);
    }
    if (!$this->scope->allows($account, $workspace, WorkspacePermission::WRITE)) {
      throw new CacheableAccessDeniedHttpException($cache);
    }
  }

  /**
   * Cache metadata for the form.
   */
  protected function cache(?Workspace $workspace): CacheableMetadata {
    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'ab_memberships', 'ab_workspace'])
      ->addCacheTags(['ab_membership_list:' . $this->currentUser()->id()]);
    if ($workspace instanceof Workspace && $workspace->id() !== NULL) {
      $cache->addCacheTags(['ab_workspace_tasks:' . $workspace->id()]);
    }
    return $cache;
  }

}
