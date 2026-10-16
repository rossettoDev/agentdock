<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\Core\Database\Connection;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the board task list as fixed status columns.
 *
 * @group ab_ui
 */
class TaskKanbanUiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ab_core',
    'ab_board',
    'ab_agent',
    'ab_communication',
    'ab_ui',
  ];

  /**
   * Cards follow the stored status, and cancelled stays in its filter.
   */
  public function testColumnsMatchPersistedStatus(): void {
    $this->drupalGet('boards');
    $this->assertSession()->statusCodeEquals(403);

    $ada = $this->drupalCreateUser();
    $grace = $this->drupalCreateUser();
    $viewer = $this->drupalCreateUser();

    $this->drupalLogin($ada);
    $this->drupalGet('workspaces/add');
    $this->submitForm([
      'name' => 'Casa Ada',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $workspace = $this->workspaceByName('Casa Ada');
    $this->drupalGet('boards/add');
    $this->submitForm([
      'name' => 'Quadro Ada',
      'description' => 'Texto Ada',
    ], 'Criar board');
    $board = $this->boardByName('Quadro Ada');
    $this->drupalGet('boards/add');
    $this->submitForm([
      'name' => 'Outro quadro',
      'description' => 'Fora da lista',
    ], 'Criar board');
    $other = $this->boardByName('Outro quadro');

    $due = (new \DateTimeImmutable(
      '2026-10-20 00:00:00',
      new \DateTimeZone('America/Sao_Paulo'),
    ))->getTimestamp();
    $backlog = $this->storeTask($board, 'Material do backlog', 'backlog', [
      'priority' => 'high',
      'human_owner' => $ada->id(),
      'due_at' => $due,
    ]);
    $ready = $this->storeTask($board, 'Tema pronto', 'ready');
    $this->storeTask($board, 'Execução gravada', 'in_progress', [
      'assigned_agent' => 42,
    ]);
    $this->storeTask($board, 'Revisão gravada', 'in_review');
    $this->storeTask($board, 'Entrega gravada', 'done');
    $this->storeTask($board, 'Fora do quadro', 'cancelled');
    $this->storeTask($other, 'Tarefa de fora', 'backlog');
    $database = $this->container->get('database');
    $this->assertInstanceOf(Connection::class, $database);
    $database->insert('ab_task_dependency')->fields([
      'workspace' => (int) $workspace->id(),
      'board' => (int) $board->id(),
      'task_id' => (int) $backlog->id(),
      'dependency_id' => (int) $ready->id(),
    ])->execute();

    $this->drupalGet('boards/' . $board->uuid() . '/tasks');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Criar tarefa');
    $this->assertSession()->linkExists('Canceladas');
    $this->assertSession()->pageTextNotContains('Fora do quadro');
    $this->assertSession()->pageTextNotContains('Tarefa de fora');
    $this->assertSession()->pageTextNotContains('Nova tarefa');
    $this->assertSession()->buttonExists('Liberar');
    $this->assertSession()->buttonNotExists('Em andamento');
    $this->assertSession()->elementExists('xpath', $this->column('Backlog') . '//article[@draggable="true"]');
    $this->assertSession()->elementNotExists('xpath', $this->column('Em andamento') . '//article[@draggable="true"]');
    $this->assertSession()->elementTextContains(
      'xpath',
      $this->column('Backlog'),
      'Material do backlog',
    );
    $this->assertSession()->elementTextContains(
      'xpath',
      $this->column('Backlog'),
      'Alta',
    );
    $this->assertSession()->elementTextContains(
      'xpath',
      $this->column('Backlog'),
      'Prazo: 20/10/2026',
    );
    $this->assertSession()->elementTextContains(
      'xpath',
      $this->column('Backlog'),
      'Responsável: ' . $ada->getAccountName(),
    );
    $this->assertSession()->elementTextContains(
      'xpath',
      $this->column('Backlog'),
      'Bloqueada',
    );
    $this->assertSession()->elementTextNotContains(
      'xpath',
      $this->column('Pronta'),
      'Bloqueada',
    );
    $this->assertSession()->elementTextContains(
      'xpath',
      $this->column('Pronta'),
      'Tema pronto',
    );
    $this->assertSession()->elementTextContains(
      'xpath',
      $this->column('Em andamento'),
      'Execução gravada',
    );
    $this->assertSession()->elementTextContains(
      'xpath',
      $this->column('Em andamento'),
      'Agente atribuído',
    );
    $this->assertSession()->elementTextNotContains(
      'xpath',
      $this->column('Em andamento'),
      '42',
    );
    $this->assertSession()->elementTextContains(
      'xpath',
      $this->column('Em revisão'),
      'Revisão gravada',
    );
    $this->assertSession()->elementTextContains(
      'xpath',
      $this->column('Concluída'),
      'Entrega gravada',
    );

    $this->drupalGet('boards/' . $board->uuid() . '/tasks', [
      'query' => ['cancelled' => '1'],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Fora do quadro');
    $this->assertSession()->pageTextContains('Canceladas');
    $this->assertSession()->linkExists('Voltar ao quadro');
    $this->assertSession()->pageTextNotContains('Material do backlog');
    $this->assertSession()->pageTextNotContains('Tema pronto');
    $this->assertSession()->pageTextNotContains('Em andamento');
    $this->assertSession()->responseNotContains('ab-kanban__column');

    $this->drupalGet('boards/' . $board->uuid() . '/tasks', [
      'query' => ['workspace' => $other->uuid()],
    ]);
    $this->assertSession()->pageTextContains('Material do backlog');
    $this->assertSession()->pageTextNotContains('Tarefa de fora');
    $this->drupalGet('tasks/' . $backlog->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('workspaces');
    $this->assertSession()->pageTextContains('Workspace atual: Casa Ada');

    $this->drupalLogin($grace);
    $this->drupalGet('workspaces/add');
    $this->submitForm([
      'name' => 'Casa Grace',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $this->drupalGet('boards/' . $board->uuid() . '/tasks');
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Material do backlog');
    $this->assertSession()->pageTextNotContains('Fora do quadro');

    $this->container->get('current_user')->setAccount($ada);
    $manager = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $manager);
    $manager->addMember($workspace, $viewer->getAccountName(), 'viewer');

    $this->drupalLogin($viewer);
    $this->drupalGet('boards/' . $board->uuid() . '/tasks');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Material do backlog');
    $this->assertSession()->pageTextContains('Bloqueada');
    $this->assertSession()->pageTextNotContains('Tarefa de fora');
    $this->assertSession()->pageTextNotContains('Fora do quadro');
    $this->assertSession()->linkNotExists('Criar tarefa');
    $this->assertSession()->buttonNotExists('Liberar');
    $this->assertSession()->elementNotExists('xpath', '//article[@draggable="true"]');
    $this->drupalGet('tasks/' . $backlog->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('workspaces');
    $this->assertSession()->pageTextNotContains('Workspace atual: Casa Ada');
  }

  /**
   * XPath of the column whose heading is the given label.
   */
  private function column(string $label): string {
    $section = 'section[contains(@class, "ab-kanban__column")]';
    $heading = 'h3[contains(normalize-space(.), "' . $label . '")]';
    return '//' . $section . '[.//' . $heading . ']';
  }

  /**
   * Loads the workspace stored with the given name.
   */
  private function workspaceByName(string $name): Workspace {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_workspace');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('name', $name)->execute();
    $workspace = $storage->load(reset($ids));
    $this->assertInstanceOf(Workspace::class, $workspace);
    return $workspace;
  }

  /**
   * Loads the board stored with the given name.
   */
  private function boardByName(string $name): Board {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_board');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('name', $name)->execute();
    $board = $storage->load(reset($ids));
    $this->assertInstanceOf(Board::class, $board);
    return $board;
  }

  /**
   * Stores a task on the board without going through the editor.
   *
   * @param \Drupal\ab_board\Entity\Board $board
   *   Board that owns the task.
   * @param string $title
   *   Stored title.
   * @param string $status
   *   Stored status.
   * @param array<string, mixed> $extra
   *   Field values beyond the title and status.
   */
  private function storeTask(
    Board $board,
    string $title,
    string $status,
    array $extra = [],
  ): Task {
    $task = Task::create([
      'board' => $board->id(),
      'title' => $title,
      'status' => $status,
    ] + $extra);
    $task->save();
    $this->assertInstanceOf(Task::class, $task);
    return $task;
  }

}
