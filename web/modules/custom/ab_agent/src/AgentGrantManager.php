<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

use Drupal\ab_agent\Entity\Agent;
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
 * Stores board grants that stay inside the agent workspace.
 *
 * Allowed permissions are the task permissions a workspace can delegate.
 * Membership, credential and workspace permissions are outside that set.
 */
class AgentGrantManager {

  /**
   * Permissions a board grant may contain.
   */
  public const ALLOWED = [
    WorkspacePermission::READ,
    WorkspacePermission::WRITE,
    WorkspacePermission::REVIEW,
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly MembershipScope $scope,
    private readonly HumanActorContextFactory $actors,
    private readonly EventStoreInterface $events,
  ) {}

  /**
   * Stores or replaces the grant and records the matching event.
   *
   * @param \Drupal\ab_agent\Entity\Agent $agent
   *   The agent that receives the grant.
   * @param \Drupal\ab_board\Entity\Board $board
   *   A board that must belong to the agent workspace.
   * @param string[] $permissions
   *   Requested permissions. They must be a subset of the allowed set.
   *
   * @throws \Drupal\ab_agent\AgentGrantRejectedException
   *   The board is in another workspace or a permission is not allowed.
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   Either workspace is outside the caller's scope.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller can see the workspace but cannot manage agents.
   */
  public function grant(Agent $agent, Board $board, array $permissions): void {
    $workspace = $this->workspaceOfAgent($agent);
    $this->assertManage($workspace);
    $stored = $this->normalize($permissions);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $this->lockAgent($agent);
      $this->lockBoard($board);
      $agent = $this->reloadAgent($agent);
      $board = $this->reloadBoard($board);
      if ($board->get('workspace')->getString() !== $agent->get('workspace')->getString()) {
        throw new AgentGrantRejectedException(AgentGrantRejectedException::BOARD);
      }
      $current = $this->storedPermissions($agent, $board);
      if ($current === $stored) {
        return;
      }
      if ($current === NULL) {
        $this->database->insert('ab_agent_board_grant')->fields([
          'workspace' => (int) $workspace->id(),
          'agent_id' => (int) $agent->id(),
          'board_id' => (int) $board->id(),
          'permissions' => $stored,
        ])->execute();
        $type = 'agent.grant_added';
      }
      else {
        $this->database->update('ab_agent_board_grant')
          ->fields(['permissions' => $stored])
          ->condition('agent_id', $agent->id())
          ->condition('board_id', $board->id())
          ->execute();
        $type = 'agent.grant_updated';
      }
      $this->record($workspace, $agent, $board, $type, $stored);
      $this->afterRecord($agent);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Removes one grant and records agent.grant_removed.
   *
   * @throws \Drupal\ab_agent\AgentGrantRejectedException
   *   The pair is not stored.
   */
  public function revoke(Agent $agent, Board $board): void {
    $workspace = $this->workspaceOfAgent($agent);
    $this->assertManage($workspace);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $this->lockAgent($agent);
      $this->lockBoard($board);
      $agent = $this->reloadAgent($agent);
      $board = $this->reloadBoard($board);
      $current = $this->storedPermissions($agent, $board);
      if ($current === NULL) {
        throw new AgentGrantRejectedException(AgentGrantRejectedException::MISSING);
      }
      $this->database->delete('ab_agent_board_grant')
        ->condition('agent_id', $agent->id())
        ->condition('board_id', $board->id())
        ->execute();
      $this->record($workspace, $agent, $board, 'agent.grant_removed', $current);
      $this->afterRecord($agent);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Returns the grants of a readable agent.
   *
   * @return array<int, array{board_id: string, permissions: string}>
   *   Stored pairs ordered by board id.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The agent workspace is outside the caller's scope.
   */
  public function pairs(Agent $agent): array {
    $this->assertRead($this->workspaceOfAgent($agent));
    $query = $this->database->select('ab_agent_board_grant', 'g');
    $query->fields('g', ['board_id', 'permissions']);
    $query->condition('agent_id', $agent->id());
    $query->orderBy('board_id');
    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = [
        'board_id' => (string) $row->board_id,
        'permissions' => (string) $row->permissions,
      ];
    }
    return $rows;
  }

  /**
   * Runs after the event is recorded and before the caller commits.
   */
  protected function afterRecord(Agent $agent): void {
    // Tests throw from here to prove the caller transaction rolls back.
  }

  /**
   * Sorts and checks the requested permissions.
   *
   * @param string[] $permissions
   *   Requested permissions.
   */
  private function normalize(array $permissions): string {
    $unique = array_values(array_unique($permissions));
    sort($unique);
    if ($unique === [] || array_diff($unique, self::ALLOWED) !== []) {
      throw new AgentGrantRejectedException(AgentGrantRejectedException::PERMISSION);
    }
    return implode(',', $unique);
  }

  /**
   * Reads the stored permission string, or NULL when the pair is absent.
   */
  private function storedPermissions(Agent $agent, Board $board): ?string {
    $query = $this->database->select('ab_agent_board_grant', 'g');
    $query->addField('g', 'permissions');
    $query->condition('agent_id', $agent->id());
    $query->condition('board_id', $board->id());
    $stored = $query->execute()->fetchField();
    return $stored === FALSE ? NULL : (string) $stored;
  }

  /**
   * Requires permission to manage agents in a visible workspace.
   */
  private function assertManage(Workspace $workspace): void {
    $this->assertRead($workspace);
    $allowed = $this->scope->allows(
      $this->currentUser->getAccount(),
      $workspace,
      WorkspacePermission::MANAGE_AGENTS,
    );
    if (!$allowed) {
      throw new ScopedOperationDeniedException('The agent cannot be changed.');
    }
  }

  /**
   * Requires a workspace the current user can read.
   */
  private function assertRead(Workspace $workspace): void {
    $allowed = $this->scope->allows(
      $this->currentUser->getAccount(),
      $workspace,
      WorkspacePermission::READ,
    );
    if (!$allowed) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
  }

  /**
   * Loads the workspace stored on the agent.
   */
  private function workspaceOfAgent(Agent $agent): Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($agent->get('workspace')->getString());
    if (!$workspace instanceof Workspace) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
    return $workspace;
  }

