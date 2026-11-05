<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_board\Kernel;

use Drupal\ab_board\ColumnManager;
use Drupal\ab_board\ColumnRepository;
use Drupal\ab_board\Entity\Column;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\TaskManager;
use Drupal\ab_board\TaskTransitionRejectedException;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies column changes stay inside the workspace.
 *
 * @group ab_board
 */
class ColumnManagerKernelTest extends KernelTestBase {

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
    $this->installEntitySchema('ab_column');
    $this->installEntitySchema('ab_task');
    $this->installSchema('ab_event', [
      'ab_workspace_sequence',
      'ab_event',
      'ab_outbox',
    ]);
  }

  /**
   * A manager can add, rename, reorder and archive a column.
   */
  public function testManagerChangesColumnsAndRecordsEvents(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');

    $created = $this->manager()->create($board, ' Desenho ');
    $this->assertSame('Desenho', $created->get('name')->getString());
    $this->assertSame(0, (int) $created->get('locked')->getString());
    $this->assertSame(0, (int) $created->get('system')->getString());
    $this->assertSame((string) $workspace->id(), $created->get('workspace')->getString());
    $this->assertSame((string) $board->id(), $created->get('board')->getString());

    $renamed = $this->manager()->rename($created, 'Layout');
    $this->assertSame('Layout', $renamed->get('name')->getString());
    $this->assertSame($created->get('machine')->getString(), $renamed->get('machine')->getString());

    $moved = $this->manager()->move($renamed, 'up');
    $this->assertLessThan(
      (int) $renamed->get('position')->getString(),
      (int) $moved->get('position')->getString(),
    );

    $task = $this->tasks()->create($board, ['title' => 'Cartaz']);
    $placed = $this->tasks()->transition($task, $created->get('machine')->getString(), 1);
    $this->assertSame($created->get('machine')->getString(), $placed->get('status')->getString());
    try {
      $this->manager()->archive($moved);
      $this->fail('A column with a task was archived.');
    }
    catch (\InvalidArgumentException) {
      $this->assertSame(0, (int) $this->reload($moved)->get('archived')->getString());
    }
    $this->tasks()->transition($placed, 'backlog', 2);

    $archived = $this->manager()->archive($moved);
    $this->assertSame(1, (int) $archived->get('archived')->getString());
    $visible = $this->repository()->ensure($board);
    $this->assertCount(5, $visible);

    $events = $this->events();
    $types = array_column($events, 'type');
    $this->assertContains('column.created', $types);
    $this->assertContains('column.renamed', $types);
    $this->assertContains('column.reordered', $types);
    $this->assertContains('column.archived', $types);
    $created_event = $this->event($events, 'column.created');
    $this->assertSame('human', $created_event['actor_type']);
    $this->assertSame((string) $ada->id(), $created_event['actor_id']);
    $this->assertSame((string) $workspace->uuid(), $created_event['workspace_id']);
    $this->assertSame((string) $created->uuid(), $created_event['resource_id']);
  }

  /**
   * The five system columns and the execution statuses stay put.
   */
  public function testSystemColumnsAndExecutionStatusesStay(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $columns = $this->repository()->ensure($board);
    $this->assertCount(5, $columns);
    $backlog = $columns[0];
    $this->assertSame('backlog', $backlog->get('machine')->getString());
    try {
      $this->manager()->archive($backlog);
      $this->fail('A system column was archived.');
    }
    catch (\InvalidArgumentException) {
      $this->assertSame(0, (int) $this->reload($backlog)->get('archived')->getString());
    }

    $task = $this->tasks()->create($board, ['title' => 'Cartaz']);
    foreach (['in_progress', 'in_review', 'done'] as $target) {
      try {
        $this->tasks()->transition($task, $target, 1);
        $this->fail($target . ' was stored by the human command.');
      }
      catch (TaskTransitionRejectedException) {
        $this->assertSame('backlog', $this->reloadTask($task)->get('status')->getString());
      }
    }
  }

  /**
   * A viewer is forbidden and another workspace is not found.
   */
  public function testViewerIsForbiddenAndOutsiderIsNotFound(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $grace = $this->createUserAccount('grace', 'grace@example.com');
    $outsider = $this->createUserAccount('out', 'out@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->membership($workspace, $grace, 'viewer');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $seeded = $this->repository()->ensure($board);
    $column_id = (string) $seeded[0]->uuid();

    $this->setCurrentUser($grace);
    try {
      $this->manager()->create($board, 'Fora');
      $this->fail('A viewer created a column.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertCount(5, $this->repository()->loadAll($board));
    }

    $this->setCurrentUser($outsider);
    try {
      $this->manager()->create($board, 'Fora');
      $this->fail('An outsider created a column.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertNull($this->repository()->loadAccessibleByUuid($column_id));
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
   * Authenticates the account.
   */
  private function setCurrentUser(User $user): void {
    $this->container->get('current_user')->setAccount($user);
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
   * Loads the column manager.
   */
  private function manager(): ColumnManager {
    $manager = $this->container->get(ColumnManager::class);
    $this->assertInstanceOf(ColumnManager::class, $manager);
    return $manager;
  }

  /**
   * Loads the column repository.
   */
  private function repository(): ColumnRepository {
    $repository = $this->container->get(ColumnRepository::class);
    $this->assertInstanceOf(ColumnRepository::class, $repository);
    return $repository;
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
   * Reloads a column from storage.
   */
  private function reload(Column $column): Column {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_column');
    $storage->resetCache([$column->id()]);
    $reloaded = $storage->load($column->id());
    $this->assertInstanceOf(Column::class, $reloaded);
    return $reloaded;
  }

  /**
   * Reloads a task from storage.
   */
  private function reloadTask(Task $task): Task {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_task');
    $storage->resetCache([$task->id()]);
    $reloaded = $storage->load($task->id());
    $this->assertInstanceOf(Task::class, $reloaded);
    return $reloaded;
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
   * Finds one event by type.
   *
   * @param array<int, array<string, mixed>> $events
   *   Stored events.
   * @param string $type
   *   Event type to find.
   *
   * @return array<string, mixed>
   *   The matching row.
   */
  private function event(array $events, string $type): array {
    foreach ($events as $event) {
      if ($event['type'] === $type) {
        return $event;
      }
    }
    $this->fail('Missing event ' . $type);
    throw new \LogicException($type);
  }

}
