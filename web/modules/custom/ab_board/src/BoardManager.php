<?php

declare(strict_types=1);

namespace Drupal\ab_board;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_event\EventDraft;
use Drupal\ab_event\EventStoreInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Creates and archives boards inside the caller's transaction.
 *
 * The workspace row is locked before the board row. EventStore::record()
 * runs after those locks and does not commit.
 */
class BoardManager {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly MembershipScope $scope,
    private readonly HumanActorContextFactory $actors,
    private readonly EventStoreInterface $events,
  ) {}

  /**
   * Creates a board and records board.created.
   *
   * @throws \InvalidArgumentException
   *   The name is empty.
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The workspace is outside the caller's scope.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller can see the workspace but cannot manage boards.
   */
  public function create(Workspace $workspace, string $name, string $description = ''): Board {
    $this->assertManage($workspace);
    $name = trim($name);
    if ($name === '') {
      throw new \InvalidArgumentException('A board requires a name.');
    }
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $created = Board::create([
        'workspace' => $workspace->id(),
        'name' => $name,
        'description' => $description,
        'archived' => FALSE,
      ]);
      $created->save();
      if (!$created instanceof Board || $created->uuid() === NULL || $created->uuid() === '') {
        throw new \RuntimeException('The board was not stored.');
      }
      $this->record($workspace, $created, 'board.created');
      $this->afterRecord($created);
      return $created;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Archives a board without deleting its stored fields.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The board workspace is outside the caller's scope.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller can see the workspace but cannot manage boards.
   */
  public function archive(Board $board): Board {
    $workspace = $this->workspaceOf($board);
    $this->assertManage($workspace);
    if ((int) $board->get('archived')->getString() === 1) {
      return $board;
    }
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $this->lockBoard($board);
      $board->set('archived', TRUE);
      $board->save();
      $this->record($workspace, $board, 'board.archived');
      $this->afterRecord($board);
      return $board;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Runs after the event is recorded and before the caller commits.
   */
  protected function afterRecord(Board $board): void {
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
      throw new ScopedOperationDeniedException('The board cannot be changed.');
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
   * Records a board event for the authenticated actor.
   */
  private function record(Workspace $workspace, Board $board, string $type): void {
    $actor = $this->actors->create($workspace);
    $workspace_id = (string) $workspace->uuid();
    $board_id = (string) $board->uuid();
    $this->events->record(new EventDraft(
      $workspace_id,
      $type,
      $board_id,
      $actor->actorType,
      $actor->actorId,
      [
        'name' => (string) $board->label(),
        'archived' => (int) $board->get('archived')->getString() === 1,
      ],
    ));
  }

}
