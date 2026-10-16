<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_agent\Kernel;

use Drupal\ab_agent\AgentAccessDeniedException;
use Drupal\ab_agent\AgentActorContextFactory;
use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentOperationGate;
use Drupal\ab_agent\AgentRuntimeAccount;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenRejectedException;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_agent\Authentication\AgentTokenAuthenticationProvider;
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_board\BoardManager;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserAuthInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies a bearer token stays hashed and cannot widen its grant.
 *
 * @group ab_agent
 */
class AgentTokenKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'options',
    'ab_core',
    'ab_event',
    'ab_board',
    'ab_agent',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('ab_workspace');
    $this->installEntitySchema('ab_membership');
    $this->installEntitySchema('ab_board');
    $this->installEntitySchema('ab_agent');
    $this->installSchema('ab_event', [
      'ab_workspace_sequence',
      'ab_event',
      'ab_outbox',
    ]);
    $this->installSchema('ab_agent', [
      'ab_agent_board_grant',
      'ab_agent_token',
    ]);
  }

  /**
   * Issue, rotate and revoke without storing or logging the secret.
   */
  public function testSecretIsStoredAsHash(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $admin = $this->createUserAccount('admin', 'admin@example.com');
    $viewer = $this->createUserAccount('view', 'view@example.com');
    $outsider = $this->createUserAccount('out', 'out@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->membership($workspace, $admin, 'admin');
    $this->membership($workspace, $viewer, 'viewer');
    $this->setCurrentUser($ada);
    $agent = $this->agents()->create($workspace, 'Cursor', '', '', TRUE, 1);

    try {
      $this->tokens()->issue($agent, []);
      $this->fail('An empty scope list was stored.');
    }
    catch (AgentTokenRejectedException) {
      $this->assertSame([], $this->tokenRows());
    }
    try {
      $this->tokens()->issue($agent, ['agent.manage']);
      $this->fail('A workspace permission was stored as a scope.');
    }
    catch (AgentTokenRejectedException) {
      $this->assertSame([], $this->tokenRows());
    }

    $issued = $this->tokens()->issue($agent, [
      AgentTokenScope::TASKS_WRITE,
      AgentTokenScope::TASKS_READ,
    ]);
    $secret = substr($issued->token, 36);
    $this->assertSame('ab_' . $issued->publicId . '.' . $secret, $issued->token);
    $this->assertSame(64, strlen($secret));
    $rows = $this->tokenRows();
    $this->assertCount(1, $rows);
    $this->assertSame(hash('sha256', $secret), $rows[0]['secret_hash']);
    $this->assertStringNotContainsString($secret, json_encode($rows));
    $this->assertSame('tasks:read,tasks:write', $rows[0]['scopes']);
    $events = $this->events();
    $last = $events[array_key_last($events)];
    $this->assertSame('agent.token_issued', $last['type']);
    $this->assertStringNotContainsString($secret, (string) $last['payload']);
    $payload = json_decode((string) $last['payload'], TRUE);
    $this->assertSame($issued->publicId, $payload['token_id']);

    $accepted = $this->tokens()->authenticate($issued->token);
    $this->assertNotNull($accepted);
    $this->assertNotNull($this->tokenRows()[0]['last_used_at']);
    $this->assertNull($this->tokens()->authenticate('ab_' . $issued->publicId . '.' . str_repeat('a', 64)));

    $this->setCurrentUser($admin);
    try {
      $this->tokens()->issue($agent, [AgentTokenScope::TASKS_READ]);
      $this->fail('An admin issued a token.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertCount(1, $this->tokenRows());
    }
    $this->setCurrentUser($viewer);
    try {
      $this->tokens()->revoke($agent, $issued->publicId);
      $this->fail('A viewer revoked a token.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertNull($this->tokenRows()[0]['revoked_at']);
    }
    $this->setCurrentUser($outsider);
    try {
      $this->tokens()->list($agent);
      $this->fail('An outsider listed tokens.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertCount(1, $this->tokenRows());
    }

    $this->setCurrentUser($ada);
    $rotated = $this->tokens()->rotate($agent, $issued->publicId, [AgentTokenScope::TASKS_READ]);
    $rotatedSecret = substr($rotated->token, 36);
    $this->assertNull($this->tokens()->authenticate($issued->token));
    $this->assertNotNull($this->tokens()->authenticate($rotated->token));
    $rotatedEvents = $this->events();
    $this->assertSame('agent.token_rotated', $rotatedEvents[array_key_last($rotatedEvents)]['type']);
    $this->assertStringNotContainsString($rotatedSecret, (string) $rotatedEvents[array_key_last($rotatedEvents)]['payload']);
    $this->assertStringNotContainsString($secret, json_encode($this->tokenRows()));
    $this->assertStringNotContainsString($rotatedSecret, json_encode($this->tokenRows()));

    $before = count($this->events());
    $this->tokens()->revoke($agent, $rotated->publicId);
    $this->tokens()->revoke($agent, $rotated->publicId);
    $this->assertCount($before + 1, $this->events());
    $this->assertNull($this->tokens()->authenticate($rotated->token));

    $account = AgentRuntimeAccount::ensure();
    $this->assertTrue($account->isBlocked());
    $this->assertSame(AgentRuntimeAccount::NAME, $account->getAccountName());
    $auth = $this->container->get('user.auth');
    $this->assertInstanceOf(UserAuthInterface::class, $auth);
    $this->assertFalse($auth->authenticate(AgentRuntimeAccount::NAME, $rotatedSecret));
  }

  /**
   * Bearer identity ignores the payload, and bad credentials deny access.
   */
  public function testBearerCannotForgeAnotherAgent(): void {
    $ada = $this->createUserAccount('ada2', 'ada2@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Token', 'America/Sao_Paulo');
    $other = $this->creator()->create($ada, 'Casa Longe', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $foreign = $this->boards()->create($other, 'Quadro longe', 'Fora');
    $agent = $this->agents()->create($workspace, 'Cursor', '', '', TRUE, 1);
    $decoy = $this->agents()->create($workspace, 'Outro', '', '', TRUE, 1);
    $this->grants()->grant($agent, $board, ['read']);
    $issued = $this->tokens()->issue($agent, [AgentTokenScope::TASKS_READ]);
    $secret = substr($issued->token, 36);

    $query = Request::create('/agents?token=' . $issued->token . '&agent_id=' . $decoy->uuid());
    $this->assertFalse($this->provider()->applies($query));
    $this->assertNull($this->provider()->authenticate($query));
    $this->assertNull($this->actors()->fromRequest($query));

    $request = Request::create('/agents', 'POST', ['agent_id' => $decoy->uuid()]);
    $request->headers->set('Authorization', 'Bearer ' . $issued->token);
    $request->query->set('agent_id', $decoy->uuid());
    $this->assertTrue($this->provider()->applies($request));
    $account = $this->provider()->authenticate($request);
    $this->assertNotNull($account);
    $this->assertSame(AgentRuntimeAccount::NAME, $account->getAccountName());
    $actor = $this->actors()->fromRequest($request);
    $this->assertInstanceOf(ActorContext::class, $actor);
    $this->assertSame((string) $agent->uuid(), $actor->actorId);
    $this->assertNotSame((string) $decoy->uuid(), $actor->actorId);
    $this->assertNotSame((string) $account->id(), $actor->actorId);
    $this->assertSame($issued->publicId, $actor->tokenId);
    $this->gate()->assert($actor, $board, AgentTokenScope::TASKS_READ);

    try {
      $this->gate()->assert($actor, $board, AgentTokenScope::TASKS_WRITE);
      $this->fail('A missing scope was allowed.');
    }
    catch (AgentAccessDeniedException $exception) {
      $this->assertStringNotContainsString($secret, $exception->getMessage());
    }
    try {
      $this->gate()->assert($actor, $foreign, AgentTokenScope::TASKS_READ);
      $this->fail('A foreign board was allowed.');
    }
    catch (AgentAccessDeniedException) {
      $this->assertSame((string) $other->id(), $foreign->get('workspace')->getString());
    }

    $past = $this->tokens()->issue($agent, [AgentTokenScope::TASKS_READ], $this->now() - 10);
    $this->assertNull($this->tokens()->authenticate($past->token));
    try {
      $this->gate()->assert($this->actorFor($agent, $workspace, $past->publicId), $board, AgentTokenScope::TASKS_READ);
      $this->fail('An expired token was allowed.');
    }
    catch (AgentAccessDeniedException) {
      $this->assertStringNotContainsString(substr($past->token, 36), json_encode($this->tokenRows()));
    }

    $this->tokens()->revoke($agent, $issued->publicId);
    try {
      $this->gate()->assert($actor, $board, AgentTokenScope::TASKS_READ);
      $this->fail('A revoked token was allowed.');
    }
    catch (AgentAccessDeniedException) {
      $this->assertNull($this->tokens()->authenticate($issued->token));
    }

    $disabledAgent = $this->agents()->update($agent, 'Cursor', '', '', FALSE, 1);
    $disabled = $this->tokens()->issue($disabledAgent, [AgentTokenScope::TASKS_READ]);
    $this->assertNull($this->tokens()->authenticate($disabled->token));
    try {
      $this->gate()->assert($this->actorFor($disabledAgent, $workspace, $disabled->publicId), $board, AgentTokenScope::TASKS_READ);
      $this->fail('A disabled agent was allowed.');
    }
    catch (AgentAccessDeniedException $exception) {
      $this->assertStringNotContainsString(substr($disabled->token, 36), $exception->getMessage());
    }
  }

  /**
   * A failed record leaves no token row and no secret behind.
   */
  public function testFailedIssueRollsBack(): void {
    $ada = $this->createUserAccount('ada3', 'ada3@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Falha', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $agent = $this->agents()->create($workspace, 'Cursor', '', '', TRUE, 1);
    $failing = new class (
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get('Drupal\ab_core\Authorization\MembershipScope'),
      $this->container->get('Drupal\ab_core\Authorization\HumanActorContextFactory'),
      $this->container->get('Drupal\ab_event\EventStoreInterface'),
      $this->container->get('datetime.time'),
      $this->container->get('cache_tags.invalidator'),
    ) extends AgentTokenManager {

      /**
       * {@inheritdoc}
       */
      protected function afterRecord(Agent $agent): void {
        throw new \RuntimeException('stop');
      }

    };
    try {
      $failing->issue($agent, [AgentTokenScope::TASKS_READ]);
      $this->fail('A failed token was stored.');
    }
    catch (\RuntimeException) {
      $this->assertSame([], $this->tokenRows());
    }
  }

  /**
   * Creates an active user.
   */
  private function createUserAccount(string $name, string $mail): User {
    $user = User::create([
      'name' => $name,
      'mail' => $mail,
      'status' => 1,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Adds an active membership.
   */
  private function membership(Workspace $workspace, User $account, string $role): void {
    $membership = Membership::create([
      'workspace' => $workspace->id(),
      'user' => $account->id(),
      'role' => $role,
      'status' => 'active',
    ]);
    $membership->save();
  }

  /**
   * Loads the workspace creator.
   */
  private function creator(): WorkspaceCreator {
    $creator = $this->container->get(WorkspaceCreator::class);
    $this->assertInstanceOf(WorkspaceCreator::class, $creator);
    return $creator;
  }

  /**
   * Loads the agent manager.
   */
  private function agents(): AgentManager {
    $manager = $this->container->get(AgentManager::class);
    $this->assertInstanceOf(AgentManager::class, $manager);
    return $manager;
  }

  /**
   * Loads the token manager.
   */
  private function tokens(): AgentTokenManager {
    $manager = $this->container->get(AgentTokenManager::class);
    $this->assertInstanceOf(AgentTokenManager::class, $manager);
    return $manager;
  }

  /**
   * Loads the grant manager.
   */
  private function grants(): AgentGrantManager {
    $manager = $this->container->get(AgentGrantManager::class);
    $this->assertInstanceOf(AgentGrantManager::class, $manager);
    return $manager;
  }

  /**
   * Loads the board manager.
   */
  private function boards(): BoardManager {
    $manager = $this->container->get(BoardManager::class);
    $this->assertInstanceOf(BoardManager::class, $manager);
    return $manager;
  }

  /**
   * Loads the bearer provider.
   */
  private function provider(): AgentTokenAuthenticationProvider {
    $provider = $this->container->get('ab_agent.authentication.agent_token');
    $this->assertInstanceOf(AgentTokenAuthenticationProvider::class, $provider);
    return $provider;
  }

  /**
   * Loads the agent actor factory.
   */
  private function actors(): AgentActorContextFactory {
    $factory = $this->container->get(AgentActorContextFactory::class);
    $this->assertInstanceOf(AgentActorContextFactory::class, $factory);
    return $factory;
  }

  /**
   * Loads the operation gate.
   */
  private function gate(): AgentOperationGate {
    $gate = $this->container->get(AgentOperationGate::class);
    $this->assertInstanceOf(AgentOperationGate::class, $gate);
    return $gate;
  }

  /**
   * Builds an actor without trusting a request payload.
   */
  private function actorFor(Agent $agent, Workspace $workspace, string $publicId): ActorContext {
    return new ActorContext(
      ActorContext::TYPE_AGENT,
      (string) $agent->uuid(),
      (string) $workspace->uuid(),
      [],
      $publicId,
    );
  }

  /**
   * Authenticates the account.
   */
  private function setCurrentUser(User $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Returns the test request time.
   */
  private function now(): int {
    return $this->container->get('datetime.time')->getRequestTime();
  }

  /**
   * Returns stored token rows.
   *
   * @return array<int, array<string, mixed>>
   *   Token rows.
   */
  private function tokenRows(): array {
    $rows = $this->container->get('database')->select('ab_agent_token', 't')
      ->fields('t')
      ->orderBy('created')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    return array_values($rows);
  }

  /**
   * Returns stored events in sequence order.
   *
   * @return array<int, array<string, mixed>>
   *   Event rows.
   */
  private function events(): array {
    $rows = $this->container->get('database')->select('ab_event', 'e')
      ->fields('e')
      ->orderBy('sequence')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    return array_values($rows);
  }

}
