<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_memory\Kernel;

use Drupal\ab_memory\MemoryContentGuard;
use Drupal\ab_memory\MemoryRepository;
use Drupal\ab_memory\MemoryAccessPolicy;
use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_board\BoardManager;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\ab_event\EventStoreInterface;
use Drupal\ab_memory\MemoryRejectedException;
use Drupal\ab_memory\MemoryService;
use Drupal\ab_memory\MemoryVersionConflictException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Verifies draft, revise, publish, archive, events and authorship.
 *
 * @group ab_memory
 */
class MemoryServiceKernelTest extends KernelTestBase {

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
   * A human creates, revises, publishes and archives with events and hash.
   */
  public function testHumanLifecycleRecordsEventsWithoutMarkdown(): void {
    [$ada, $workspace, $board] = $this->fixture('Casa');
    $human = $this->human($ada, $workspace);
    $created = $this->memory()->createDraft($human, [
      'title' => 'Decisão do board',
      'content_markdown' => 'Usamos claim atômico.',
      'kind' => 'decision',
      'board_id' => (string) $board->uuid(),
      'tags' => ['claim'],
      'source_type' => 'manual',
    ]);
    $this->assertSame('draft', $created->status);
    $this->assertSame(1, $created->version);
    $this->assertSame(1, $created->revisionVersion);
    $this->assertSame(hash('sha256', 'Usamos claim atômico.'), $created->contentHash);
    $this->assertSame('human', $created->authorType);
    $this->assertSame((string) $ada->id(), $created->authorId);

    $revised = $this->memory()->revise($human, $created->uuid, [
      'content_markdown' => 'Usamos claim atômico e lease.',
      'change_reason' => 'Detalhe',
    ], $created->version);
    $this->assertSame(2, $revised->version);
    $this->assertSame(2, $revised->revisionVersion);
    $this->assertSame(2, $this->revisionCount($created->uuid));

    $published = $this->memory()->publish($human, $created->uuid, $revised->version);
    $this->assertSame('published', $published->status);
    $this->assertSame(3, $published->version);
    $this->assertSame(2, $published->revisionVersion);

    $archived = $this->memory()->archive($human, $created->uuid, $published->version);
    $this->assertSame('archived', $archived->status);

    $createdEvent = $this->eventsOf('memory.created')[0];
    $payload = json_decode((string) $createdEvent['payload'], TRUE);
    $this->assertIsArray($payload);
    $this->assertSame((string) $board->uuid(), $payload['board_id']);
    $this->assertArrayNotHasKey('content_markdown', $payload);
    $this->assertSame('human', $createdEvent['actor_type']);
    $this->assertSame((string) $ada->id(), $createdEvent['actor_id']);
    $this->assertCount(1, $this->eventsOf('memory.revised'));
    $this->assertCount(1, $this->eventsOf('memory.published'));
    $this->assertCount(1, $this->eventsOf('memory.archived'));
    $this->assertSame(2, $this->sourceCount($created->uuid));
  }

