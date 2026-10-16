<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_board\Kernel;

use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskDayWindow;
use Drupal\ab_board\TaskManager;
use Drupal\ab_board\TaskReorderRejectedException;
use Drupal\ab_board\TaskVersionConflictException;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies column order stays valid and the day filter does not write.
 *
 * @group ab_board
 */
class TaskReorderKernelTest extends KernelTestBase {

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
  }

  /**
   * Two reorders keep increasing positions, and a stale one does not write.
   */
  public function testReorderKeepsValidOrder(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $viewer = $this->createUserAccount('view', 'view@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->membership($workspace, $viewer, 'viewer');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $alfa = $this->tasks()->create($board, ['title' => 'Alfa', 'position' => 1]);
    $beta = $this->tasks()->create($board, ['title' => 'Beta', 'position' => 2]);

    $moved = $this->tasks()->reorder($beta, 1, $alfa->uuid());
    $alfa = $this->reload($alfa);
    $beta = $this->reload($moved);
    $this->assertSame('1024', $beta->get('position')->getString());
    $this->assertSame('2048', $alfa->get('position')->getString());
    $this->assertSame('2', $beta->get('version')->getString());
    $this->assertSame('1', $alfa->get('version')->getString());
    $this->assertLessThan(
      (int) $alfa->get('position')->getString(),
      (int) $beta->get('position')->getString(),
    );

    $again = $this->tasks()->reorder($alfa, 1, NULL);
    $alfa = $this->reload($again);
    $beta = $this->reload($beta);
    $this->assertGreaterThan(
      (int) $beta->get('position')->getString(),
      (int) $alfa->get('position')->getString(),
    );
    $this->assertSame('2', $beta->get('version')->getString());
    $events = $this->events();
    $last = $events[array_key_last($events)];
    $this->assertSame('task.reordered', $last['type']);
    $payload = json_decode((string) $last['payload'], TRUE);
    $this->assertSame((int) $alfa->get('position')->getString(), $payload['position']);

    $before = (int) $alfa->get('position')->getString();
    try {
      $this->tasks()->reorder($alfa, 1, $beta->uuid());
      $this->fail('A stale reorder was stored.');
    }
    catch (TaskVersionConflictException) {
      $this->assertSame((string) $before, $this->reload($alfa)->get('position')->getString());
    }

    $ready = $this->tasks()->create($board, ['title' => 'Pronta']);
    $ready->set('status', 'ready');
    $ready->save();
    $current = $this->reload($alfa);
    try {
      $this->tasks()->reorder(
        $current,
        (int) $current->get('version')->getString(),
        $ready->uuid(),
      );
      $this->fail('A task was ordered into another column.');
    }
    catch (TaskReorderRejectedException) {
      $this->assertSame((string) $before, $this->reload($alfa)->get('position')->getString());
    }

    $this->setCurrentUser($viewer);
    try {
      $this->tasks()->reorder($beta, 2, NULL);
      $this->fail('A viewer reordered a task.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame('2', $this->reload($beta)->get('version')->getString());
    }
  }

  /**
   * Crossing midnight changes the filter, not the stored task.
   */
  public function testMidnightDoesNotChangeTheTask(): void {
    $zone = new \DateTimeZone('America/Sao_Paulo');
    $evening = new \DateTimeImmutable('2026-10-16 23:30:00', $zone);
    $morning = new \DateTimeImmutable('2026-10-17 00:10:00', $zone);
    $today = new \DateTimeImmutable('2026-10-16 00:00:00', $zone);
    $tomorrow = new \DateTimeImmutable('2026-10-17 00:00:00', $zone);
    $window = new TaskDayWindow();
    $timezone = 'America/Sao_Paulo';

    $this->assertTrue($window->includes($today->getTimestamp(), 'backlog', $timezone, $evening->getTimestamp()));
    $this->assertFalse($window->includes($tomorrow->getTimestamp(), 'backlog', $timezone, $evening->getTimestamp()));
    $this->assertTrue($window->includes($today->getTimestamp(), 'backlog', $timezone, $morning->getTimestamp()));
    $this->assertTrue($window->includes($tomorrow->getTimestamp(), 'backlog', $timezone, $morning->getTimestamp()));
    $this->assertTrue($window->includes(NULL, 'in_progress', $timezone, $evening->getTimestamp()));

    $ada = $this->createUserAccount('ada2', 'ada2@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Noite', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $task = $this->tasks()->create($board, [
      'title' => 'Para amanhã',
      'due_at' => $tomorrow->getTimestamp(),
    ]);
    $window->includes($tomorrow->getTimestamp(), 'backlog', $timezone, $morning->getTimestamp());
    $stored = $this->reload($task);
    $this->assertSame('1', $stored->get('version')->getString());
    $this->assertSame('backlog', $stored->get('status')->getString());
    $this->assertSame((string) $tomorrow->getTimestamp(), $stored->get('due_at')->getString());
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
