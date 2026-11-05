<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Column;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies column editing and the member select on the board.
 *
 * @group ab_ui
 */
class BoardColumnsUiTest extends BrowserTestBase {

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
   * The board screen creates a column and keeps execution columns locked.
   */
  public function testBoardScreenManagesColumnsAndListsMembers(): void {
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
    $this->container->get('current_user')->setAccount($ada);
    $manager = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $manager);
    $manager->addMember($workspace, $grace->getAccountName(), 'member');

    $this->drupalGet('boards/add');
    $this->submitForm([
      'name' => 'Quadro Ada',
      'description' => 'Texto Ada',
    ], 'Criar board');
    $board = $this->boardByName('Quadro Ada');
    $this->drupalGet('boards/' . $board->uuid() . '/tasks/add');
    $this->assertSession()->selectExists('Responsável');
    $this->assertSession()->optionExists('Responsável', $grace->getAccountName());
    $this->assertSession()->optionNotExists('Responsável', $viewer->getAccountName());
    $this->submitForm([
      'title' => 'Escrever o tema',
      'human_owner' => $grace->id(),
    ], 'Criar tarefa');
    $task = $this->taskByTitle('Escrever o tema');
    $this->assertSame((string) $grace->id(), $task->get('human_owner')->getString());

    $this->drupalGet('boards/' . $board->uuid() . '/tasks');
    $this->assertSession()->pageTextContains('Colunas');
    $this->assertSession()->buttonExists('Criar coluna');
    $this->assertSession()->buttonNotExists('Arquivar Backlog');
    $this->assertSession()->pageTextContains('Entra quando um agente assume a tarefa.');
    $this->assertSession()->responseContains('data-targets="ready cancelled"');
    $this->submitForm([
      'new_name' => 'Desenho',
    ], 'Criar coluna');
    $this->assertSession()->pageTextContains('Coluna criada.');
    $column = $this->columnByName('Desenho');
    $content = $this->getSession()->getPage()->getContent();
    $this->assertMatchesRegularExpression('/data-targets="[^"]*' . preg_quote($column->get('machine')->getString(), '/') . '[^"]*"/', $content);
    $this->assertDoesNotMatchRegularExpression('/data-targets="[^"]*in_progress/', $content);
    $this->assertSession()->elementTextContains('xpath', $this->column('Backlog'), 'Escrever o tema');
    $this->submitForm([], 'Desenho');
    $this->assertSession()->pageTextContains('Tarefa atualizada.');
    $this->assertSame($column->get('machine')->getString(), $this->reload($task)->get('status')->getString());
    $this->assertSession()->elementTextContains('xpath', $this->column('Desenho'), 'Escrever o tema');
    $this->assertSession()->elementTextNotContains('xpath', $this->column('Backlog'), 'Escrever o tema');

    $this->submitForm([], 'Arquivar coluna');
    $this->assertSession()->pageTextContains('A coluna ainda tem tarefas.');
    $this->submitForm([], 'Devolver ao backlog');
    $this->assertSession()->pageTextContains('Tarefa devolvida ao backlog.');
    $this->submitForm([], 'Arquivar coluna');
    $this->assertSession()->pageTextContains('Coluna arquivada.');
    $this->assertSession()->elementNotExists('xpath', $this->column('Desenho'));

    $this->submitForm([
      'columns[ready][name]' => 'Liberada',
    ], 'Salvar colunas');
    $this->assertSession()->pageTextContains('Colunas salvas.');
    $this->assertSession()->elementExists('xpath', $this->column('Liberada'));

    $manager->addMember($workspace, $viewer->getAccountName(), 'viewer');
    $this->drupalLogin($viewer);
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $workspace->uuid(),
    ], 'Usar este workspace');
    $this->drupalGet('boards/' . $board->uuid() . '/tasks');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonNotExists('Criar coluna');
    $this->assertSession()->pageTextNotContains('Nova coluna');
    $this->assertSession()->elementExists('xpath', $this->column('Liberada'));
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
   * Loads the column stored with the given name.
   */
  private function columnByName(string $name): Column {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_column');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('name', $name)->execute();
    $column = $storage->load(reset($ids));
    $this->assertInstanceOf(Column::class, $column);
    return $column;
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

}
