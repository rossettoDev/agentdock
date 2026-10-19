<?php

declare(strict_types=1);

namespace Drupal\ab_api\Http;

use Drupal\ab_agent\AgentAccessDeniedException;
use Drupal\ab_agent\AgentActorContextFactory;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\StoredAgentToken;
use Drupal\ab_api\ApiException;
use Drupal\ab_api\ProblemDetails;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\UuidLookup;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the caller of an API request.
 *
 * The actor comes from the bearer or the session. A payload cannot
 * choose it. Humans must send the session CSRF token.
 */
final class ApiCaller {

  public function __construct(
    private readonly AgentActorContextFactory $agents,
    private readonly HumanActorContextFactory $humans,
    private readonly AgentTokenManager $tokens,
    private readonly UuidLookup $uuids,
    private readonly AccountProxyInterface $currentUser,
    private readonly CsrfTokenGenerator $csrf,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns the agent actor, or NULL when the request is a session.
   */
  public function agent(Request $request): ?ActorContext {
    return $this->agents->fromRequest($request);
  }

  /**
   * Requires a credential and returns the actor for one workspace.
   */
  public function actor(Request $request, string $workspaceUuid): ActorContext {
    $agent = $this->agent($request);
    if ($agent instanceof ActorContext) {
      $this->assertToken($agent);
      if ($agent->workspaceId !== $workspaceUuid) {
        throw new ScopedResourceNotFoundException('Workspace was not found.');
      }
      return $agent;
    }
    $this->assertHuman();
    $workspace = $this->workspace($workspaceUuid);
    $actor = $this->humans->create($workspace);
    if (!$actor->allows(WorkspacePermission::READ)) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
    return $actor;
  }

  /**
   * Requires a logged-in human.
   */
  public function assertHuman(): void {
    if ($this->currentUser->isAnonymous()) {
      throw new ApiException(401, 'invalid_credential', ProblemDetails::fixedDetail(401));
    }
  }

  /**
   * Requires the session CSRF token on a human mutation.
   */
  public function assertCsrf(Request $request): void {
    if ($this->agent($request) instanceof ActorContext) {
      return;
    }
    $header = $request->headers->get('X-CSRF-Token');
    $key = CsrfRequestHeaderAccessCheck::TOKEN_KEY;
    $valid = is_string($header) && $this->csrf->validate($header, $key);
    if (!$valid) {
      throw new ApiException(403, 'csrf', 'The CSRF token is missing.');
    }
  }

  /**
   * Reads the version the client claims to have seen.
   */
  public function ifMatch(Request $request): int {
    $header = $request->headers->get('If-Match');
    if (!is_string($header)) {
      throw new ApiException(428, 'precondition_required', 'If-Match is required.');
    }
    $value = trim($header);
    if (str_starts_with($value, 'W/')) {
      throw new ApiException(428, 'precondition_required', 'If-Match is required.');
    }
    $value = trim($value, '"');
    if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
      throw new ApiException(428, 'precondition_required', 'If-Match is required.');
    }
    return (int) $value;
  }

  /**
   * Requires a token scope. Humans are checked by the caller.
   */
  public function assertScope(ActorContext $actor, string $scope): void {
    if ($actor->actorType !== ActorContext::TYPE_AGENT) {
      return;
    }
    $token = is_string($actor->tokenId) ? $this->tokens->load($actor->tokenId) : NULL;
    if (!$token instanceof StoredAgentToken || !$token->hasScope($scope)) {
      throw new AgentAccessDeniedException();
    }
  }

  /**
   * Loads a workspace by its public id.
   */
  public function workspace(string $uuid): Workspace {
    try {
      $workspace = $this->uuids->load('ab_workspace', $uuid);
    }
    catch (\InvalidArgumentException) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
    if (!$workspace instanceof Workspace) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
    return $workspace;
  }

  /**
   * Refuses a token that can no longer be used.
   */
  private function assertToken(ActorContext $actor): void {
    $token = is_string($actor->tokenId) ? $this->tokens->load($actor->tokenId) : NULL;
    $now = $this->time->getRequestTime();
    $usable = $token instanceof StoredAgentToken
      && $token->agentUuid === $actor->actorId
      && $token->workspaceUuid === $actor->workspaceId
      && $token->revokedAt === NULL
      && $token->expiresAt > $now
      && $token->agentEnabled;
    if (!$usable) {
      throw new ApiException(401, 'invalid_credential', ProblemDetails::fixedDetail(401));
    }
  }

}
