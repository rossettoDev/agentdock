<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies a stale task form does not overwrite a newer write.
 *
 * @group ab_ui
 */
class TaskVersionUiTest extends BrowserTestBase {

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
   * A second write wins. The open form reports the conflict.
   */
  public function testStaleFormDoesNotOverwrite(): void {
    $ada = $this->drupalCreateUser();
    $this->drupalLogin($ada);
    $this->drupalGet('workspaces/add');
    $this->submitForm([
      'name' => 'Casa Ada',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $this->assertInstanceOf(Workspace::class, $this->workspaceByName('Casa Ada'));
    $this->drupalGet('boards/add');
    $this->submitForm([
      'name' => 'Quadro Ada',
      'description' => 'Texto Ada',
    ], 'Criar board');
    $board = $this->boardByName('Quadro Ada');
    $this->createTask($board, 'Alfa');
    $this->createTask($board, 'Beta');
    $alfa = $this->taskByTitle('Alfa');
    $beta = $this->taskByTitle('Beta');

    $this->drupalGet('tasks/' . $alfa->uuid());
    $this->assertSession()->hiddenFieldValueEquals('version', '1');
    $alfa->set('title', 'Pelo serviço');
    $alfa->set('version', 2);
    $alfa->save();
    $this->submitForm([], 'Liberar');
    $this->assertSession()->pageTextContains('A tarefa foi alterada. Recarregue e tente de novo.');
    $this->assertSession()->pageTextNotContains('Tarefa liberada.');
    $stored = $this->reload($alfa);
    $this->assertSame('Pelo serviço', (string) $stored->label());
    $this->assertSame('backlog', $stored->get('status')->getString());
    $this->assertSame('2', $stored->get('version')->getString());

    $this->drupalGet('tasks/' . $stored->uuid());
    $this->assertSession()->hiddenFieldValueEquals('version', '2');
    $stored->set('version', 3);
    $stored->save();
    $this->submitForm([
      'dependency' => $beta->uuid(),
    ], 'Adicionar dependência');
    $this->assertSession()->pageTextContains('A tarefa foi alterada. Recarregue e tente de novo.');
    $this->assertSession()->pageTextNotContains('Dependência adicionada.');
    $this->assertSession()->pageTextNotContains('Bloqueada');
    $this->assertSame('3', $this->reload($alfa)->get('version')->getString());
    $this->assertSame('backlog', $this->reload($alfa)->get('status')->getString());
    $this->assertSame([], $this->pairs());
  }

  /**
   * Creates a task through the board form.
   */
  private function createTask(Board $board, string $title): void {
    $this->drupalGet('boards/' . $board->uuid() . '/tasks/add');
    $this->submitForm([
      'title' => $title,
    ], 'Criar tarefa');
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

}
