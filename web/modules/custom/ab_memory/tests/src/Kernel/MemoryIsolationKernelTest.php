<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_memory\Kernel;

use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\TaskManager;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\ab_memory\MemoryRejectedException;
use Drupal\ab_memory\MemoryService;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Verifies cross-workspace isolation, revocation and source validation.
 *
 * @group ab_memory
 */
class MemoryIsolationKernelTest extends KernelTestBase {

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
    'ab_memory',
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
    $this->installEntitySchema('ab_task');
    $this->installEntitySchema('ab_agent');
    $this->installEntitySchema('ab_execution');
    $this->installSchema('ab_event', [
      'ab_workspace_sequence',
      'ab_event',
      'ab_outbox',
    ]);
    $this->installSchema('ab_board', ['ab_task_dependency']);
    $this->installSchema('ab_agent', [
      'ab_agent_board_grant',
      'ab_agent_token',
      'ab_execution_lease',
    ]);
    $this->installSchema('ab_memory', [
      'ab_memory_entry',
      'ab_memory_revision',
      'ab_memory_source',
      'ab_memory_link',
    ]);
  }

  /**
   * Agents without grant get 404; archived is readable; revoke hides again.
   */
  public function testAgentGrantRevokeAndArchivedRead(): void {
    [$ada, $workspace, $board] = $this->fixture('Grant');
    $human = $this->human($ada, $workspace);
    $hiddenBoard = $this->boards()->create($workspace, 'Sem grant', '');
    $this->setCurrentUser($ada);
    $agent = $this->agents()->create($workspace, 'Isolado', '', '', TRUE, 1);
    $this->grants()->grant($agent, $board, [WorkspacePermission::READ, WorkspacePermission::WRITE]);
    $issued = $this->tokens()->issue($agent, [
      AgentTokenScope::MEMORY_READ,
      AgentTokenScope::MEMORY_WRITE,
    ]);
    $actor = new ActorContext(
      ActorContext::TYPE_AGENT,
      (string) $agent->uuid(),
      (string) $workspace->uuid(),
      [],
      $issued->publicId,
    );

    $visible = $this->memory()->createDraft($human, [
      'title' => 'Visível',
      'content_markdown' => 'Com grant.',
      'kind' => 'fact',
      'board_id' => (string) $board->uuid(),
    ]);
    $published = $this->memory()->publish($human, $visible->uuid, $visible->version);
    $this->assertSame('published', $this->memory()->get($actor, $published->uuid)->status);

    $hidden = $this->memory()->createDraft($human, [
      'title' => 'Oculto',
      'content_markdown' => 'Sem grant.',
      'kind' => 'fact',
      'board_id' => (string) $hiddenBoard->uuid(),
    ]);
    $hiddenPublished = $this->memory()->publish($human, $hidden->uuid, $hidden->version);
    try {
      $this->memory()->get($actor, $hiddenPublished->uuid);
      $this->fail('An agent read memory without a board grant.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertSame(1, $this->memory()->countVisible($actor));
    }

    try {
      $this->memory()->listRevisions($actor, $hiddenPublished->uuid);
      $this->fail('An agent listed revisions without a board grant.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertSame('published', $hiddenPublished->status);
    }

    try {
      $this->memory()->archive($actor, $published->uuid, $published->version);
      $this->fail('An agent archived visible memory.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame('published', $this->memory()->get($human, $published->uuid)->status);
    }

    $archived = $this->memory()->archive($human, $published->uuid, $published->version);
    $this->assertSame('archived', $this->memory()->get($actor, $archived->uuid)->status);

    $this->grants()->revoke($agent, $board);
    try {
      $this->memory()->get($actor, $archived->uuid);
      $this->fail('An agent read memory after revoke.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertSame(0, $this->memory()->countVisible($actor));
    }
  }

  /**
   * Another workspace never sees the entry; workspace-only events omit task_id.
   */
  public function testCrossWorkspaceAndWorkspaceEventOmitsTaskId(): void {
    [$ada, $workspace, $board] = $this->fixture('Casa');
    $human = $this->human($ada, $workspace);
    $task = $this->tasks()->create($board, ['title' => 'Tarefa origem']);
    $created = $this->memory()->createDraft($human, [
      'title' => 'Com tarefa',
      'content_markdown' => 'Origem na tarefa.',
      'kind' => 'summary',
      'task_id' => (string) $task->uuid(),
      'source_type' => 'task',
      'source_id' => (string) $task->uuid(),
    ]);
    $createdEvent = $this->eventsOf('memory.created')[0];
    $payload = json_decode((string) $createdEvent['payload'], TRUE);
    $this->assertIsArray($payload);
    $this->assertArrayNotHasKey('board_id', $payload);
    $this->assertArrayNotHasKey('task_id', $payload);
    $this->assertSame((string) $task->uuid(), $created->taskUuid);
    $this->assertSame((string) $task->uuid(), $created->sourceId);

    $bob = $this->createUserAccount('bob', 'bob@example.com');
    $other = $this->creator()->create($bob, 'Outro', 'America/Sao_Paulo');
    $otherHuman = $this->human($bob, $other);
    try {
      $this->memory()->get($otherHuman, $created->uuid);
      $this->fail('A foreign workspace read memory.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertSame(0, $this->memory()->countVisible($otherHuman));
    }

    try {
      $this->memory()->createDraft($human, [
        'title' => 'Fonte falsa',
        'content_markdown' => 'UUID solto.',
        'kind' => 'fact',
        'board_id' => (string) $board->uuid(),
        'source_type' => 'task',
        'source_id' => '11111111-1111-4111-8111-111111111111',
      ]);
      $this->fail('A foreign source_id was accepted.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertSame(1, $this->entryCount());
    }
  }

  /**
   * Duplicate links raise MemoryRejectedException; secrets in tags are refused.
   */
  public function testLinkUniquenessAndTagGuard(): void {
    [$ada, $workspace, $board] = $this->fixture('Link');
    $human = $this->human($ada, $workspace);
    $a = $this->memory()->createDraft($human, [
      'title' => 'A',
      'content_markdown' => 'Primeira',
      'kind' => 'fact',
      'board_id' => (string) $board->uuid(),
    ]);
    $b = $this->memory()->createDraft($human, [
      'title' => 'B',
      'content_markdown' => 'Segunda',
      'kind' => 'fact',
      'board_id' => (string) $board->uuid(),
    ]);
    $this->memory()->link($human, $a->uuid, $b->uuid, 'related');
    try {
      $this->memory()->link($human, $a->uuid, $b->uuid, 'related');
      $this->fail('A duplicate link was accepted.');
    }
    catch (MemoryRejectedException) {
      $this->assertSame(1, $this->linkCount());
    }

    try {
      $this->memory()->createDraft($human, [
        'title' => 'Tag suja',
        'content_markdown' => 'Corpo limpo',
        'kind' => 'fact',
        'board_id' => (string) $board->uuid(),
        'tags' => ['secret=abc'],
      ]);
      $this->fail('A secret tag was stored.');
    }
    catch (MemoryRejectedException) {
      $this->assertSame(2, $this->entryCount());
    }

    try {
      $this->memory()->revise($human, $a->uuid, [
        'change_reason' => "password=hunter2\n",
      ], $a->version);
      $this->fail('A secret change_reason was stored.');
    }
    catch (MemoryRejectedException) {
      $this->assertSame(1, $this->revisionCount($a->uuid));
    }
  }

  /**
   * Creates owner, workspace and board fixtures.
   *
   * @return array{0: \Drupal\user\Entity\User, 1: \Drupal\ab_core\Entity\Workspace, 2: \Drupal\ab_board\Entity\Board}
   *   Owner, workspace and board.
   */
  private function fixture(string $name): array {
    $ada = $this->createUserAccount(strtolower($name), strtolower($name) . '@example.com');
    $workspace = $this->creator()->create($ada, $name, 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro ' . $name, '');
    return [$ada, $workspace, $board];
  }

  /**
   * Builds a human ActorContext for the workspace.
   */
  private function human(UserInterface $user, Workspace $workspace): ActorContext {
    $this->setCurrentUser($user);
    $factory = $this->container->get(HumanActorContextFactory::class);
    $this->assertInstanceOf(HumanActorContextFactory::class, $factory);
    return $factory->create($workspace);
  }

  /**
   * Loads the memory service.
   */
  private function memory(): MemoryService {
    $service = $this->container->get(MemoryService::class);
    $this->assertInstanceOf(MemoryService::class, $service);
    return $service;
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
   * Loads the board manager.
   */
  private function boards(): BoardManager {
    $manager = $this->container->get(BoardManager::class);
    $this->assertInstanceOf(BoardManager::class, $manager);
    return $manager;
  }

  /**
   * Loads the task manager.
   */
  private function tasks(): TaskManager {
    $manager = $this->container->get(TaskManager::class);
    $this->assertInstanceOf(TaskManager::class, $manager);
    return $manager;
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
   * Loads the token manager.
   */
  private function tokens(): AgentTokenManager {
    $manager = $this->container->get(AgentTokenManager::class);
    $this->assertInstanceOf(AgentTokenManager::class, $manager);
    return $manager;
  }

  /**
   * Creates an active user account.
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
   * Authenticates the account in the kernel.
   */
  private function setCurrentUser(UserInterface $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Returns events of one type.
   *
   * @return list<array<string, mixed>>
   *   Event rows.
   */
  private function eventsOf(string $type): array {
    return array_values($this->container->get('database')->select('ab_event', 'e')
      ->fields('e')
      ->condition('type', $type)
      ->orderBy('sequence')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC));
  }

  /**
   * Counts revisions of one memory entry.
   */
  private function revisionCount(string $memoryUuid): int {
    $entryId = (int) $this->container->get('database')->select('ab_memory_entry', 'e')
      ->fields('e', ['id'])
      ->condition('uuid', $memoryUuid)
      ->execute()
      ->fetchField();
    return (int) $this->container->get('database')->select('ab_memory_revision', 'r')
      ->condition('entry_id', $entryId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts memory entries.
   */
  private function entryCount(): int {
    return (int) $this->container->get('database')->select('ab_memory_entry', 'e')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts memory links.
   */
  private function linkCount(): int {
    return (int) $this->container->get('database')->select('ab_memory_link', 'l')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
