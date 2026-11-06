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
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Applies the pilot memory matrix from ADR 003 and ADR 004.
 *
 * Humans use ActorContext permissions. Agents use AgentOperationGate with
 * memory scopes. Workspace-only entries are human-scoped.
 */
final class MemoryAccessPolicy {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AgentOperationGate $gate,
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
      if (!$board instanceof Board) {
        throw new ScopedResourceNotFoundException();
      }
      $this->gate->assert($actor, $board, AgentTokenScope::MEMORY_WRITE);
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
      if ($board === NULL || (int) ($entry['board_id'] ?? 0) <= 0) {
        throw new ScopedResourceNotFoundException();
      }
      if ($status !== 'published') {
        throw new ScopedResourceNotFoundException();
      }
      $this->gate->assert($actor, $board, AgentTokenScope::MEMORY_READ);
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
      if ($board === NULL || (int) ($entry['board_id'] ?? 0) <= 0) {
        throw new ScopedResourceNotFoundException();
      }
      $this->gate->assert($actor, $board, AgentTokenScope::MEMORY_WRITE);
      return;
    }
    if ($actor->actorType !== ActorContext::TYPE_HUMAN || !$actor->allows(WorkspacePermission::WRITE)) {
      throw new ScopedOperationDeniedException();
    }
  }

  /**
   * Allows publishing a draft. Humans with review only.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   * @throws \Drupal\ab_memory\MemoryRejectedException
   */
  public function assertCanPublish(ActorContext $actor, array $entry): void {
    if ($actor->actorType !== ActorContext::TYPE_HUMAN) {
      throw new ScopedOperationDeniedException();
    }
    if (!$actor->allows(WorkspacePermission::REVIEW)) {
      throw new ScopedOperationDeniedException();
    }
    if ((string) $entry['status'] !== 'draft') {
      throw new MemoryRejectedException('Only a draft can be published.');
    }
  }

  /**
   * Allows archiving. Humans with review only.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   * @throws \Drupal\ab_memory\MemoryRejectedException
   */
  public function assertCanArchive(ActorContext $actor, array $entry): void {
    if ($actor->actorType !== ActorContext::TYPE_HUMAN) {
      throw new ScopedOperationDeniedException();
    }
    if (!$actor->allows(WorkspacePermission::REVIEW)) {
      throw new ScopedOperationDeniedException();
    }
    $status = (string) $entry['status'];
    if (!in_array($status, ['draft', 'published'], TRUE)) {
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
      // Agents may attach source metadata on write; entry links are human.
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

}
