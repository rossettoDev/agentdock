<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_board\BoardRepository;
use Drupal\ab_board\ColumnManager;
use Drupal\ab_board\ColumnRepository;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Column;
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
 * Creates, renames, reorders and archives columns on the board screen.
 *
 * The five system columns can be renamed and reordered. Only a column
 * the creator added can be archived, and only when it has no tasks.
 */
final class ColumnManageForm extends FormBase {

  public function __construct(
    private readonly BoardRepository $boards,
    private readonly ColumnRepository $columns,
    private readonly ColumnManager $manager,
    private readonly MembershipScope $scope,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_column_manage';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $board_uuid = '') {
    $board = $this->visibleBoard($board_uuid);
    $workspace = $this->workspaceOf($board);
    $this->assertManager($workspace);
    $columns = $this->columns->ensure($board);
    $form['#attributes']['class'][] = 'ab-columns';
    $form['board_uuid'] = [
      '#type' => 'hidden',
      '#value' => $board->uuid(),
    ];
    $help = $this->t('Crie, renomeie e reordene as colunas humanas.');
    $help .= ' ' . $this->t('Arrastar não entra nas colunas do agente.');
    $form['help'] = [
      '#markup' => '<p class="ab-columns__help">' . $help . '</p>',
    ];
    $form['columns'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['ab-columns__list']],
    ];
    $last = count($columns) - 1;
    foreach ($columns as $index => $column) {
      if (!$column instanceof Column) {
        continue;
      }
      $machine = $column->get('machine')->getString();
      $name = $column->get('name')->getString();
      $system = (int) $column->get('system')->getString() === 1;
      $form['columns'][$machine] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ab-columns__row']],
      ];
      $form['columns'][$machine]['name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Nome'),
        '#default_value' => $name,
        '#maxlength' => 80,
        '#required' => TRUE,
        '#size' => 24,
      ];
      $form['columns'][$machine]['up'] = [
        '#type' => 'submit',
        '#value' => $this->t('Subir'),
        '#name' => 'column_up_' . $machine,
        '#limit_validation_errors' => [['board_uuid']],
        '#column_machine' => $machine,
        '#column_op' => 'up',
        '#access' => $index > 0,
        '#attributes' => ['class' => ['ab-button', 'ab-button--secondary']],
      ];
      $form['columns'][$machine]['down'] = [
        '#type' => 'submit',
        '#value' => $this->t('Descer'),
        '#name' => 'column_down_' . $machine,
        '#limit_validation_errors' => [['board_uuid']],
        '#column_machine' => $machine,
        '#column_op' => 'down',
        '#access' => $index < $last,
        '#attributes' => ['class' => ['ab-button', 'ab-button--secondary']],
      ];
      $form['columns'][$machine]['archive'] = [
        '#type' => 'submit',
        '#value' => $this->t('Arquivar coluna'),
        '#name' => 'column_archive_' . $machine,
        '#limit_validation_errors' => [['board_uuid']],
        '#column_machine' => $machine,
        '#column_op' => 'archive',
        '#access' => !$system,
        '#attributes' => ['class' => ['ab-button', 'ab-button--secondary']],
      ];
    }
    $form['new_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nova coluna'),
      '#maxlength' => 80,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Salvar colunas'),
      '#name' => 'column_save',
      '#column_op' => 'save',
      '#limit_validation_errors' => [['board_uuid'], ['columns']],
      '#attributes' => ['class' => ['ab-button']],
    ];
    $form['actions']['create'] = [
      '#type' => 'submit',
      '#value' => $this->t('Criar coluna'),
      '#name' => 'column_create',
      '#column_op' => 'create',
      '#limit_validation_errors' => [['board_uuid'], ['new_name']],
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
    $trigger = $form_state->getTriggeringElement();
    $op = is_array($trigger) ? (string) ($trigger['#column_op'] ?? '') : '';
    $machine = is_array($trigger) ? (string) ($trigger['#column_machine'] ?? '') : '';
    try {
      if ($op === 'create') {
        $this->manager->create($board, (string) $form_state->getValue('new_name'));
        $this->messenger()->addStatus($this->t('Coluna criada.'));
      }
      elseif ($op === 'save') {
        $this->renameAll($board, $form_state);
        $this->messenger()->addStatus($this->t('Colunas salvas.'));
      }
      else {
        $column = $this->columnByMachine($board, $machine);
        if (!$column instanceof Column) {
          $this->messenger()->addError($this->t('O registro não foi encontrado.'));
          return;
        }
        $this->apply($column, $op);
      }
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
    $form_state->setRedirect('ab_ui.board_tasks', ['uuid' => $board->uuid()]);
  }

  /**
   * Renames each column whose label changed.
   */
  private function renameAll(Board $board, FormStateInterface $form_state): void {
    $submitted = $form_state->getValue('columns');
    if (!is_array($submitted)) {
      return;
    }
    foreach ($this->columns->ensure($board) as $column) {
      $machine = $column->get('machine')->getString();
      $row = $submitted[$machine] ?? NULL;
      if (!is_array($row)) {
        continue;
      }
      $name = isset($row['name']) ? (string) $row['name'] : '';
      if ($name !== $column->get('name')->getString()) {
        $this->manager->rename($column, $name);
      }
    }
  }

  /**
   * Runs one column button.
   */
  private function apply(Column $column, string $op): void {
    if ($op === 'up' || $op === 'down') {
      $this->manager->move($column, $op);
      $this->messenger()->addStatus($this->t('Coluna reordenada.'));
      return;
    }
    if ($op === 'archive') {
      $this->manager->archive($column);
      $this->messenger()->addStatus($this->t('Coluna arquivada.'));
    }
  }

  /**
   * Finds a column of this board by its machine key.
   */
  private function columnByMachine(Board $board, string $machine): ?Column {
    foreach ($this->columns->loadAll($board) as $column) {
      if ($column->get('machine')->getString() === $machine) {
        return $column;
      }
    }
    return NULL;
  }

  /**
   * Maps a rejected name or archive to a message.
   */
  private function flag(FormStateInterface $form_state, \InvalidArgumentException $exception): void {
    $message = $exception->getMessage();
    if (str_contains($message, 'name')) {
      $form_state->setErrorByName('new_name', $this->t('Informe o nome da coluna.'));
      return;
    }
    if (str_contains($message, 'too many')) {
      $this->messenger()->addError($this->t('O quadro já tem colunas demais.'));
      return;
    }
    if (str_contains($message, 'system')) {
      $this->messenger()->addError($this->t('Essa coluna faz parte do fluxo do quadro.'));
      return;
    }
    if (str_contains($message, 'tasks')) {
      $this->messenger()->addError($this->t('A coluna ainda tem tarefas.'));
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
    throw new CacheableNotFoundHttpException((new CacheableMetadata())->addCacheContexts(['user']));
  }

  /**
   * Loads the workspace stored on the board.
   */
  private function workspaceOf(Board $board): Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($board->get('workspace')->getString());
    if (!$workspace instanceof Workspace) {
      throw new CacheableNotFoundHttpException((new CacheableMetadata())->addCacheContexts(['user']));
    }
    return $workspace;
  }

  /**
   * Refuses readers and callers outside the workspace.
   */
  private function assertManager(Workspace $workspace): void {
    $account = $this->currentUser();
    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'ab_memberships', 'ab_workspace']);
    if (!$this->scope->allows($account, $workspace, WorkspacePermission::READ)) {
      throw new CacheableNotFoundHttpException($cache);
    }
    if (!$this->scope->allows($account, $workspace, WorkspacePermission::MANAGE_BOARDS)) {
      throw new CacheableAccessDeniedHttpException($cache);
    }
  }

}