  /**
   * Reloads an agent from storage.
   */
  private function reloadAgent(Agent $agent): Agent {
    $storage = $this->entityTypeManager->getStorage('ab_agent');
    $storage->resetCache([$agent->id()]);
    $reloaded = $storage->load($agent->id());
    if (!$reloaded instanceof Agent) {
      throw new ScopedResourceNotFoundException('Agent was not found.');
    }
    return $reloaded;
  }

  /**
   * Reloads a board from storage.
   */
  private function reloadBoard(Board $board): Board {
    $storage = $this->entityTypeManager->getStorage('ab_board');
    $storage->resetCache([$board->id()]);
    $reloaded = $storage->load($board->id());
    if (!$reloaded instanceof Board) {
      throw new ScopedResourceNotFoundException('Board was not found.');
    }
    return $reloaded;
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
   * Locks the agent row for the current transaction.
   */
  private function lockAgent(Agent $agent): void {
    $locked = $this->database->query('SELECT id FROM {ab_agent} WHERE id = :id FOR UPDATE', [
      ':id' => $agent->id(),
    ])->fetchField();
    if ($locked === FALSE) {
      throw new ScopedResourceNotFoundException('Agent was not found.');
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
   * Records a grant event for the authenticated actor.
   */
  private function record(Workspace $workspace, Agent $agent, Board $board, string $type, string $permissions): void {
    $actor = $this->actors->create($workspace);
    $this->events->record(new EventDraft(
      (string) $workspace->uuid(),
      $type,
      (string) $agent->uuid(),
      $actor->actorType,
      $actor->actorId,
      [
        'name' => (string) $agent->label(),
        'board' => (string) $board->uuid(),
        'permissions' => $permissions,
      ],
    ));
  }

}
