<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_memory\Kernel;

use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_board\BoardManager;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\ab_memory\MemorySearchIndex;
use Drupal\ab_memory\MemoryService;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Proves lexical search ranks, scopes and reindexes without widening access.
 *
 * @group ab_memory
 */
class MemorySearchKernelTest extends KernelTestBase {

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
      'ab_memory_search_index',
    ]);
    $this->container->get(MemorySearchIndex::class)->ensureFts();
  }

  /**
   * Known queries rank the relevant published lesson above an unrelated fact.
   */
  public function testBenchmarkOrdersByRelevanceThenRecency(): void {
    [$owner, $workspace, $board] = $this->fixture('Busca');
    $human = $this->human($owner, $workspace);
    $noise = $this->memory()->createDraft($human, [
      'title' => 'Cor da marca',
      'content_markdown' => 'Escolhemos azul no piloto.',
      'kind' => 'decision',
      'board_id' => (string) $board->uuid(),
    ]);
    $this->memory()->publish($human, $noise->uuid, $noise->version);
    $weak = $this->memory()->createDraft($human, [
      'title' => 'Nota de presença',
      'content_markdown' => 'O agente envia um sinal curto.',
      'kind' => 'lesson',
      'board_id' => (string) $board->uuid(),
    ]);
    $this->memory()->publish($human, $weak->uuid, $weak->version);
    $hit = $this->memory()->createDraft($human, [
      'title' => 'Renovação de lease',
      'content_markdown' => 'Renovar a lease a cada trinta segundos com heartbeat contínuo.',
      'kind' => 'lesson',
      'board_id' => (string) $board->uuid(),
    ]);
    $published = $this->memory()->publish($human, $hit->uuid, $hit->version);

    $found = $this->memory()->listVisible($human, ['q' => 'lease heartbeat'], 10);
    $this->assertNotEmpty($found);
    $this->assertSame($published->uuid, $found[0]->uuid);
    $ids = array_map(static fn ($row) => $row->uuid, $found);
    $this->assertNotContains($noise->uuid, $ids);
    $this->assertNotNull($found[0]->searchExcerpt);
    $this->assertLessThanOrEqual(MemorySearchIndex::EXCERPT_MAX, strlen((string) $found[0]->searchExcerpt));
    $this->assertSame($published->revisionVersion, $found[0]->revisionVersion);
    $this->assertSame('manual', $found[0]->sourceType);
  }

  /**
   * Archived rows leave the default search; reindex restores published only.
   */
  public function testArchivedHiddenAndReindexRestoresProjection(): void {
    [$owner, $workspace, $board] = $this->fixture('Arquivo');
    $human = $this->human($owner, $workspace);
    $draft = $this->memory()->createDraft($human, [
      'title' => 'Procedimento de deploy',
      'content_markdown' => 'Passos do deploy canário.',
      'kind' => 'procedure',
      'board_id' => (string) $board->uuid(),
    ]);
    $published = $this->memory()->publish($human, $draft->uuid, $draft->version);
    $this->assertSame(1, $this->index()->count((int) $workspace->id()));
    $this->assertCount(1, $this->memory()->listVisible($human, ['q' => 'deploy']));

    $archived = $this->memory()->archive($human, $published->uuid, $published->version);
    $this->assertSame('archived', $archived->status);
    $this->assertSame([], $this->memory()->listVisible($human, ['q' => 'deploy']));
    $explicit = $this->memory()->listVisible($human, [
      'q' => 'deploy',
      'status' => 'archived',
    ]);
    $this->assertCount(1, $explicit);

    $this->database()->delete('ab_memory_search_index')->execute();
    $this->assertSame(0, $this->index()->count());
    $rebuilt = $this->memory()->reindex((int) $workspace->id());
    $this->assertSame(1, $rebuilt);
    $this->assertSame([], $this->memory()->listVisible($human, ['q' => 'deploy']));
    $this->assertCount(1, $this->memory()->listVisible($human, [
      'q' => 'deploy',
      'status' => 'archived',
    ]));
  }

  /**
   * Agents only see boards they still hold; concurrent sync keeps one row.
   */
  public function testGrantRevocationAndConcurrentSync(): void {
    [$owner, $workspace, $visible, $hidden] = $this->fixtureTwoBoards('Grant');
    $human = $this->human($owner, $workspace);
    $a = $this->memory()->createDraft($human, [
      'title' => 'Segredo do quadro oculto',
      'content_markdown' => 'Texto só do quadro oculto.',
      'kind' => 'fact',
      'board_id' => (string) $hidden->uuid(),
    ]);
    $this->memory()->publish($human, $a->uuid, $a->version);
    $b = $this->memory()->createDraft($human, [
      'title' => 'Lição do quadro visível',
      'content_markdown' => 'Texto do quadro visível para o agente.',
      'kind' => 'lesson',
      'board_id' => (string) $visible->uuid(),
    ]);
    $published = $this->memory()->publish($human, $b->uuid, $b->version);

    $agent = $this->container->get(AgentManager::class)->create($workspace, 'Buscador', '', '', TRUE, 1);
    $this->container->get(AgentGrantManager::class)->grant($agent, $visible, ['read', 'write']);
    $token = $this->container->get(AgentTokenManager::class)->issue($agent, [
      AgentTokenScope::MEMORY_READ,
    ]);
    $actor = new ActorContext(
      ActorContext::TYPE_AGENT,
      (string) $agent->uuid(),
      (string) $workspace->uuid(),
      [],
      $token->publicId,
    );

    $found = $this->memory()->listVisible($actor, ['q' => 'quadro']);
    $ids = array_map(static fn ($row) => $row->uuid, $found);
    $this->assertContains($published->uuid, $ids);
    $this->assertNotContains($a->uuid, $ids);

    $this->setCurrentUser($owner);
    $this->container->get(AgentGrantManager::class)->revoke($agent, $visible);
    $this->assertSame([], $this->memory()->listVisible($actor, ['q' => 'quadro']));

    $entry = $this->database()->select('ab_memory_entry', 'e')
      ->fields('e')
      ->condition('e.uuid', $published->uuid)
      ->execute()
      ->fetchAssoc();
    $revision = $this->database()->select('ab_memory_revision', 'r')
      ->fields('r')
      ->condition('r.id', (int) $entry['current_revision_id'])
      ->execute()
      ->fetchAssoc();
    $this->index()->sync($entry, $revision);
    $this->index()->sync($entry, $revision);
    $this->assertSame(1, (int) $this->database()->select('ab_memory_search_index', 'i')
      ->condition('i.entry_id', (int) $entry['id'])
      ->countQuery()
      ->execute()
      ->fetchField());
  }

  /**
   * Creates an owner, workspace and board.
   *
   * @return array{0: \Drupal\user\UserInterface, 1: \Drupal\ab_core\Entity\Workspace, 2: \Drupal\ab_board\Entity\Board}
   *   Owner, workspace and board.
   */
  private function fixture(string $name): array {
    $owner = User::create([
      'name' => 'mem-search-' . strtolower($name) . '-' . $this->randomMachineName(4),
      'status' => 1,
    ]);
    $owner->save();
    $this->setCurrentUser($owner);
    $workspace = $this->container->get(WorkspaceCreator::class)->create($owner, $name, 'America/Sao_Paulo');
    $board = $this->container->get(BoardManager::class)->create($workspace, 'Quadro ' . $name);
    return [$owner, $workspace, $board];
  }

  /**
   * Creates an owner, workspace and two boards.
   *
   * @return array{0: \Drupal\user\UserInterface, 1: \Drupal\ab_core\Entity\Workspace, 2: \Drupal\ab_board\Entity\Board, 3: \Drupal\ab_board\Entity\Board}
   *   Owner, workspace, visible board and hidden board.
   */
  private function fixtureTwoBoards(string $name): array {
    [$owner, $workspace, $visible] = $this->fixture($name);
    $hidden = $this->container->get(BoardManager::class)->create($workspace, 'Oculto ' . $name);
    return [$owner, $workspace, $visible, $hidden];
  }

  /**
   * Builds the human actor for the given account.
   */
  private function human(UserInterface $user, $workspace): ActorContext {
    $this->setCurrentUser($user);
    return $this->container->get(HumanActorContextFactory::class)->create($workspace);
  }

  /**
   * Sets the current account for policy checks.
   */
  private function setCurrentUser(UserInterface $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Returns the memory service.
   */
  private function memory(): MemoryService {
    return $this->container->get(MemoryService::class);
  }

  /**
   * Returns the lexical index service.
   */
  private function index(): MemorySearchIndex {
    return $this->container->get(MemorySearchIndex::class);
  }

  /**
   * Returns the database connection.
   */
  private function database() {
    return $this->container->get('database');
  }

}
