<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_board\Kernel;

use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskManager;
use Drupal\ab_board\TaskTransitionRejectedException;
use Drupal\ab_board\TaskVersionConflictException;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\ab_event\EventStoreInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies human transitions cannot imitate an execution.
 *
 * @group ab_board
 */
class TaskTransitionKernelTest extends KernelTestBase {

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
   * Release, cancel and reopen stay on the human graph.
   */
  public function testHumanGraphReleasesCancelsAndReopens(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $task = $this->tasks()->create($board, ['title' => 'Escrever o tema']);

    $ready = $this->tasks()->transition($task, 'ready', 1);
    $stored = $this->reload($ready);
    $this->assertSame('ready', $stored->get('status')->getString());
    $this->assertSame('2', $stored->get('version')->getString());
    $this->assertSame('', $stored->get('active_execution')->getString());
    $this->assertSame((string) $workspace->id(), $stored->get('workspace')->getString());
    $this->assertSame((string) $board->id(), $stored->get('board')->getString());

    $events = $this->events();
    $transition = $events[array_key_last($events)];
    $this->assertSame('task.transitioned', $transition['type']);
    $this->assertSame('human', $transition['actor_type']);
    $this->assertSame((string) $ada->id(), $transition['actor_id']);
    $payload = json_decode((string) $transition['payload'], TRUE);
    $this->assertSame([
      'title' => 'Escrever o tema',
      'status' => 'ready',
      'from' => 'backlog',
      'priority' => 'normal',
      'version' => 2,
    ], $payload);

    $cancelled = $this->tasks()->transition($stored, 'cancelled', 2);
    $this->assertSame('cancelled', $this->reload($cancelled)->get('status')->getString());
    $reopened = $this->tasks()->transition($cancelled, 'backlog', 3);
    $this->assertSame('backlog', $this->reload($reopened)->get('status')->getString());
    $this->assertSame('4', $this->reload($reopened)->get('version')->getString());

    $done = $this->reload($reopened);
    $done->set('status', 'done');
    $done->save();
    $again = $this->tasks()->transition($done, 'ready', 4);
    $this->assertSame('ready', $this->reload($again)->get('status')->getString());
    $this->assertSame('5', $this->reload($again)->get('version')->getString());
    $this->assertSame('', $this->reload($again)->get('active_execution')->getString());
  }

  /**
   * Dragging a card cannot enter or leave the execution statuses.
   */
  public function testExecutionStatusesStayOutOfTheHumanCommand(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $task = $this->tasks()->create($board, ['title' => 'Escrever o tema']);
    $before = count($this->events());

    foreach (['in_progress', 'in_review', 'done'] as $target) {
      try {
        $this->tasks()->transition($task, $target, 1);
        $this->fail($target . ' was stored by the human command.');
      }
      catch (TaskTransitionRejectedException) {
        $stored = $this->reload($task);
        $this->assertSame('backlog', $stored->get('status')->getString());
        $this->assertSame('1', $stored->get('version')->getString());
        $this->assertSame('', $stored->get('active_execution')->getString());
      }
    }
    $this->assertCount($before, $this->events());

    $ready = $this->tasks()->transition($task, 'ready', 1);
    try {
      $this->tasks()->transition($ready, 'in_progress', 2);
      $this->fail('A ready task entered in_progress.');
    }
    catch (TaskTransitionRejectedException) {
      $this->assertSame('ready', $this->reload($ready)->get('status')->getString());
      $this->assertSame('2', $this->reload($ready)->get('version')->getString());
    }

    $running = $this->reload($ready);
    $running->set('status', 'in_progress');
    $running->save();
    try {
      $this->tasks()->transition($running, 'ready', 2);
      $this->fail('A human command left in_progress.');
    }
    catch (TaskTransitionRejectedException) {
      $this->assertSame('in_progress', $this->reload($running)->get('status')->getString());
    }

    $review = $this->reload($running);
    $review->set('status', 'in_review');
    $review->save();
    try {
      $this->tasks()->transition($review, 'done', 2);
      $this->fail('A human command approved in_review.');
    }
    catch (TaskTransitionRejectedException) {
      $this->assertSame('in_review', $this->reload($review)->get('status')->getString());
      $this->assertSame('2', $this->reload($review)->get('version')->getString());
    }
  }

  /**
   * A stale version or an active execution leaves the row unchanged.
   */
  public function testConflictAndActiveExecutionDoNotWrite(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $task = $this->tasks()->create($board, ['title' => 'Escrever o tema']);

    try {
      $this->tasks()->transition($task, 'ready', 9);
      $this->fail('A stale transition was stored.');
    }
    catch (TaskVersionConflictException) {
      $this->assertSame('backlog', $this->reload($task)->get('status')->getString());
      $this->assertSame('1', $this->reload($task)->get('version')->getString());
    }

    $busy = $this->reload($task);
    $busy->set('active_execution', 9);
    $busy->save();
    try {
      $this->tasks()->transition($busy, 'ready', 1);
      $this->fail('A task with an execution changed status.');
    }
    catch (TaskTransitionRejectedException) {
      $stored = $this->reload($busy);
      $this->assertSame('backlog', $stored->get('status')->getString());
      $this->assertSame('9', $stored->get('active_execution')->getString());
      $this->assertSame('1', $stored->get('version')->getString());
    }
  }

  /**
   * A failure after the event restores the previous status.
   */
  public function testTransitionRollsBackWithTheEvent(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $task = $this->tasks()->create($board, ['title' => 'Escrever o tema']);
    $before = count($this->events());
    $manager = new class(
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get(MembershipScope::class),
      $this->container->get(HumanActorContextFactory::class),
      $this->container->get(EventStoreInterface::class),
    ) extends TaskManager {

      /**
       * {@inheritdoc}
       */
      protected function afterRecord(Task $task): void {
        throw new \RuntimeException('Roll back the transition.');
      }

    };

    try {
      $manager->transition($task, 'ready', 1);
      $this->fail('The transition committed after a failure.');
    }
    catch (\RuntimeException) {
      $this->assertSame('backlog', $this->reload($task)->get('status')->getString());
      $this->assertSame('1', $this->reload($task)->get('version')->getString());
      $this->assertCount($before, $this->events());
    }
  }

  /**
   * A reader cannot move a task, and an outsider cannot see it.
   */
  public function testViewerCannotTransition(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $viewer = $this->createUserAccount('view', 'view@example.com');
    $outsider = $this->createUserAccount('out', 'out@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->membership($workspace, $viewer, 'viewer');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $task = $this->tasks()->create($board, ['title' => 'Escrever o tema']);

    $this->setCurrentUser($viewer);
    try {
      $this->tasks()->transition($task, 'ready', 1);
      $this->fail('A viewer moved a task.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame('backlog', $this->reload($task)->get('status')->getString());
    }

    $this->setCurrentUser($outsider);
    try {
      $this->tasks()->transition($task, 'ready', 1);
      $this->fail('An outsider moved a task.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertSame('backlog', $this->reload($task)->get('status')->getString());
      $this->assertSame('1', $this->reload($task)->get('version')->getString());
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
