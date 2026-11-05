<?php

declare(strict_types=1);

namespace Drupal\ab_board;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Column;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_event\EventDraft;
use Drupal\ab_event\EventStoreInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Creates, renames, reorders and archives columns.
 *
 * The workspace row is locked before the board row. The five system
 * columns stay. A locked column is not a drag target.
 */
final class ColumnManager {

  /**
   * Active columns a board may show, including the five defaults.
   */
  private const LIMIT = 20;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly MembershipScope $scope,
    private readonly HumanActorContextFactory $actors,
    private readonly EventStoreInterface $events,
    private readonly CacheTagsInvalidatorInterface $cacheTags,
    private readonly ColumnRepository $columns,
  ) {}

  /**
   * Adds a human column at the end of the board.
   *
   * @throws \InvalidArgumentException
   *   The name is empty or the board already has the limit.
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The board workspace is outside the caller's scope.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller can see the workspace but cannot manage boards.
   */
  public function create(Board $board, string $name): Column {
    $workspace = $this->workspaceOf($board);
    $this->assertManage($workspace);
    $name = $this->name($name);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $this->lockBoard($board);
      $this->columns->ensure($board);
      $active = $this->active($board);
      if (count($active) >= self::LIMIT) {
        throw new \InvalidArgumentException('The board has too many columns.');
      }
      $position = 0;
      foreach ($active as $column) {
        $position = max($position, (int) $column->get('position')->getString() + 1);
      }
      $created = Column::create([
        'workspace' => $workspace->id(),
        'board' => $board->id(),
        'name' => $name,
        'machine' => $this->machine($board),
        'position' => $position,
        'archived' => FALSE,
        'locked' => FALSE,
        'system' => FALSE,
      ]);
      $created->save();
      if (!$created instanceof Column || $created->uuid() === NULL || $created->uuid() === '') {
        throw new \RuntimeException('The column was not stored.');
      }
      $this->record($workspace, $created, 'column.created');
      $this->invalidate($workspace, $board);
      $this->afterRecord($created);
      return $created;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Changes the label. The machine key stays.
   *
   * @throws \InvalidArgumentException
   *   The name is empty.
   */
  public function rename(Column $column, string $name): Column {
    $board = $this->boardOf($column);
    $workspace = $this->workspaceOf($board);
    $this->assertManage($workspace);
    $name = $this->name($name);
    if ($column->get('name')->getString() === $name) {
      return $column;
    }
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $this->lockBoard($board);
      $current = $this->reload($column);
      $current->set('name', $name);
      $current->save();
      $this->record($workspace, $current, 'column.renamed');
      $this->invalidate($workspace, $board);
      $this->afterRecord($current);
      return $current;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Moves a column one place left or right.
   *
   * @param \Drupal\ab_board\Entity\Column $column
   *   Column to move.
   * @param string $direction
   *   Either "up" or "down".
   */
  public function move(Column $column, string $direction): Column {
    $board = $this->boardOf($column);
    $workspace = $this->workspaceOf($board);
    $this->assertManage($workspace);
    if (!in_array($direction, ['up', 'down'], TRUE)) {
      throw new \InvalidArgumentException('The column order is invalid.');
    }
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $this->lockBoard($board);
      $active = $this->active($board);
      $index = $this->indexOf($active, $column);
      $swap = $direction === 'up' ? $index - 1 : $index + 1;
      if ($index < 0 || !isset($active[$swap])) {
        return $this->reload($column);
      }
      $this->swap($active[$index], $active[$swap]);
      $current = $this->reload($column);
      $this->record($workspace, $current, 'column.reordered');
      $this->invalidate($workspace, $board);
      $this->afterRecord($current);
      return $current;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Hides a custom column that has no tasks.
   *
   * @throws \InvalidArgumentException
   *   The column is one of the five, or it still has tasks.
   */
  public function archive(Column $column): Column {
    $board = $this->boardOf($column);
    $workspace = $this->workspaceOf($board);
    $this->assertManage($workspace);
    if ((int) $column->get('system')->getString() === 1) {
      throw new \InvalidArgumentException('A system column cannot be archived.');
    }
    if ((int) $column->get('archived')->getString() === 1) {
      return $column;
    }
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $this->lockBoard($board);
      $current = $this->reload($column);
      if ($this->taskCount($board, $current) > 0) {
        throw new \InvalidArgumentException('The column still has tasks.');
      }
      $current->set('archived', TRUE);
      $current->save();
      $this->record($workspace, $current, 'column.archived');
      $this->invalidate($workspace, $board);
      $this->afterRecord($current);
      return $current;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Runs after the event is recorded and before the caller commits.
   */
  protected function afterRecord(Column $column): void {
    // Tests throw from here to prove the caller transaction rolls back.
  }

  /**
   * Requires permission to manage boards in a visible workspace.
   */
  private function assertManage(Workspace $workspace): void {
    $account = $this->currentUser->getAccount();
    $can_read = $this->scope->allows($account, $workspace, WorkspacePermission::READ);
    if (!$can_read) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
    $can_manage = $this->scope->allows($account, $workspace, WorkspacePermission::MANAGE_BOARDS);
    if (!$can_manage) {
      throw new ScopedOperationDeniedException('The column cannot be changed.');
    }
  }

  /**
   * Loads the workspace stored on the board.
   */
  private function workspaceOf(Board $board): Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($board->get('workspace')->getString());
    if (!$workspace instanceof Workspace) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
    return $workspace;
  }

  /**
   * Loads the board stored on the column.
   */
  private function boardOf(Column $column): Board {
    $board = $this->entityTypeManager->getStorage('ab_board')->load($column->get('board')->getString());
    if (!$board instanceof Board) {
      throw new ScopedResourceNotFoundException('Board was not found.');
    }
    return $board;
  }

  /**
   * Locks the workspace row for the current transaction.
   */
  private function lockWorkspace(Workspace $workspace): void {
    $locked = $this->database->query('SELECT id FROM {ab_workspace} WHERE id = :id FOR UPDATE', [
      ':id' => $workspace->id(),
    ])->fetchField();
    if ($locked === FALSE) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
  }

  /**
   * Locks the board row for the current transaction.
   */
  private function lockBoard(Board $board): void {
    $locked = $this->database->query('SELECT id FROM {ab_board} WHERE id = :id FOR UPDATE', [
      ':id' => $board->id(),
    ])->fetchField();
    if ($locked === FALSE) {
      throw new ScopedResourceNotFoundException('Board was not found.');
    }
  }

  /**
   * Returns the active columns after the board lock.
   *
   * @return \Drupal\ab_board\Entity\Column[]
   *   Columns in display order.
   */
  private function active(Board $board): array {
    $active = [];
    foreach ($this->columns->loadAll($board) as $column) {
      if ((int) $column->get('archived')->getString() === 1) {
        continue;
      }
      $active[] = $column;
    }
    return $active;
  }

  /**
   * Finds the column in the active list.
   *
   * @param \Drupal\ab_board\Entity\Column[] $columns
   *   Active columns.
   * @param \Drupal\ab_board\Entity\Column $column
   *   Column to locate.
   */
  private function indexOf(array $columns, Column $column): int {
    foreach ($columns as $index => $item) {
      if ((string) $item->id() === (string) $column->id()) {
        return $index;
      }
    }
    return -1;
  }

  /**
   * Exchanges the positions of two columns.
   */
  private function swap(Column $left, Column $right): void {
    $position = (int) $left->get('position')->getString();
    $left->set('position', (int) $right->get('position')->getString());
    $right->set('position', $position);
    $left->save();
    $right->save();
  }

  /**
   * Counts tasks still stored in the column.
   */
  private function taskCount(Board $board, Column $column): int {
    $count = $this->database->select('ab_task', 't')
      ->condition('board', $board->id())
      ->condition('status', $column->get('machine')->getString())
      ->countQuery()
      ->execute()
      ->fetchField();
    return (int) $count;
  }

  /**
   * Reloads a column from storage.
   */
  private function reload(Column $column): Column {
    $storage = $this->entityTypeManager->getStorage('ab_column');
    $storage->resetCache([$column->id()]);
    $reloaded = $storage->load($column->id());
    if (!$reloaded instanceof Column) {
      throw new ScopedResourceNotFoundException('Column was not found.');
    }
    return $reloaded;
  }

  /**
   * Normalizes a column label.
   */
  private function name(string $name): string {
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 80) {
      throw new \InvalidArgumentException('A column requires a name.');
    }
    return $name;
  }

  /**
   * Builds a machine key that is not one of the workflow statuses.
   */
  private function machine(Board $board): string {
    $taken = [];
    foreach ($this->columns->loadAll($board) as $column) {
      $taken[$column->get('machine')->getString()] = TRUE;
    }
    foreach (ColumnDefaults::definitions() as $spec) {
      $taken[$spec['machine']] = TRUE;
    }
    $taken['cancelled'] = TRUE;
    for ($attempt = 0; $attempt < 5; $attempt++) {
      $machine = 'c' . bin2hex(random_bytes(4));
      if (!isset($taken[$machine])) {
        return $machine;
      }
    }
    throw new \RuntimeException('The column key was not stored.');
  }

  /**
   * Drops the cached board after the column row is stored.
   */
  private function invalidate(Workspace $workspace, Board $board): void {
    $this->cacheTags->invalidateTags([
      'ab_board_columns:' . $board->id(),
      'ab_workspace_tasks:' . $workspace->id(),
    ]);
  }

  /**
   * Records a column event for the authenticated actor.
   */
  private function record(Workspace $workspace, Column $column, string $type): void {
    $actor = $this->actors->create($workspace);
    $this->events->record(new EventDraft(
      (string) $workspace->uuid(),
      $type,
      (string) $column->uuid(),
      $actor->actorType,
      $actor->actorId,
      [
        'name' => $column->get('name')->getString(),
        'machine' => $column->get('machine')->getString(),
        'position' => (int) $column->get('position')->getString(),
        'archived' => (int) $column->get('archived')->getString() === 1,
      ],
    ));
  }

}
