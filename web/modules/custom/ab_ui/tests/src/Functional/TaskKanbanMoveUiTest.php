<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies keyboard movement on the board uses the transition command.
 *
 * @group ab_ui
 */
class TaskKanbanMoveUiTest extends BrowserTestBase {

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
   * A stale board button leaves the card in the stored column.
   */
  public function testStaleMoveStaysInTheStoredColumn(): void {
    $ada = $this->drupalCreateUser();
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
    $this->drupalGet('boards/' . $board->uuid() . '/tasks/add');
    $this->submitForm([
      'title' => 'Escrever o tema',
    ], 'Criar tarefa');
    $task = $this->taskByTitle('Escrever o tema');

    $this->drupalGet('boards/' . $board->uuid() . '/tasks');
    $this->assertSession()->buttonExists('Liberar');
    $this->assertSession()->buttonExists('Cancelar');
    $this->assertSession()->buttonNotExists('Em andamento');
    $this->assertSession()->pageTextContains('Mover');
    $this->assertSession()->responseContains('data-targets="ready cancelled"');
    $stored = $this->reload($task);
    $stored->set('version', 2);
    $stored->save();
    $this->submitForm([], 'Liberar');
    $this->assertSession()->pageTextContains('A tarefa foi alterada. Recarregue e tente de novo.');
    $this->assertSession()->pageTextNotContains('Tarefa liberada.');
    $this->assertSession()->elementTextContains('xpath', $this->column('Backlog'), 'Escrever o tema');
    $this->assertSession()->elementTextNotContains('xpath', $this->column('Pronta'), 'Escrever o tema');
    $this->assertSame('backlog', $this->reload($task)->get('status')->getString());
    $this->assertSame('2', $this->reload($task)->get('version')->getString());

    $this->drupalGet('boards/' . $board->uuid() . '/tasks');
    $this->submitForm([], 'Liberar');
    $this->assertSession()->pageTextContains('Tarefa liberada.');
    $this->assertSession()->elementTextContains('xpath', $this->column('Pronta'), 'Escrever o tema');
    $this->assertSession()->elementTextNotContains('xpath', $this->column('Backlog'), 'Escrever o tema');
    $this->assertSame('ready', $this->reload($task)->get('status')->getString());
    $this->assertSame('3', $this->reload($task)->get('version')->getString());

    $this->submitForm([], 'Cancelar');
    $this->assertSession()->pageTextContains('Tarefa cancelada.');
    $this->assertSession()->pageTextContains('Escrever o tema');
    $this->assertSession()->responseNotContains('ab-kanban__column');
    $this->assertSame('cancelled', $this->reload($task)->get('status')->getString());
    $this->submitForm([], 'Reabrir');
    $this->assertSession()->pageTextContains('Tarefa reaberta.');
    $this->assertSession()->elementTextContains('xpath', $this->column('Backlog'), 'Escrever o tema');
    $this->assertSame('backlog', $this->reload($task)->get('status')->getString());

    $this->storeTask($board, 'Execução gravada', 'in_progress');
    $this->drupalGet('boards/' . $board->uuid() . '/tasks');
    $this->assertSession()->elementNotExists('xpath', $this->column('Em andamento') . '//article[@draggable="true"]');
    $this->assertSession()->buttonNotExists('Em andamento');

    $this->container->get('current_user')->setAccount($ada);
    $manager = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $manager);
    $manager->addMember($workspace, $viewer->getAccountName(), 'viewer');
    $this->drupalLogin($viewer);
    $this->drupalGet('boards/' . $board->uuid() . '/tasks');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonNotExists('Liberar');
    $this->assertSession()->buttonNotExists('Cancelar');
    $this->assertSession()->pageTextNotContains('Mover');
    $this->assertSession()->elementNotExists('xpath', '//article[@draggable="true"]');
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
   * Loads the task stored with the given title.
   */
  private function taskByTitle(string $title): Task {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_task');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('title', $title)->execute();
    $task = $storage->load(reset($ids));
    $this->assertInstanceOf(Task::class, $task);
    return $task;
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
   * Stores a task on the board without going through the editor.
   *
   * @param \Drupal\ab_board\Entity\Board $board
   *   Board that owns the task.
   * @param string $title
   *   Stored title.
   * @param string $status
   *   Stored status.
   */
  private function storeTask(Board $board, string $title, string $status): Task {
    $task = Task::create([
      'board' => $board->id(),
      'title' => $title,
      'status' => $status,
    ]);
    $task->save();
    $this->assertInstanceOf(Task::class, $task);
    return $task;
  }

}
