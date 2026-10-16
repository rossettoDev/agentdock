<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_board\Kernel;

use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskDependencyManager;
use Drupal\ab_board\TaskDependencyRejectedException;
use Drupal\ab_board\TaskManager;
use Drupal\ab_board\TaskVersionConflictException;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\ab_event\EventStoreInterface;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies task dependencies stay on one board and cannot cycle.
 *
 * @group ab_board
 */
class TaskDependencyKernelTest extends KernelTestBase {

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
    $this->installEntitySchema('ab_task');
    $this->installSchema('ab_event', [
      'ab_workspace_sequence',
      'ab_event',
      'ab_outbox',
    ]);
    $this->installSchema('ab_board', ['ab_task_dependency']);
  }

  /**
   * Self, another board and a repeated pair are rejected.
   */
  public function testSelfBoardAndDuplicatePairsAreRejected(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $other_board = $this->boards()->create($workspace, 'Outro', 'Texto');
    $alfa = $this->task($board, 'Alfa');
    $beta = $this->task($board, 'Beta');
    $gama = $this->task($other_board, 'Gama');

    try {
      $this->dependencies()->add($alfa, $alfa, 1);
      $this->fail('A task depended on itself.');
    }
    catch (TaskDependencyRejectedException $exception) {
      $this->assertSame(TaskDependencyRejectedException::SELF, $exception->getMessage());
      $this->assertSame('1', $this->reload($alfa)->get('version')->getString());
      $this->assertSame([], $this->pairs());
    }

    try {
      $this->dependencies()->add($alfa, $gama, 1);
      $this->fail('A dependency crossed boards.');
    }
    catch (TaskDependencyRejectedException $exception) {
      $this->assertSame(TaskDependencyRejectedException::BOARD, $exception->getMessage());
      $this->assertSame([], $this->pairs());
    }

    $stored = $this->dependencies()->add($alfa, $beta, 1);
    $this->assertSame('2', $stored->get('version')->getString());
    $this->assertSame('backlog', $stored->get('status')->getString());
    $pair = $this->pairs()[0];
    $this->assertSame((string) $workspace->id(), (string) $pair['workspace']);
    $this->assertSame((string) $board->id(), (string) $pair['board']);
    $this->assertSame((string) $alfa->id(), (string) $pair['task_id']);
    $this->assertSame((string) $beta->id(), (string) $pair['dependency_id']);

    $events = $this->events();
    $added = $events[array_key_last($events)];
    $this->assertSame('task.dependency_added', $added['type']);
    $this->assertSame('human', $added['actor_type']);
    $this->assertSame((string) $ada->id(), $added['actor_id']);
    $payload = json_decode((string) $added['payload'], TRUE);
    $this->assertSame((string) $beta->uuid(), $payload['dependency']);
    $this->assertSame(2, $payload['version']);

    try {
      $this->dependencies()->add($stored, $beta, 2);
      $this->fail('The same pair was stored twice.');
    }
    catch (TaskDependencyRejectedException $exception) {
      $this->assertSame(TaskDependencyRejectedException::DUPLICATE, $exception->getMessage());
      $this->assertCount(1, $this->pairs());
      $this->assertSame('2', $this->reload($alfa)->get('version')->getString());
    }

    $database = $this->container->get('database');
    $transaction = $database->startTransaction();
    try {
      $database->insert('ab_task_dependency')->fields($pair)->execute();
      $this->fail('The database accepted a repeated pair.');
    }
    catch (IntegrityConstraintViolationException) {
      $transaction->rollBack();
    }
    $this->assertCount(1, $this->pairs());
  }

  /**
   * An indirect cycle is rejected and a shortcut is kept.
   */
  public function testIndirectCycleIsRejected(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $alfa = $this->task($board, 'Alfa');
    $beta = $this->task($board, 'Beta');
    $gama = $this->task($board, 'Gama');

    $alfa = $this->dependencies()->add($alfa, $beta, 1);
    $beta = $this->dependencies()->add($beta, $gama, 1);
    $alfa = $this->dependencies()->add($alfa, $gama, 2);
    $this->assertCount(3, $this->pairs());
    $this->assertSame('3', $this->reload($alfa)->get('version')->getString());

    try {
      $this->dependencies()->add($gama, $alfa, 1);
      $this->fail('An indirect cycle was stored.');
    }
    catch (TaskDependencyRejectedException $exception) {
      $this->assertSame(TaskDependencyRejectedException::CYCLE, $exception->getMessage());
      $this->assertCount(3, $this->pairs());
      $this->assertSame('1', $this->reload($gama)->get('version')->getString());
    }

    try {
      $this->dependencies()->add($beta, $alfa, 2);
      $this->fail('A two-step cycle was stored.');
    }
    catch (TaskDependencyRejectedException $exception) {
      $this->assertSame(TaskDependencyRejectedException::CYCLE, $exception->getMessage());
    }
  }

  /**
   * Blocked is derived from predecessors that are not done.
   */
  public function testBlockedFollowsPredecessorsThatAreNotDone(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $alfa = $this->task($board, 'Alfa');
    $beta = $this->task($board, 'Beta');
    $gama = $this->task($board, 'Gama');
    $this->assertFalse($this->dependencies()->isBlocked($alfa));

    $alfa = $this->dependencies()->add($alfa, $beta, 1);
    $alfa = $this->dependencies()->add($alfa, $gama, 2);
    $this->assertTrue($this->dependencies()->isBlocked($alfa));

    $beta->set('status', 'done');
    $beta->save();
    $this->assertTrue($this->dependencies()->isBlocked($this->reload($alfa)));

    $gama->set('status', 'done');
    $gama->save();
    $this->assertFalse($this->dependencies()->isBlocked($this->reload($alfa)));

    $gama->set('status', 'cancelled');
    $gama->save();
    $this->assertTrue($this->dependencies()->isBlocked($this->reload($alfa)));

    $removed = $this->dependencies()->remove($this->reload($alfa), $this->reload($gama), 3);
    $this->assertFalse($this->dependencies()->isBlocked($removed));
    $this->assertCount(1, $this->pairs());
    $labels = array_map(static fn (Task $task): string => (string) $task->label(), $this->dependencies()->predecessors($removed));
    $this->assertSame(['Beta'], $labels);
    $events = array_column($this->events(), 'type');
    $this->assertContains('task.dependency_removed', $events);
  }

  /**
   * A stale version or a failed event leaves the pairs unchanged.
   */
  public function testConflictAndRollbackDoNotWrite(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $alfa = $this->task($board, 'Alfa');
    $beta = $this->task($board, 'Beta');
    $before = count($this->events());

    try {
      $this->dependencies()->add($alfa, $beta, 9);
      $this->fail('A stale dependency was stored.');
    }
    catch (TaskVersionConflictException) {
      $this->assertSame([], $this->pairs());
      $this->assertSame('1', $this->reload($alfa)->get('version')->getString());
    }

    $manager = new class(
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get(MembershipScope::class),
      $this->container->get(HumanActorContextFactory::class),
      $this->container->get(EventStoreInterface::class),
    ) extends TaskDependencyManager {

      /**
       * {@inheritdoc}
       */
      protected function afterRecord(Task $task): void {
        throw new \RuntimeException('Roll back the dependency.');
      }

    };

    try {
      $manager->add($alfa, $beta, 1);
      $this->fail('The dependency committed after a failure.');
    }
    catch (\RuntimeException) {
      $this->assertSame([], $this->pairs());
      $this->assertSame('1', $this->reload($alfa)->get('version')->getString());
      $this->assertCount($before, $this->events());
    }
  }

  /**
   * A reader cannot change dependencies, and an outsider cannot see them.
   */
  public function testViewerCannotChangeDependencies(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $viewer = $this->createUserAccount('view', 'view@example.com');
    $outsider = $this->createUserAccount('out', 'out@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $hidden = $this->creator()->create($outsider, 'Casa Oculta', 'America/Sao_Paulo');
    $this->membership($workspace, $viewer, 'viewer');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $alfa = $this->task($board, 'Alfa');
    $beta = $this->task($board, 'Beta');
    $this->setCurrentUser($outsider);
    $other = $this->task($this->boards()->create($hidden, 'Oculto', 'Texto'), 'Oculta');

    $this->setCurrentUser($viewer);
    try {
      $this->dependencies()->add($alfa, $beta, 1);
      $this->fail('A viewer added a dependency.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame([], $this->pairs());
    }

    $this->setCurrentUser($ada);
    try {
      $this->dependencies()->add($alfa, $other, 1);
      $this->fail('A hidden task was linked.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertSame([], $this->pairs());
      $this->assertSame('1', $this->reload($alfa)->get('version')->getString());
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
   * Creates a task on a board.
   */
  private function task(Board $board, string $title): Task {
    return $this->tasks()->create($board, ['title' => $title]);
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
   * Loads the dependency manager.
   */
  private function dependencies(): TaskDependencyManager {
    $manager = $this->container->get(TaskDependencyManager::class);
    $this->assertInstanceOf(TaskDependencyManager::class, $manager);
    return $manager;
  }

  /**
   * Authenticates the account.
   */
  private function setCurrentUser(User $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Reloads a task from storage.
   */
  private function reload(Task $task): Task {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_task');
    $storage->resetCache([$task->id()]);
    $reloaded = $storage->load($task->id());
    $this->assertInstanceOf(Task::class, $reloaded);
    return $reloaded;
  }

  /**
   * Returns stored dependency rows.
   *
   * @return array<int, array<string, mixed>>
   *   Dependency rows.
   */
  private function pairs(): array {
    $rows = $this->container->get('database')->select('ab_task_dependency', 'd')
      ->fields('d')
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
