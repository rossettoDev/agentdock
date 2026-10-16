<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_event\EventDraft;
use Drupal\ab_event\EventStoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Issues, rotates and revokes agent bearer tokens.
 *
 * The returned secret is the only copy. Storage keeps a SHA-256 hash.
 * Events and public fields carry the public id, never the secret.
 */
class AgentTokenManager {

  /**
   * Default lifetime of a new token, in seconds.
   */
  public const TTL = 7776000;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly MembershipScope $scope,
    private readonly HumanActorContextFactory $actors,
    private readonly EventStoreInterface $events,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Issues a token and records agent.token_issued.
   *
   * @param \Drupal\ab_agent\Entity\Agent $agent
   *   The agent that receives the credential.
   * @param string[] $scopes
   *   Requested scopes. They must be a subset of the allowed set.
   * @param int|null $expiresAt
   *   UTC expiry. NULL uses the default lifetime.
   *
   * @throws \Drupal\ab_agent\AgentTokenRejectedException
   *   A scope is unknown or the list is empty.
   */
  public function issue(Agent $agent, array $scopes, ?int $expiresAt = NULL): IssuedAgentToken {
    $workspace = $this->workspaceOf($agent);
    $this->assertCredentials($workspace);
    $storedScopes = $this->normalize($scopes);
    $expiresAt = $expiresAt ?? ($this->time->getRequestTime() + self::TTL);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $this->lockAgent($agent);
      $agent = $this->reload($agent);
      $issued = $this->insert($workspace, $agent, $storedScopes, $expiresAt);
      $this->record($workspace, $agent, 'agent.token_issued', [
        'name' => (string) $agent->label(),
        'token_id' => $issued->publicId,
        'scopes' => $storedScopes,
        'expires_at' => $expiresAt,
      ]);
      $this->afterRecord($agent);
      $this->invalidate($agent);
      return $issued;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Revokes one token and records agent.token_revoked.
   *
   * A second revoke of the same token does not write another event.
   */
  public function revoke(Agent $agent, string $publicId): void {
    $workspace = $this->workspaceOf($agent);
    $this->assertCredentials($workspace);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $this->lockAgent($agent);
      $this->lockToken($publicId);
      $agent = $this->reload($agent);
      $row = $this->rowForAgent($agent, $publicId);
      if ($row === NULL) {
        throw new ScopedResourceNotFoundException('Token was not found.');
      }
      if ($row->revoked_at !== NULL) {
        return;
      }
      $revokedAt = $this->time->getRequestTime();
      $this->database->update('ab_agent_token')
        ->fields(['revoked_at' => $revokedAt])
        ->condition('token_id', $publicId)
        ->execute();
      $this->record($workspace, $agent, 'agent.token_revoked', [
        'name' => (string) $agent->label(),
        'token_id' => $publicId,
        'scopes' => (string) $row->scopes,
        'expires_at' => (int) $row->expires_at,
      ]);
      $this->afterRecord($agent);
      $this->invalidate($agent);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Revokes the current token and issues a replacement.
   *
   * @param \Drupal\ab_agent\Entity\Agent $agent
   *   The agent that owns the current credential.
   * @param string $publicId
   *   Public id of the credential to replace.
   * @param string[] $scopes
   *   Scopes for the replacement token.
   */
  public function rotate(Agent $agent, string $publicId, array $scopes): IssuedAgentToken {
    $workspace = $this->workspaceOf($agent);
    $this->assertCredentials($workspace);
    $storedScopes = $this->normalize($scopes);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $this->lockAgent($agent);
      $this->lockToken($publicId);
      $agent = $this->reload($agent);
      $row = $this->rowForAgent($agent, $publicId);
      if ($row === NULL || $row->revoked_at !== NULL) {
        throw new ScopedResourceNotFoundException('Token was not found.');
      }
      $revokedAt = $this->time->getRequestTime();
      $this->database->update('ab_agent_token')
        ->fields(['revoked_at' => $revokedAt])
        ->condition('token_id', $publicId)
        ->execute();
      $expiresAt = $revokedAt + self::TTL;
      $issued = $this->insert($workspace, $agent, $storedScopes, $expiresAt);
      $this->record($workspace, $agent, 'agent.token_rotated', [
        'name' => (string) $agent->label(),
        'previous_token_id' => $publicId,
        'token_id' => $issued->publicId,
        'scopes' => $storedScopes,
        'expires_at' => $expiresAt,
      ]);
      $this->afterRecord($agent);
      $this->invalidate($agent);
      return $issued;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Returns the public credentials of one agent.
   *
   * @return \Drupal\ab_agent\StoredAgentToken[]
   *   Tokens ordered by creation. The hash stays inside the object.
   */
  public function list(Agent $agent): array {
    $this->assertCredentials($this->workspaceOf($agent));
    $query = $this->database->select('ab_agent_token', 't');
    $query->fields('t');
    $query->condition('agent_id', $agent->id());
    $query->orderBy('created');
    $query->orderBy('token_id');
    $tokens = [];
    foreach ($query->execute() as $row) {
      $tokens[] = $this->stored($agent, $row, (int) $agent->get('enabled')->getString() === 1);
    }
    return $tokens;
  }

  /**
   * Accepts a bearer token or returns NULL.
   *
   * Expired, revoked, unknown and disabled credentials fail closed.
   * The secret is never written to the result.
   */
  public function authenticate(string $presented): ?StoredAgentToken {
    if (preg_match('/^ab_([0-9a-f]{32})\.([0-9a-f]{64})$/', $presented, $matches) !== 1) {
      return NULL;
    }
    $loaded = $this->loadPresented($matches[1]);
    if ($loaded === NULL || !$loaded['token']->matches($matches[2])) {
      return NULL;
    }
    $token = $loaded['token'];
    $now = $this->time->getRequestTime();
    if ($token->revokedAt !== NULL || $token->expiresAt <= $now || !$loaded['enabled']) {
      return NULL;
    }
    $this->database->update('ab_agent_token')
      ->fields(['last_used_at' => $now])
      ->condition('token_id', $token->publicId)
      ->execute();
    return $token;
  }

  /**
   * Loads a token by public id for a later denial check.
   */
  public function load(string $publicId): ?StoredAgentToken {
    $loaded = $this->loadPresented($publicId);
    return $loaded === NULL ? NULL : $loaded['token'];
  }

  /**
   * Runs after the event is recorded and before the caller commits.
   */
  protected function afterRecord(Agent $agent): void {
    // Tests throw from here to prove the caller transaction rolls back.
  }

  /**
   * Sorts and checks the requested scopes.
   *
   * @param string[] $scopes
   *   Requested scopes.
   */
  private function normalize(array $scopes): string {
    $unique = array_values(array_unique($scopes));
    sort($unique);
    if ($unique === [] || array_diff($unique, AgentTokenScope::all()) !== []) {
      throw new AgentTokenRejectedException(AgentTokenRejectedException::SCOPE);
    }
    return implode(',', $unique);
  }

  /**
   * Inserts a hashed credential and returns the only copy of the secret.
   */
  private function insert(Workspace $workspace, Agent $agent, string $scopes, int $expiresAt): IssuedAgentToken {
    $publicId = bin2hex(random_bytes(16));
    $secret = bin2hex(random_bytes(32));
    $this->database->insert('ab_agent_token')->fields([
      'token_id' => $publicId,
      'workspace' => (int) $workspace->id(),
      'agent_id' => (int) $agent->id(),
      'secret_hash' => hash('sha256', $secret),
      'scopes' => $scopes,
      'expires_at' => $expiresAt,
      'created' => $this->time->getRequestTime(),
    ])->execute();
    return new IssuedAgentToken($publicId, 'ab_' . $publicId . '.' . $secret, $expiresAt);
  }

  /**
   * Loads a token row and whether its agent is enabled.
   *
   * @return array{token: \Drupal\ab_agent\StoredAgentToken, enabled: bool}|null
   *   The pair, or NULL when the public id is unknown.
   */
  private function loadPresented(string $publicId): ?array {
    $query = $this->database->select('ab_agent_token', 't');
    $query->fields('t');
    $query->addField('a', 'uuid', 'agent_uuid');
    $query->addField('w', 'uuid', 'workspace_uuid');
    $query->addField('a', 'enabled', 'agent_enabled');
    $query->join('ab_agent', 'a', 'a.id = t.agent_id');
    $query->join('ab_workspace', 'w', 'w.id = t.workspace');
    $query->condition('t.token_id', $publicId);
    $row = $query->execute()->fetchObject();
    if ($row === FALSE) {
      return NULL;
    }
    $agent = $this->entityTypeManager->getStorage('ab_agent')->load($row->agent_id);
    if (!$agent instanceof Agent) {
      return NULL;
    }
    return [
      'token' => $this->stored(
        $agent,
        $row,
        (int) $row->agent_enabled === 1,
        (string) $row->agent_uuid,
        (string) $row->workspace_uuid,
      ),
      'enabled' => (int) $row->agent_enabled === 1,
    ];
  }

  /**
   * Builds a stored token from a database row.
   */
  private function stored(
    Agent $agent,
    object $row,
    bool $agentEnabled,
    ?string $agentUuid = NULL,
    ?string $workspaceUuid = NULL,
  ): StoredAgentToken {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($agent->get('workspace')->getString());
    return new StoredAgentToken(
      (string) $row->token_id,
      $agentUuid ?? (string) $agent->uuid(),
      $workspaceUuid ?? ($workspace instanceof Workspace ? (string) $workspace->uuid() : ''),
      (string) $row->scopes,
      (int) $row->expires_at,
      $row->revoked_at === NULL ? NULL : (int) $row->revoked_at,
      $row->last_used_at === NULL ? NULL : (int) $row->last_used_at,
      $agentEnabled,
      (string) $row->secret_hash,
    );
  }

  /**
   * Reads one token row that belongs to the agent.
   */
  private function rowForAgent(Agent $agent, string $publicId): ?object {
    $query = $this->database->select('ab_agent_token', 't');
    $query->fields('t');
    $query->condition('token_id', $publicId);
    $query->condition('agent_id', $agent->id());
    $row = $query->execute()->fetchObject();
    return $row === FALSE ? NULL : $row;
  }

  /**
   * Requires permission to manage credentials in a visible workspace.
   */
  private function assertCredentials(Workspace $workspace): void {
    $readable = $this->scope->allows(
      $this->currentUser->getAccount(),
      $workspace,
      WorkspacePermission::READ,
    );
    if (!$readable) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
    $allowed = $this->scope->allows(
      $this->currentUser->getAccount(),
      $workspace,
      WorkspacePermission::MANAGE_CREDENTIALS,
    );
    if (!$allowed) {
      throw new ScopedOperationDeniedException('The token cannot be changed.');
    }
  }

  /**
   * Loads the workspace stored on the agent.
   */
  private function workspaceOf(Agent $agent): Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($agent->get('workspace')->getString());
    if (!$workspace instanceof Workspace) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
    return $workspace;
  }

  /**
   * Reloads an agent from storage.
   */
  private function reload(Agent $agent): Agent {
    $storage = $this->entityTypeManager->getStorage('ab_agent');
    $storage->resetCache([$agent->id()]);
    $reloaded = $storage->load($agent->id());
    if (!$reloaded instanceof Agent) {
      throw new ScopedResourceNotFoundException('Agent was not found.');
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
   * Locks one token row for the current transaction.
   */
  private function lockToken(string $publicId): void {
    $this->database->query('SELECT token_id FROM {ab_agent_token} WHERE token_id = :id FOR UPDATE', [
      ':id' => $publicId,
    ])->fetchField();
  }

  /**
   * Records a token event. The payload must already omit the secret.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the agent.
   * @param \Drupal\ab_agent\Entity\Agent $agent
   *   Agent the event is about.
   * @param string $type
   *   Dotted event type.
   * @param array<string, mixed> $payload
   *   Public event fields.
   */
  private function record(Workspace $workspace, Agent $agent, string $type, array $payload): void {
    $actor = $this->actors->create($workspace);
    $this->events->record(new EventDraft(
      (string) $workspace->uuid(),
      $type,
      (string) $agent->uuid(),
      $actor->actorType,
      $actor->actorId,
      $payload,
    ));
  }

  /**
   * Drops the cached token list of this agent.
   */
  private function invalidate(Agent $agent): void {
    $this->cacheTagsInvalidator->invalidateTags([
      'ab_agent_tokens:' . $agent->id(),
    ]);
  }

}
