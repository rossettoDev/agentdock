<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_board\Kernel;

use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspaceBoundary;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\ab_event\EventStoreInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies board creation, archival and workspace boundaries.
 *
 * @group ab_board
 */
class BoardManagerKernelTest extends KernelTestBase {

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
    $this->installSchema('ab_event', [
      'ab_workspace_sequence',
      'ab_event',
      'ab_outbox',
    ]);
  }

  /**
   * Archiving keeps the board row and records both events.
   */
  public function testArchivePreservesTheBoardAndRecordsEvents(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);

    $board = $this->manager()->create($workspace, 'Quadro', 'Descrição do quadro');
    $archived = $this->manager()->archive($board);
    $again = $this->manager()->archive($archived);

    $this->assertSame($board->id(), $again->id());
    $this->assertSame($board->uuid(), $again->uuid());
    $this->assertSame('Quadro', (string) $again->label());
    $this->assertSame('Descrição do quadro', $again->get('description')->getString());
    $this->assertSame((string) $workspace->id(), $again->get('workspace')->getString());
    $this->assertSame(1, (int) $again->get('archived')->getString());
    $this->assertCount(1, $this->boardIds());

    $stored = $this->reload($again);
    $this->assertSame('Quadro', (string) $stored->label());
    $this->assertSame('Descrição do quadro', $stored->get('description')->getString());
    $this->assertSame(1, (int) $stored->get('archived')->getString());

    $events = $this->events();
    $this->assertCount(2, $events);
    $this->assertSame('board.created', $events[0]['type']);
    $this->assertSame('board.archived', $events[1]['type']);
    $this->assertSame('1', (string) $events[0]['sequence']);
    $this->assertSame('2', (string) $events[1]['sequence']);
    $this->assertSame((string) $workspace->uuid(), $events[0]['workspace_id']);
    $this->assertSame((string) $board->uuid(), $events[0]['resource_id']);
    $this->assertSame((string) $board->uuid(), $events[1]['resource_id']);
    $this->assertSame('human', $events[0]['actor_type']);
    $this->assertSame((string) $ada->id(), $events[0]['actor_id']);
    $created_payload = json_decode((string) $events[0]['payload'], TRUE);
    $archived_payload = json_decode((string) $events[1]['payload'], TRUE);
    $this->assertSame(['name' => 'Quadro', 'archived' => FALSE], $created_payload);
    $this->assertSame(['name' => 'Quadro', 'archived' => TRUE], $archived_payload);
    $this->assertCount(2, $this->outboxIds());
  }

  /**
   * A board cannot be pointed at another workspace.
   */
  public function testCrossWorkspaceMoveIsRejected(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $grace = $this->createUserAccount('grace', 'grace@example.com');
    $ada_workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $grace_workspace = $this->creator()->create($grace, 'Casa Grace', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->manager()->create($ada_workspace, 'Quadro', 'Texto');

    $boundary = new WorkspaceBoundary();
    $this->assertFalse($boundary->isCrossWorkspaceReference($ada_workspace, $board));
    $this->assertTrue($boundary->isCrossWorkspaceReference($grace_workspace, $board));

    $board->set('workspace', $grace_workspace->id());
    try {
      $board->save();
      $this->fail('A board moved into another workspace.');
    }
    catch (EntityStorageException) {
      $stored = $this->reload($board);
      $this->assertSame((string) $ada_workspace->id(), $stored->get('workspace')->getString());
      $this->assertSame('Quadro', (string) $stored->label());
      $this->assertSame('Texto', $stored->get('description')->getString());
      $this->assertSame(0, (int) $stored->get('archived')->getString());
      $this->assertCount(1, $this->events());
    }
  }

  /**
   * A failure after the event removes the board and the event.
   */
  public function testCreateRollsBackWithTheEvent(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $manager = new class(
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get(MembershipScope::class),
      $this->container->get(HumanActorContextFactory::class),
      $this->container->get(EventStoreInterface::class),
    ) extends BoardManager {

      /**
       * {@inheritdoc}
       */
      protected function afterRecord(Board $board): void {
        throw new \RuntimeException('Roll back the board.');
      }

    };

    try {
      $manager->create($workspace, 'Quadro', 'Texto');
      $this->fail('The board create committed after a failure.');
    }
    catch (\RuntimeException) {
      $this->assertSame([], $this->boardIds());
      $this->assertSame([], $this->events());
      $this->assertSame([], $this->outboxIds());
    }
  }

  /**
   * A reader cannot create or archive a board.
   */
  public function testReaderCannotChangeBoards(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $grace = $this->createUserAccount('grace', 'grace@example.com');
    $outsider = $this->createUserAccount('out', 'out@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->membership($workspace, $grace, 'viewer');
    $this->setCurrentUser($ada);
    $board = $this->manager()->create($workspace, 'Quadro', 'Texto');

    $this->setCurrentUser($grace);
    try {
      $this->manager()->create($workspace, 'Outro', 'Texto');
      $this->fail('A viewer created a board.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertCount(1, $this->boardIds());
    }
    try {
      $this->manager()->archive($board);
      $this->fail('A viewer archived a board.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame(0, (int) $this->reload($board)->get('archived')->getString());
    }

    $this->setCurrentUser($outsider);
    try {
      $this->manager()->create($workspace, 'Fora', 'Texto');
      $this->fail('An outsider created a board.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertCount(1, $this->boardIds());
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
   * Loads the board manager.
   */
  private function manager(): BoardManager {
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
   * Reloads a board from storage.
   */
  private function reload(Board $board): Board {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_board');
    $storage->resetCache([$board->id()]);
    $reloaded = $storage->load($board->id());
    $this->assertInstanceOf(Board::class, $reloaded);
    return $reloaded;
  }

  /**
   * Returns stored board ids.
   *
   * @return string[]
   *   Board ids.
   */
  private function boardIds(): array {
    $ids = $this->container->get('entity_type.manager')->getStorage('ab_board')->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    return array_values($ids);
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

  /**
   * Returns outbox primary keys.
   *
   * @return string[]
   *   Event ids.
   */
  private function outboxIds(): array {
    $rows = $this->container->get('database')->select('ab_outbox', 'o')
      ->fields('o', ['event_id'])
      ->execute()
      ->fetchCol();
    return array_values($rows);
  }

}
