<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_board\Kernel;

use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
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
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies the task editor, its version and the authenticated actor.
 *
 * @group ab_board
 */
class TaskManagerKernelTest extends KernelTestBase {

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
   * Create stores the board workspace and refuses status or execution.
   */
  public function testCreateKeepsStatusAndExecutionOffTheEditor(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $grace = $this->createUserAccount('grace', 'grace@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');

    $task = $this->tasks()->create($board, [
      'title' => ' Escrever o tema ',
      'description' => 'Texto da tarefa',
      'acceptance_criteria' => 'Cabeçalho pronto',
      'priority' => 'high',
      'human_owner' => $grace->id(),
      'assigned_agent' => 15,
      'due_at' => 1790000000,
      'position' => 4,
    ]);

    $stored = $this->reload($task);
    $this->assertSame((string) $workspace->id(), $stored->get('workspace')->getString());
    $this->assertSame((string) $board->id(), $stored->get('board')->getString());
    $this->assertSame('Escrever o tema', (string) $stored->label());
    $this->assertSame('Texto da tarefa', $stored->get('description')->getString());
    $this->assertSame('Cabeçalho pronto', $stored->get('acceptance_criteria')->getString());
    $this->assertSame('backlog', $stored->get('status')->getString());
    $this->assertSame('high', $stored->get('priority')->getString());
    $this->assertSame((string) $grace->id(), $stored->get('human_owner')->getString());
    $this->assertSame('15', $stored->get('assigned_agent')->getString());
    $this->assertSame('1790000000', $stored->get('due_at')->getString());
    $this->assertSame('4', $stored->get('position')->getString());
    $this->assertSame('1', $stored->get('version')->getString());
    $this->assertSame('', $stored->get('active_execution')->getString());

    $events = $this->events();
    $this->assertCount(2, $events);
    $this->assertSame('task.created', $events[1]['type']);
    $this->assertSame('human', $events[1]['actor_type']);
    $this->assertSame((string) $ada->id(), $events[1]['actor_id']);
    $this->assertSame((string) $workspace->uuid(), $events[1]['workspace_id']);
    $this->assertSame((string) $task->uuid(), $events[1]['resource_id']);
    $payload = json_decode((string) $events[1]['payload'], TRUE);
    $this->assertSame([
      'title' => 'Escrever o tema',
      'status' => 'backlog',
      'priority' => 'high',
      'version' => 1,
    ], $payload);

    $this->assertRejected($board, ['title' => 'Fora', 'status' => 'done']);
    $this->assertRejected($board, ['title' => 'Fora', 'workspace' => 99]);
    $this->assertRejected($board, ['title' => 'Fora', 'active_execution' => 3]);
    $this->assertRejected($board, ['title' => 'Fora', 'author_id' => 9]);
    $this->assertRejected($board, ['title' => 'Fora', 'version' => 9]);
    $this->assertSame('backlog', $this->reload($task)->get('status')->getString());
    $this->assertSame('', $this->reload($task)->get('active_execution')->getString());
    $this->assertCount(1, $this->taskIds());
    $this->assertCount(2, $this->events());
  }

  /**
   * An edit bumps the version and a stale edit changes nothing.
   */
  public function testUpdateIncrementsVersionAndRejectsStaleEdits(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $member = $this->createUserAccount('member', 'member@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->membership($workspace, $member, 'member');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
    $task = $this->tasks()->create($board, [
      'title' => 'Escrever o tema',
      'priority' => 'normal',
    ]);

    $this->setCurrentUser($member);
    $updated = $this->tasks()->update($task, [
      'title' => 'Revisado',
      'description' => 'Novo texto',
    ], 1);
    $stored = $this->reload($updated);
    $this->assertSame('Revisado', (string) $stored->label());
    $this->assertSame('Novo texto', $stored->get('description')->getString());
    $this->assertSame('backlog', $stored->get('status')->getString());
    $this->assertSame('', $stored->get('active_execution')->getString());
    $this->assertSame('2', $stored->get('version')->getString());
    $this->assertSame((string) $workspace->id(), $stored->get('workspace')->getString());

    $events = $this->events();
    $this->assertSame('task.updated', $events[2]['type']);
    $this->assertSame('human', $events[2]['actor_type']);
    $this->assertSame((string) $member->id(), $events[2]['actor_id']);
    $payload = json_decode((string) $events[2]['payload'], TRUE);
    $this->assertSame(2, $payload['version']);
    $this->assertSame('backlog', $payload['status']);

    try {
      $this->tasks()->update($stored, [
        'status' => 'done',
        'active_execution' => 8,
        'workspace' => 99,
      ], 2);
      $this->fail('The editor changed status, execution or workspace.');
    }
    catch (\InvalidArgumentException) {
      $this->assertSame('Revisado', (string) $this->reload($task)->label());
      $this->assertSame('2', $this->reload($task)->get('version')->getString());
      $this->assertSame('backlog', $this->reload($task)->get('status')->getString());
    }

    try {
      $this->tasks()->update($stored, ['title' => 'Atrasado'], 1);
      $this->fail('A stale edit was stored.');
    }
    catch (TaskVersionConflictException) {
      $this->assertSame('Revisado', (string) $this->reload($task)->label());
      $this->assertSame('2', $this->reload($task)->get('version')->getString());
    }

    $same = $this->tasks()->update($stored, ['title' => 'Revisado'], 2);
    $this->assertSame('2', $same->get('version')->getString());
    $this->assertCount(3, $this->events());
  }

  /**
   * A failure after the event removes the task and the event.
   */
  public function testCreateRollsBackWithTheEvent(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', 'Texto');
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
        throw new \RuntimeException('Roll back the task.');
      }

    };

    try {
      $manager->create($board, ['title' => 'Some']);
      $this->fail('The task create committed after a failure.');
    }
    catch (\RuntimeException) {
      $this->assertSame([], $this->taskIds());
      $this->assertCount(1, $this->events());
      $this->assertCount(1, $this->outboxIds());
    }
  }

  /**
   * A reader cannot create or edit a task.
   */
  public function testViewerCannotEditTasks(): void {
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
      $this->tasks()->create($board, ['title' => 'Outra']);
      $this->fail('A viewer created a task.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertCount(1, $this->taskIds());
    }
    try {
      $this->tasks()->update($task, ['title' => 'Trocado'], 1);
      $this->fail('A viewer edited a task.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame('Escrever o tema', (string) $this->reload($task)->label());
    }

    $this->setCurrentUser($outsider);
    try {
      $this->tasks()->create($board, ['title' => 'Fora']);
      $this->fail('An outsider created a task.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertCount(1, $this->taskIds());
    }
  }

  /**
   * Asserts a forbidden create leaves the stored tasks untouched.
   *
   * @param \Drupal\ab_board\Entity\Board $board
   *   Board that would receive the task.
   * @param array<string, mixed> $fields
   *   Editor input that must be rejected.
   */
  private function assertRejected(Board $board, array $fields): void {
    try {
      $this->tasks()->create($board, $fields);
      $this->fail('The editor stored a forbidden field.');
    }
    catch (\InvalidArgumentException) {
      $this->assertCount(1, $this->taskIds());
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
   * Returns stored task ids.
   *
   * @return string[]
   *   Task ids.
   */
  private function taskIds(): array {
    $ids = $this->container->get('entity_type.manager')->getStorage('ab_task')->getQuery()
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
    $ids = $this->container->get('database')->select('ab_outbox', 'o')
      ->fields('o', ['event_id'])
      ->execute()
      ->fetchCol();
    return array_values($ids);
  }

}
