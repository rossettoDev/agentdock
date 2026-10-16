<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_agent\Kernel;

use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentGrantRejectedException;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentUnavailableException;
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_board\BoardManager;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies an agent stays in its workspace and its grants stay on its boards.
 *
 * @group ab_agent
 */
class AgentKernelTest extends KernelTestBase {

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
    $this->installSchema('ab_agent', ['ab_agent_board_grant']);
  }

  /**
   * Create, disable, and reject a bad limit or a workspace move.
   */
  public function testAgentStaysInTheWorkspace(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $viewer = $this->createUserAccount('view', 'view@example.com');
    $member = $this->createUserAccount('member', 'member@example.com');
    $admin = $this->createUserAccount('admin', 'admin@example.com');
    $outsider = $this->createUserAccount('out', 'out@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $other = $this->creator()->create($ada, 'Casa Outra', 'America/Sao_Paulo');
    $this->membership($workspace, $viewer, 'viewer');
    $this->membership($workspace, $member, 'member');
    $this->membership($workspace, $admin, 'admin');

    $this->setCurrentUser($ada);
    try {
      $this->agents()->create($workspace, '  ', '', '', TRUE, 1);
      $this->fail('An empty name was stored.');
    }
    catch (\InvalidArgumentException) {
      $this->assertSame([], $this->agentIds());
    }
    try {
      $this->agents()->create($workspace, 'Cursor', '', '', TRUE, 0);
      $this->fail('A zero limit was stored.');
    }
    catch (\InvalidArgumentException) {
      $this->assertSame([], $this->agentIds());
    }

    $created = $this->agents()->create($workspace, ' Cursor local ', 'Texto', 'Revisão', TRUE, 2);
    $this->assertSame('Cursor local', (string) $created->label());
    $this->assertSame('Texto', $created->get('description')->getString());
    $this->assertSame('Revisão', $created->get('capabilities')->getString());
    $this->assertSame('1', $created->get('enabled')->getString());
    $this->assertSame('2', $created->get('concurrency_limit')->getString());
    $this->assertSame((string) $workspace->id(), $created->get('workspace')->getString());
    $events = $this->events();
    $last = $events[array_key_last($events)];
    $this->assertSame('agent.created', $last['type']);
    $this->assertSame((string) $created->uuid(), $last['resource_id']);
    $payload = json_decode((string) $last['payload'], TRUE);
    $this->assertTrue($payload['enabled']);
    $this->assertSame(2, $payload['concurrency_limit']);

    $disabled = $this->agents()->update($created, 'Cursor local', 'Texto', 'Revisão', FALSE, 2);
    $this->assertNotSame(1, (int) $disabled->get('enabled')->getString());
    try {
      $this->agents()->assertCanStartWork($disabled);
      $this->fail('A disabled agent started work.');
    }
    catch (AgentUnavailableException) {
      $stored = (int) $this->reload($disabled)->get('enabled')->getString();
      $this->assertNotSame(1, $stored);
    }
    $enabled = $this->agents()->update($disabled, 'Cursor local', 'Texto', 'Revisão', TRUE, 1);
    $this->agents()->assertCanStartWork($enabled);

    $enabled->set('workspace', $other->id());
    try {
      $enabled->save();
      $this->fail('An agent moved to another workspace.');
    }
    catch (EntityStorageException $exception) {
      $this->assertSame('An agent cannot move to another workspace.', $exception->getMessage());
      $this->assertSame((string) $workspace->id(), $this->reload($enabled)->get('workspace')->getString());
    }
    $enabled = $this->reload($enabled);

    $this->setCurrentUser($viewer);
    try {
      $this->agents()->create($workspace, 'Outro', '', '', TRUE, 1);
      $this->fail('A viewer created an agent.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertCount(1, $this->agentIds());
    }
    $this->setCurrentUser($member);
    try {
      $this->agents()->update($enabled, 'Trocado', '', '', TRUE, 1);
      $this->fail('A member updated an agent.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame('Cursor local', (string) $this->reload($enabled)->label());
    }
    $this->setCurrentUser($outsider);
    try {
      $this->agents()->assertCanStartWork($enabled);
      $this->fail('An outsider started work.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertSame('1', $this->reload($enabled)->get('enabled')->getString());
    }

    $this->setCurrentUser($admin);
    $by_admin = $this->agents()->create($workspace, 'Agente admin', '', '', TRUE, 1);
    $this->assertSame((string) $workspace->id(), $by_admin->get('workspace')->getString());
  }

  /**
   * Grants stay on boards of the same workspace.
   */
  public function testGrantStaysOnTheAgentBoard(): void {
    $ada = $this->createUserAccount('ada2', 'ada2@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Grant', 'America/Sao_Paulo');
    $other = $this->creator()->create($ada, 'Casa Longe', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $foreign = $this->boards()->create($other, 'Quadro longe', 'Fora');
    $agent = $this->agents()->create($workspace, 'Cursor', '', '', TRUE, 1);
    $before = count($this->events());

    $this->grants()->grant($agent, $board, ['write', 'read']);
    $rows = $this->grantRows();
    $this->assertCount(1, $rows);
    $this->assertSame('read,write', $rows[0]['permissions']);
    $this->assertSame((string) $workspace->id(), $rows[0]['workspace']);
    $added = $this->events();
    $this->assertSame('agent.grant_added', $added[array_key_last($added)]['type']);
    $payload = json_decode((string) $added[array_key_last($added)]['payload'], TRUE);
    $this->assertSame((string) $board->uuid(), $payload['board']);
    $this->assertSame('read,write', $payload['permissions']);

    $this->grants()->grant($agent, $board, ['read', 'write']);
    $this->assertCount(count($added), $this->events());

    $this->grants()->grant($agent, $board, ['review']);
    $updated = $this->events();
    $this->assertSame('agent.grant_updated', $updated[array_key_last($updated)]['type']);
    $this->assertSame('review', $this->grantRows()[0]['permissions']);

    try {
      $this->grants()->grant($agent, $foreign, ['read']);
      $this->fail('A foreign board received a grant.');
    }
    catch (AgentGrantRejectedException $exception) {
      $this->assertSame(AgentGrantRejectedException::BOARD, $exception->getMessage());
      $this->assertCount(1, $this->grantRows());
    }
    try {
      $this->grants()->grant($agent, $board, ['agent.manage']);
      $this->fail('A workspace permission was granted.');
    }
    catch (AgentGrantRejectedException $exception) {
      $this->assertSame(AgentGrantRejectedException::PERMISSION, $exception->getMessage());
      $this->assertSame('review', $this->grantRows()[0]['permissions']);
    }
    try {
      $this->grants()->grant($agent, $board, []);
      $this->fail('An empty grant was stored.');
    }
    catch (AgentGrantRejectedException) {
      $this->assertCount(1, $this->grantRows());
    }

    $this->grants()->revoke($agent, $board);
    $this->assertSame([], $this->grantRows());
    $removed = $this->events();
    $this->assertSame('agent.grant_removed', $removed[array_key_last($removed)]['type']);
    try {
      $this->grants()->revoke($agent, $board);
      $this->fail('A missing grant was removed again.');
    }
    catch (AgentGrantRejectedException $exception) {
      $this->assertSame(AgentGrantRejectedException::MISSING, $exception->getMessage());
    }
    $this->assertGreaterThan($before, count($removed));
  }

  /**
   * A failed record leaves no agent row.
   */
  public function testFailedRecordRollsBack(): void {
    $ada = $this->createUserAccount('ada3', 'ada3@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Falha', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $failing = new class (
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get('Drupal\ab_core\Authorization\MembershipScope'),
      $this->container->get('Drupal\ab_core\Authorization\HumanActorContextFactory'),
      $this->container->get('Drupal\ab_event\EventStoreInterface'),
    ) extends AgentManager {

      /**
       * {@inheritdoc}
       */
      protected function afterRecord(Agent $agent): void {
        throw new \RuntimeException('stop');
      }

    };
    try {
      $failing->create($workspace, 'Falhou', '', '', TRUE, 1);
      $this->fail('A failed record was stored.');
    }
    catch (\RuntimeException) {
      $this->assertSame([], $this->agentIds());
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
   * Authenticates the account.
   */
  private function setCurrentUser(User $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Reloads an agent from storage.
   */
  private function reload(Agent $agent): Agent {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_agent');
    $storage->resetCache([$agent->id()]);
    $reloaded = $storage->load($agent->id());
    $this->assertInstanceOf(Agent::class, $reloaded);
    return $reloaded;
  }

  /**
   * Returns stored agent ids.
   *
   * @return string[]
   *   Agent ids.
   */
  private function agentIds(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_agent');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    return array_map('strval', array_values($ids));
  }

  /**
   * Returns stored grant rows.
   *
   * @return array<int, array<string, string>>
   *   Grant rows.
   */
  private function grantRows(): array {
    $rows = $this->container->get('database')->select('ab_agent_board_grant', 'g')
      ->fields('g')
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
