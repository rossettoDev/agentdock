<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the task screen cannot start an execution.
 *
 * @group ab_ui
 */
class TaskTransitionUiTest extends BrowserTestBase {

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
   * Liberar and cancelar persist. Execution statuses are not buttons.
   */
  public function testScreenReleasesWithoutEnteringExecution(): void {
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
    $this->assertSession()->buttonExists('Liberar');
    $this->assertSession()->buttonExists('Cancelar');
    $this->assertSession()->buttonNotExists('Em andamento');
    $this->assertSession()->buttonNotExists('Em revisão');
    $this->assertSession()->buttonNotExists('Concluída');
    $this->assertSession()->fieldNotExists('status');

    $this->submitForm([], 'Liberar');
    $this->assertSession()->pageTextContains('Tarefa liberada.');
    $this->assertSession()->pageTextContains('Pronta');
    $this->assertSession()->buttonNotExists('Liberar');
    $this->assertSession()->buttonNotExists('Em andamento');
    $this->assertSession()->buttonExists('Devolver ao backlog');
    $stored = $this->reload($task);
    $this->assertSame('ready', $stored->get('status')->getString());
    $this->assertSame('2', $stored->get('version')->getString());
    $this->assertSame('', $stored->get('active_execution')->getString());

    $this->submitForm([], 'Cancelar');
    $this->assertSession()->pageTextContains('Tarefa cancelada.');
    $this->assertSession()->pageTextContains('Cancelada');
    $this->assertSession()->buttonExists('Reabrir');
    $this->assertSession()->buttonNotExists('Em andamento');
    $this->assertSame('cancelled', $this->reload($task)->get('status')->getString());

    $this->submitForm([], 'Reabrir');
    $this->assertSession()->pageTextContains('Tarefa reaberta.');
    $this->assertSession()->pageTextContains('Backlog');
    $this->assertSame('backlog', $this->reload($task)->get('status')->getString());
    $this->assertSame('4', $this->reload($task)->get('version')->getString());

    $this->container->get('current_user')->setAccount($ada);
    $manager = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $manager);
    $manager->addMember($workspace, $viewer->getAccountName(), 'viewer');
    $this->drupalLogin($viewer);
    $this->drupalGet('tasks/' . $task->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Backlog');
    $this->assertSession()->buttonNotExists('Liberar');
    $this->assertSession()->buttonNotExists('Cancelar');
    $this->assertSession()->buttonNotExists('Em andamento');
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

}
