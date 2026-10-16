<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Intersects token scope, board grant and workspace.
 *
 * The Drupal account of the request is not consulted. A disabled,
 * expired or revoked credential denies the operation.
 */
final class AgentOperationGate {

  public function __construct(
    private readonly AgentTokenManager $tokens,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Refuses the operation when the intersection does not allow it.
   *
   * @throws \Drupal\ab_agent\AgentAccessDeniedException
   *   The token, agent, grant or board does not allow the scope.
   */
  public function assert(ActorContext $actor, Board $board, string $scope): void {
    $tokenId = $actor->tokenId;
    $token = is_string($tokenId) ? $this->tokens->load($tokenId) : NULL;
    $knownScope = in_array($scope, AgentTokenScope::all(), TRUE);
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($board->get('workspace')->getString());
    $sameWorkspace = $workspace instanceof Workspace && (string) $workspace->uuid() === $actor->workspaceId;
    $now = $this->time->getRequestTime();
    $usable = $actor->actorType === ActorContext::TYPE_AGENT
      && $token instanceof StoredAgentToken
      && $token->agentUuid === $actor->actorId
      && $token->workspaceUuid === $actor->workspaceId
      && $token->revokedAt === NULL
      && $token->expiresAt > $now
      && $token->agentEnabled
      && $knownScope
      && $token->hasScope($scope)
      && $sameWorkspace
      && $this->grantAllows($actor->actorId, $board, AgentTokenScope::grantFor($scope));
    if (!$usable) {
      throw new AgentAccessDeniedException();
    }
  }

  /**
   * Reports whether the agent has the grant permission on this board.
   */
  private function grantAllows(string $agentUuid, Board $board, string $permission): bool {
    $query = $this->database->select('ab_agent_board_grant', 'g');
    $query->addField('g', 'permissions');
    $query->join('ab_agent', 'a', 'a.id = g.agent_id');
    $query->condition('a.uuid', $agentUuid);
    $query->condition('g.board_id', $board->id());
    $stored = $query->execute()->fetchField();
    if ($stored === FALSE) {
      return FALSE;
    }
    return in_array($permission, explode(',', (string) $stored), TRUE);
  }

}
