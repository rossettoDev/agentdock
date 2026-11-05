<?php

declare(strict_types=1);

namespace Drupal\ab_board;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Column;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\UuidLookup;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Reads the columns of a board the current user can see.
 */
final class ColumnRepository {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MembershipScope $scope,
    private readonly UuidLookup $uuidLookup,
    private readonly Connection $database,
  ) {}

  /**
   * Returns the visible columns, seeding the five defaults when missing.
   *
   * @return \Drupal\ab_board\Entity\Column[]
   *   Active columns in display order. Empty when the table is absent.
   */
  public function ensure(Board $board): array {
    if (!$this->tableExists() || $board->id() === NULL) {
      return [];
    }
    $stored = $this->loadAll($board);
    if ($stored === []) {
      $this->seed($board);
      $stored = $this->loadAll($board);
    }
    return $this->active($stored);
  }

  /**
   * Returns one column the current user can read.
   */
  public function loadAccessibleByUuid(string $uuid): ?Column {
    try {
      $loaded = $this->uuidLookup->load('ab_column', $uuid);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
    if (!$loaded instanceof Column) {
      return NULL;
    }
    $workspace = $this->workspaceOf($loaded);
    if (!$workspace instanceof Workspace || !$this->canRead($workspace)) {
      return NULL;
    }
    return $loaded;
  }

  /**
   * Machine keys a person may drag a card into.
   *
   * @return string[]
   *   Unlocked column keys. The five defaults when nothing is stored.
   */
  public function humanKeys(Board $board): array {
    $columns = $this->ensure($board);
    if ($columns === []) {
      return ['backlog', 'ready'];
    }
    $keys = [];
    foreach ($columns as $column) {
      if ((int) $column->get('locked')->getString() === 1) {
        continue;
      }
      $keys[] = $column->get('machine')->getString();
    }
    return $keys === [] ? ['backlog', 'ready'] : $keys;
  }

  /**
   * Returns the stored label for a machine key.
   */
  public function label(Board $board, string $machine): ?string {
    foreach ($this->ensure($board) as $column) {
      if ($column->get('machine')->getString() === $machine) {
        return $column->get('name')->getString();
      }
    }
    return NULL;
  }

  /**
   * Inserts the five default columns once.
   */
  private function seed(Board $board): void {
    $transaction = $this->database->startTransaction();
    try {
      $locked = $this->database->query('SELECT id FROM {ab_board} WHERE id = :id FOR UPDATE', [
        ':id' => $board->id(),
      ])->fetchField();
      if ($locked === FALSE) {
        throw new ScopedResourceNotFoundException('Board was not found.');
      }
      if ($this->loadAll($board) !== []) {
        return;
      }
      foreach (ColumnDefaults::definitions() as $position => $spec) {
        $column = Column::create([
          'workspace' => $board->get('workspace')->getString(),
          'board' => $board->id(),
          'name' => $spec['name'],
          'machine' => $spec['machine'],
          'position' => $position,
          'archived' => FALSE,
          'locked' => $spec['locked'],
          'system' => TRUE,
        ]);
        $column->save();
      }
    }
    catch (EntityStorageException) {
      $transaction->rollBack();
      if ($this->loadAll($board) === []) {
        throw new EntityStorageException('The board columns were not stored.');
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Loads every column of the board, including archived ones.
   *
   * @return \Drupal\ab_board\Entity\Column[]
   *   Columns ordered by position.
   */
  public function loadAll(Board $board): array {
    if (!$this->tableExists() || $board->id() === NULL) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('ab_column');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('board', $board->id())
      ->sort('position')
      ->sort('id')
      ->execute();
    $columns = [];
    foreach ($storage->loadMultiple($ids) as $column) {
      if ($column instanceof Column) {
        $columns[] = $column;
      }
    }
    return $columns;
  }

  /**
   * Drops archived columns.
   *
   * @param \Drupal\ab_board\Entity\Column[] $columns
   *   Columns already ordered.
   *
   * @return \Drupal\ab_board\Entity\Column[]
   *   Columns still shown on the board.
   */
  private function active(array $columns): array {
    $visible = [];
    foreach ($columns as $column) {
      if ((int) $column->get('archived')->getString() === 1) {
        continue;
      }
      $visible[] = $column;
    }
    return $visible;
  }

  /**
   * Reports whether the column table is installed.
   */
  public function tableExists(): bool {
    return $this->database->schema()->tableExists('ab_column');
  }

  /**
   * Loads the workspace stored on the column.
   */
  private function workspaceOf(Column $column): ?Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($column->get('workspace')->getString());
    return $workspace instanceof Workspace ? $workspace : NULL;
  }

  /**
   * Reports whether the current user can read the workspace.
   */
  private function canRead(Workspace $workspace): bool {
    return $this->scope->allows(
      $this->currentUser->getAccount(),
      $workspace,
      WorkspacePermission::READ,
    );
  }

}