  /**
   * A stale version conflicts and an event failure rolls back the revision.
   */
  public function testConflictAndRollback(): void {
    [$ada, $workspace, $board] = $this->fixture('Rollback');
    $human = $this->human($ada, $workspace);
    $created = $this->memory()->createDraft($human, [
      'title' => 'Título',
      'content_markdown' => 'Corpo inicial',
      'kind' => 'fact',
      'board_id' => (string) $board->uuid(),
    ]);
    try {
      $this->memory()->revise($human, $created->uuid, [
        'content_markdown' => 'Outro',
      ], 99);
      $this->fail('A stale revise was accepted.');
    }
    catch (MemoryVersionConflictException) {
      $this->assertSame(1, $this->revisionCount($created->uuid));
    }

    $failing = new class(
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get(EventStoreInterface::class),
      $this->container->get(MemoryAccessPolicy::class),
      $this->container->get(MemoryRepository::class),
      $this->container->get(MemoryContentGuard::class),
      $this->container->get('uuid'),
      $this->container->get('datetime.time'),
    ) extends MemoryService {

      /**
       * {@inheritdoc}
       */
      protected function afterRecord(): void {
        throw new \RuntimeException('forced');
      }

    };
    try {
      $failing->revise($human, $created->uuid, [
        'content_markdown' => 'Não deve ficar',
      ], $created->version);
      $this->fail('A forced failure was ignored.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('forced', $exception->getMessage());
    }
    $this->assertSame(1, $this->revisionCount($created->uuid));
    $this->assertSame('Corpo inicial', $this->memory()->get($human, $created->uuid)->contentMarkdown);
    $this->assertCount(0, $this->eventsOf('memory.revised'));
  }

  /**
   * Agents write board drafts; forged authorship and secrets are refused.
   */
  public function testAgentDraftAndGuards(): void {
    [$ada, $workspace, $board] = $this->fixture('Agente');
    $human = $this->human($ada, $workspace);
    $this->setCurrentUser($ada);
    $agent = $this->agents()->create($workspace, 'Runner', '', '', TRUE, 1);
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

    $draft = $this->memory()->createDraft($actor, [
      'title' => 'Lição',
      'content_markdown' => 'Heartbeat a cada 30s.',
      'kind' => 'lesson',
      'board_id' => (string) $board->uuid(),
      'source_type' => 'execution',
      'source_id' => '11111111-1111-4111-8111-111111111111',
    ]);
    $this->assertSame('agent', $draft->authorType);
    $this->assertSame((string) $agent->uuid(), $draft->authorId);
    $this->assertSame('execution', $draft->sourceType);

    try {
      $this->memory()->publish($actor, $draft->uuid, $draft->version);
      $this->fail('An agent published memory.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame('draft', $this->memory()->get($human, $draft->uuid)->status);
    }

    try {
      $this->memory()->createDraft($human, [
        'title' => 'x',
        'content_markdown' => 'ok',
        'kind' => 'fact',
        'author_id' => 'forged',
      ]);
      $this->fail('Forged author_id was accepted.');
    }
    catch (\InvalidArgumentException) {
      $this->assertSame(1, $this->entryCount());
    }

    try {
      $this->memory()->createDraft($human, [
        'title' => 'Segredo',
        'content_markdown' => "password=hunter2\n",
        'kind' => 'fact',
        'board_id' => (string) $board->uuid(),
      ]);
      $this->fail('Sensitive content was stored.');
    }
    catch (MemoryRejectedException) {
      $this->assertSame(1, $this->entryCount());
    }

    $workspaceOnly = $this->memory()->createDraft($human, [
      'title' => 'Só humanos',
      'content_markdown' => 'Visibilidade de workspace.',
      'kind' => 'summary',
    ]);
    try {
      $this->memory()->get($actor, $workspaceOnly->uuid);
      $this->fail('An agent read workspace-only memory.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertSame('draft', $workspaceOnly->status);
    }

    $other = $this->memory()->createDraft($human, [
      'title' => 'Sucessor',
      'content_markdown' => 'Substitui a lição.',
      'kind' => 'decision',
      'board_id' => (string) $board->uuid(),
    ]);
    $this->memory()->link($human, $other->uuid, $draft->uuid, 'supersedes');
    $this->assertSame(1, $this->linkCount());
    $this->assertCount(1, $this->eventsOf('memory.linked'));
  }

  /**
   * Token scopes include memory read and write.
   */
  public function testMemoryScopesExist(): void {
    $this->assertContains(AgentTokenScope::MEMORY_READ, AgentTokenScope::all());
    $this->assertContains(AgentTokenScope::MEMORY_WRITE, AgentTokenScope::all());
    $this->assertSame(WorkspacePermission::READ, AgentTokenScope::grantFor(AgentTokenScope::MEMORY_READ));
    $this->assertSame(WorkspacePermission::WRITE, AgentTokenScope::grantFor(AgentTokenScope::MEMORY_WRITE));
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
   * Counts source rows of one memory entry.
   */
  private function sourceCount(string $memoryUuid): int {
    $entryId = (int) $this->container->get('database')->select('ab_memory_entry', 'e')
      ->fields('e', ['id'])
      ->condition('uuid', $memoryUuid)
      ->execute()
      ->fetchField();
    return (int) $this->container->get('database')->select('ab_memory_source', 's')
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
