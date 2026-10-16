<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

use Drupal\ab_core\Authorization\ActorContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the agent actor from the authentication attribute.
 *
 * Request parameters named agent_id are ignored.
 */
final class AgentActorContextFactory {

  /**
   * Creates the actor stored by the bearer provider.
   */
  public function fromRequest(Request $request): ?ActorContext {
    $bag = $request->attributes->get('ab_agent_actor');
    if (!is_array($bag)) {
      return NULL;
    }
    $agentUuid = $bag['agent_uuid'] ?? '';
    $workspaceUuid = $bag['workspace_uuid'] ?? '';
    $tokenId = $bag['token_id'] ?? '';
    if (!is_string($agentUuid) || !is_string($workspaceUuid) || !is_string($tokenId)) {
      return NULL;
    }
    return new ActorContext(
      ActorContext::TYPE_AGENT,
      $agentUuid,
      $workspaceUuid,
      [],
      $tokenId,
    );
  }

}
