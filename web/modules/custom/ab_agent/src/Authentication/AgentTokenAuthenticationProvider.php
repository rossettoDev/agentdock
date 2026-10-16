<?php

declare(strict_types=1);

namespace Drupal\ab_agent\Authentication;

use Drupal\ab_agent\AgentRuntimeAccount;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates a bearer token from the Authorization header.
 *
 * The query string is ignored. A payload cannot choose the agent:
 * the actor attribute is filled only after the hash matches.
 */
final class AgentTokenAuthenticationProvider implements AuthenticationProviderInterface {

  public function __construct(
    private readonly AgentTokenManager $tokens,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request) {
    $header = $request->headers->get('Authorization');
    if (!is_string($header)) {
      return FALSE;
    }
    return preg_match('/^Bearer ab_[0-9a-f]{32}\.[0-9a-f]{64}$/', $header) === 1;
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request) {
    if (!$this->applies($request)) {
      return NULL;
    }
    $presented = substr((string) $request->headers->get('Authorization'), 7);
    $token = $this->tokens->authenticate($presented);
    if ($token === NULL) {
      return NULL;
    }
    $request->attributes->set('ab_agent_actor', [
      'agent_uuid' => $token->agentUuid,
      'workspace_uuid' => $token->workspaceUuid,
      'token_id' => $token->publicId,
    ]);
    return AgentRuntimeAccount::ensure();
  }

}
