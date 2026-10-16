<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_board\Kernel;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskSchemaInstaller;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies the task schema and the workspace copied from the board.
 *
 * @group ab_board
 */
class TaskEntityKernelTest extends KernelTestBase {

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
  }

  /**
   * The schema installs before agent and execution entities exist.
   */
  public function testSchemaInstallsWithoutFutureEntities(): void {
    $manager = $this->container->get('entity_type.manager');
    $this->assertNull($manager->getDefinition('ab_agent', FALSE));
    $this->assertNull($manager->getDefinition('ab_execution', FALSE));

    $message = TaskSchemaInstaller::install();
    $this->assertSame('Installed the task entity schema.', $message);
    $again = TaskSchemaInstaller::install();
    $this->assertSame('Task schema already present.', $again);

    $schema = $this->container->get('database')->schema();
    $this->assertTrue($schema->tableExists('ab_task'));
    $this->assertTrue($schema->fieldExists('ab_task', 'assigned_agent'));
    $this->assertTrue($schema->fieldExists('ab_task', 'active_execution'));
    $this->assertFalse($schema->fieldExists('ab_task', 'assigned_agent_target_id'));
    $this->assertFalse($schema->fieldExists('ab_task', 'active_execution_target_id'));
    $this->assertTrue($schema->indexExists('ab_task', 'workspace_board_status_position'));
    $this->assertSame('NO', $this->column('workspace')['is_nullable']);
    $this->assertSame('NO', $this->column('board')['is_nullable']);
    $this->assertSame('YES', $this->column('assigned_agent')['is_nullable']);
    $this->assertSame('YES', $this->column('active_execution')['is_nullable']);
    $this->assertSame('bigint', $this->column('assigned_agent')['data_type']);
    $this->assertSame('bigint', $this->column('active_execution')['data_type']);

    $definitions = $this->container->get('entity_field.manager')->getFieldStorageDefinitions('ab_task');
    $this->assertSame('integer', $definitions['assigned_agent']->getType());
    $this->assertSame('integer', $definitions['active_execution']->getType());
    $this->assertSame('entity_reference', $definitions['board']->getType());
    $this->assertSame('ab_board', $definitions['board']->getSetting('target_type'));
    $this->assertSame('entity_reference', $definitions['human_owner']->getType());
    $this->assertSame('user', $definitions['human_owner']->getSetting('target_type'));
  }

  /**
   * A new task stores the board workspace and keeps future ids as integers.
   */
  public function testWorkspaceComesFromTheBoard(): void {
    $this->installTaskSchema();
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $grace = $this->createUserAccount('grace', 'grace@example.com');
    $member = $this->createUserAccount('member', 'member@example.com');
    $viewer = $this->createUserAccount('view', 'view@example.com');
    $ada_workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $grace_workspace = $this->creator()->create($grace, 'Casa Grace', 'America/Sao_Paulo');
    $this->membership($ada_workspace, $member, 'member');
    $this->membership($ada_workspace, $viewer, 'viewer');
    $ada_board = $this->board($ada_workspace, 'Quadro Ada');
    $other_board = $this->board($ada_workspace, 'Outro quadro');
    $grace_board = $this->board($grace_workspace, 'Quadro Grace');
    $this->setCurrentUser($ada);

    $task = Task::create([
      'board' => $ada_board->id(),
      'title' => 'Escrever o tema',
      'description' => 'Texto da tarefa',
      'acceptance_criteria' => 'Cabeçalho pronto',
      'human_owner' => $ada->id(),
      'assigned_agent' => 15,
      'active_execution' => 9,
      'due_at' => 1790000000,
      'position' => 20,
    ]);
    $task->save();

    $stored = $this->reload($task);
    $this->assertSame((string) $ada_workspace->id(), $stored->get('workspace')->getString());
    $this->assertSame((string) $ada_board->id(), $stored->get('board')->getString());
    $this->assertSame('Escrever o tema', (string) $stored->label());
    $this->assertSame('Texto da tarefa', $stored->get('description')->getString());
    $this->assertSame('Cabeçalho pronto', $stored->get('acceptance_criteria')->getString());
    $this->assertSame('backlog', $stored->get('status')->getString());
    $this->assertSame('normal', $stored->get('priority')->getString());
    $this->assertSame((string) $ada->id(), $stored->get('human_owner')->getString());
    $this->assertSame('15', $stored->get('assigned_agent')->getString());
    $this->assertSame('9', $stored->get('active_execution')->getString());
    $this->assertSame('1790000000', $stored->get('due_at')->getString());
    $this->assertSame('20', $stored->get('position')->getString());
    $this->assertSame('1', $stored->get('version')->getString());
    $this->assertSame('15', $this->rawColumn($stored, 'assigned_agent'));
    $this->assertSame('9', $this->rawColumn($stored, 'active_execution'));

    $empty = Task::create([
      'board' => $ada_board->id(),
      'title' => 'Sem agente',
    ]);
    $empty->save();
    $this->assertNull($this->rawColumn($empty, 'assigned_agent'));
    $this->assertNull($this->rawColumn($empty, 'active_execution'));
    $this->assertNull($this->rawColumn($empty, 'human_owner'));
    $this->assertNull($this->rawColumn($empty, 'due_at'));

    $mismatch = Task::create([
      'board' => $ada_board->id(),
      'workspace' => $grace_workspace->id(),
      'title' => 'Fora',
    ]);
    try {
      $mismatch->save();
      $this->fail('A task stored a workspace other than its board.');
    }
    catch (EntityStorageException) {
      $this->assertCount(2, $this->taskIds());
    }

    $stored->set('board', $other_board->id());
    try {
      $stored->save();
      $this->fail('A task moved to another board.');
    }
    catch (EntityStorageException) {
      $reloaded = $this->reload($task);
      $this->assertSame((string) $ada_board->id(), $reloaded->get('board')->getString());
      $this->assertSame((string) $ada_workspace->id(), $reloaded->get('workspace')->getString());
    }

    $stored = $this->reload($task);
    $stored->set('board', $grace_board->id());
    $stored->set('workspace', $grace_workspace->id());
    try {
      $stored->save();
      $this->fail('A task moved into another workspace.');
    }
    catch (EntityStorageException) {
      $reloaded = $this->reload($task);
      $this->assertSame((string) $ada_board->id(), $reloaded->get('board')->getString());
      $this->assertSame('Escrever o tema', (string) $reloaded->label());
    }

    $stored = $this->reload($task);
    $stored->set('status', 'flying');
    try {
      $stored->save();
      $this->fail('A task stored an unknown status.');
    }
    catch (EntityStorageException) {
      $this->assertSame('backlog', $this->reload($task)->get('status')->getString());
    }

    $this->setCurrentUser($grace);
    $grace_task = Task::create([
      'board' => $grace_board->id(),
      'title' => 'Tarefa Grace',
    ]);
    $grace_task->save();

    $this->setCurrentUser($ada);
    $ada_ids = $this->visibleTaskIds();
    $this->setCurrentUser($grace);
    $grace_ids = $this->visibleTaskIds();
    $this->assertNotContains((string) $grace_task->id(), $ada_ids);
    $this->assertContains((string) $task->id(), $ada_ids);
    $this->assertSame([(string) $grace_task->id()], $grace_ids);

    $visible = $this->reload($task);
    $this->assertTrue($visible->access('view', $viewer));
    $this->assertFalse($visible->access('update', $viewer));
    $this->assertTrue($visible->access('view', $member));
    $this->assertTrue($visible->access('update', $member));
    $this->assertFalse($visible->access('view', $grace));
    $this->assertFalse($visible->access('create', $ada));
  }

  /**
   * Installs the task schema through the update path.
   */
  private function installTaskSchema(): void {
    $this->assertSame('Installed the task entity schema.', TaskSchemaInstaller::install());
  }

  /**
   * Reads one information_schema column of ab_task.
   *
   * @return array<string, string>
   *   data_type and is_nullable.
   */
  private function column(string $name): array {
    $connection = $this->container->get('database');
    $table = $connection->getPrefix() . 'ab_task';
    $row = $connection->query(
      'SELECT data_type, is_nullable FROM information_schema.columns WHERE table_name = :table AND column_name = :column',
      [':table' => $table, ':column' => $name],
    )->fetchAssoc();
    $this->assertIsArray($row);
    return $row;
  }

  /**
   * Reads one stored column, preserving NULL.
   */
  private function rawColumn(Task $task, string $column): ?string {
    $value = $this->container->get('database')->select('ab_task', 't')
      ->fields('t', [$column])
      ->condition('id', $task->id())
      ->execute()
      ->fetchField();
    if ($value === FALSE || $value === NULL) {
      return NULL;
    }
    return (string) $value;
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
   * Stores a board in the workspace.
   */
  private function board(Workspace $workspace, string $name): Board {
    $board = Board::create([
      'workspace' => $workspace->id(),
      'name' => $name,
      'description' => '',
    ]);
    $board->save();
    return $board;
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
   * Returns task ids visible to the current account.
   *
   * @return string[]
   *   Task ids.
   */
  private function visibleTaskIds(): array {
    $ids = $this->container->get('entity_type.manager')->getStorage('ab_task')->getQuery()
      ->accessCheck(TRUE)
      ->sort('id')
      ->execute();
    return array_values($ids);
  }

}
