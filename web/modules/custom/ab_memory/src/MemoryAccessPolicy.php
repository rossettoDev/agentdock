<?php

declare(strict_types=1);

namespace Drupal\ab_memory;

use Drupal\ab_agent\AgentOperationGate;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Applies the pilot memory matrix from ADR 003 and ADR 004.
 *
 * Agents follow Catalog::visible(): missing board read grant is 404 before
 * AgentOperationGate (which alone would be 403 and confirm the resource).
 */
final class MemoryAccessPolicy {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AgentOperationGate $gate,
    private readonly Connection $database,
  ) {}

  /**
   * Allows creating a draft.
   *
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   Authenticated actor.
   * @param \Drupal\ab_board\Entity\Board|null $board
   *   Board for agent drafts; optional for humans.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   * @throws \Drupal\ab_agent\AgentAccessDeniedException
   */
  public function assertCanCreate(ActorContext $actor, ?Board $board): void {
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      $visible = $this->requireVisibleBoard($actor, $board);
      $this->gate->assert($actor, $visible, AgentTokenScope::MEMORY_WRITE);
      return;
    }
    if ($actor->actorType !== ActorContext::TYPE_HUMAN || !$actor->allows(WorkspacePermission::WRITE)) {
      throw new ScopedOperationDeniedException();
    }
  }

  /**
   * Allows reading an entry the caller already resolved in-workspace.
   *
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   Authenticated actor.
   * @param array<string, mixed> $entry
   *   Locked or loaded entry row with board_id and status.
   * @param \Drupal\ab_board\Entity\Board|null $board
   *   Board of the entry when present.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   * @throws \Drupal\ab_agent\AgentAccessDeniedException
   */
  public function assertCanRead(ActorContext $actor, array $entry, ?Board $board): void {
    $status = (string) $entry['status'];
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      if ($status === 'draft') {
        throw new ScopedResourceNotFoundException();
      }
      if (!in_array($status, ['published', 'archived'], TRUE)) {
        throw new ScopedResourceNotFoundException();
      }
      $visible = $this->requireVisibleBoard($actor, $board);
      $this->gate->assert($actor, $visible, AgentTokenScope::MEMORY_READ);
      return;
    }
    if ($actor->actorType !== ActorContext::TYPE_HUMAN || !$actor->allows(WorkspacePermission::READ)) {
      throw new ScopedOperationDeniedException();
    }
    if ($status === 'draft' && !$actor->allows(WorkspacePermission::REVIEW)) {
      // Own-draft read with write is allowed later via author check in service.
      if (!$actor->allows(WorkspacePermission::WRITE)) {
        throw new ScopedOperationDeniedException();
      }
    }
  }

  /**
   * Allows appending a draft revision.
   *
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   Authenticated actor.
   * @param array<string, mixed> $entry
   *   Entry row.
   * @param \Drupal\ab_board\Entity\Board|null $board
   *   Board of the entry when present.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   * @throws \Drupal\ab_agent\AgentAccessDeniedException
   * @throws \Drupal\ab_memory\MemoryRejectedException
   */
  public function assertCanRevise(ActorContext $actor, array $entry, ?Board $board): void {
    if ((string) $entry['status'] !== 'draft') {
      throw new MemoryRejectedException('Only draft memory can be revised.');
    }
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      $visible = $this->requireVisibleBoard($actor, $board);
      $this->gate->assert($actor, $visible, AgentTokenScope::MEMORY_WRITE);
      return;
    }
    if ($actor->actorType !== ActorContext::TYPE_HUMAN || !$actor->allows(WorkspacePermission::WRITE)) {
      throw new ScopedOperationDeniedException();
    }
  }

  /**
   * Allows publishing a draft. Humans with review only.
   *
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   Authenticated actor.
   * @param array<string, mixed> $entry
   *   Entry row.
   * @param \Drupal\ab_board\Entity\Board|null $board
   *   Board of the entry when present.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   * @throws \Drupal\ab_memory\MemoryRejectedException
   */
  public function assertCanPublish(ActorContext $actor, array $entry, ?Board $board): void {
    $this->assertAgentCannotMutateStatus($actor, $board);
    if ($actor->actorType !== ActorContext::TYPE_HUMAN || !$actor->allows(WorkspacePermission::REVIEW)) {
      throw new ScopedOperationDeniedException();
    }
    if ((string) $entry['status'] !== 'draft') {
      throw new MemoryRejectedException('Only a draft can be published.');
    }
  }

  /**
   * Allows archiving. Humans with review only.
   *
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   Authenticated actor.
   * @param array<string, mixed> $entry
   *   Entry row.
   * @param \Drupal\ab_board\Entity\Board|null $board
   *   Board of the entry when present.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   * @throws \Drupal\ab_memory\MemoryRejectedException
   */
  public function assertCanArchive(ActorContext $actor, array $entry, ?Board $board): void {
    $this->assertAgentCannotMutateStatus($actor, $board);
    if ($actor->actorType !== ActorContext::TYPE_HUMAN || !$actor->allows(WorkspacePermission::REVIEW)) {
      throw new ScopedOperationDeniedException();
    }
    if (!in_array((string) $entry['status'], ['draft', 'published'], TRUE)) {
      throw new MemoryRejectedException('Only draft or published memory can be archived.');
    }
  }

  /**
   * Allows linking two entries the caller can revise in the workspace.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   */
  public function assertCanLink(ActorContext $actor): void {
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      throw new ScopedOperationDeniedException();
    }
    if ($actor->actorType !== ActorContext::TYPE_HUMAN || !$actor->allows(WorkspacePermission::WRITE)) {
      throw new ScopedOperationDeniedException();
    }
  }

  /**
   * Loads a board entity by internal id when present.
   */
  public function boardById(?int $boardId): ?Board {
    if ($boardId === NULL || $boardId <= 0) {
      return NULL;
    }
    $board = $this->entityTypeManager->getStorage('ab_board')->load($boardId);
    return $board instanceof Board ? $board : NULL;
  }

  /**
   * Board ids the agent may read in one workspace.
   *
   * @return int[]
   *   Internal board ids with a read grant.
   */
  public function readableBoardIds(ActorContext $actor, int $workspaceId): array {
    if ($actor->actorType !== ActorContext::TYPE_AGENT) {
      return [];
    }
    $ids = $this->database->query(
      'SELECT g.board_id FROM {ab_agent_board_grant} g INNER JOIN {ab_agent} a ON a.id = g.agent_id INNER JOIN {ab_board} b ON b.id = g.board_id WHERE a.uuid = :agent AND b.workspace = :workspace',
      [
        ':agent' => $actor->actorId,
        ':workspace' => $workspaceId,
      ],
    )->fetchCol();
    $readable = [];
    foreach ($ids as $boardId) {
      $stored = $this->grantPermissions($actor->actorId, (int) $boardId);
      if ($stored !== NULL && in_array('read', $stored, TRUE)) {
        $readable[] = (int) $boardId;
      }
    }
    return $readable;
  }

  /**
   * Agents never publish/archive; invisible entries stay 404.
   *
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   Authenticated actor.
   * @param \Drupal\ab_board\Entity\Board|null $board
   *   Board of the entry when present.
   */
  private function assertAgentCannotMutateStatus(ActorContext $actor, ?Board $board): void {
    if ($actor->actorType !== ActorContext::TYPE_AGENT) {
      return;
    }
    $this->requireVisibleBoard($actor, $board);
    throw new ScopedOperationDeniedException();
  }

  /**
   * Requires a board the agent can read before consulting the gate.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   */
  private function requireVisibleBoard(ActorContext $actor, ?Board $board): Board {
    if (!$board instanceof Board) {
      throw new ScopedResourceNotFoundException();
    }
    $permissions = $this->grantPermissions($actor->actorId, (int) $board->id());
    if ($permissions === NULL || !in_array('read', $permissions, TRUE)) {
      throw new ScopedResourceNotFoundException();
    }
    return $board;
  }

  /**
   * Loads stored grant permissions for one agent and board.
   *
   * @return string[]|null
   *   Grant permissions, or NULL when no grant exists.
   */
  private function grantPermissions(string $agentUuid, int $boardId): ?array {
    $stored = $this->database->query(
      'SELECT g.permissions FROM {ab_agent_board_grant} g INNER JOIN {ab_agent} a ON a.id = g.agent_id WHERE a.uuid = :agent AND g.board_id = :board',
      [
        ':agent' => $agentUuid,
        ':board' => $boardId,
      ],
    )->fetchField();
    if ($stored === FALSE) {
      return NULL;
    }
    return explode(',', (string) $stored);
  }

}
