<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the task screen rejects a dependency cycle.
 *
 * @group ab_ui
 */
class TaskDependencyUiTest extends BrowserTestBase {

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
   * A shortcut is stored. A cycle is not, and the block is derived.
   */
  public function testCycleIsRejectedAndBlockIsDerived(): void {
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
    $this->createTask($board, 'Alfa');
    $this->createTask($board, 'Beta');
    $this->createTask($board, 'Gama');
    $alfa = $this->taskByTitle('Alfa');
    $beta = $this->taskByTitle('Beta');
    $gama = $this->taskByTitle('Gama');

    $this->drupalGet('tasks/' . $alfa->uuid());
    $this->assertSession()->optionNotExists('dependency', $alfa->uuid());
    $this->assertSession()->pageTextNotContains('Bloqueada');
    $this->submitForm([
      'dependency' => $beta->uuid(),
    ], 'Adicionar dependência');
    $this->assertSession()->pageTextContains('Dependência adicionada.');
    $this->assertSession()->pageTextContains('Bloqueada');
    $this->assertSession()->pageTextContains('Beta');
    $this->assertSame('2', $this->reload($alfa)->get('version')->getString());

    $this->drupalGet('tasks/' . $beta->uuid());
    $this->submitForm([
      'dependency' => $gama->uuid(),
    ], 'Adicionar dependência');
    $this->assertSession()->pageTextContains('Dependência adicionada.');

    $this->drupalGet('tasks/' . $gama->uuid());
    $this->submitForm([
      'dependency' => $alfa->uuid(),
    ], 'Adicionar dependência');
    $this->assertSession()->pageTextContains('Essa dependência forma um ciclo.');
    $this->assertSession()->pageTextNotContains('Dependência adicionada.');
    $this->assertSame('1', $this->reload($gama)->get('version')->getString());

    $this->drupalGet('tasks/' . $alfa->uuid());
    $this->submitForm([], 'Remover Beta');
    $this->assertSession()->pageTextContains('Dependência removida.');
    $this->assertSession()->pageTextNotContains('Bloqueada');
    $this->assertSame('3', $this->reload($alfa)->get('version')->getString());

    $this->container->get('current_user')->setAccount($ada);
    $manager = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $manager);
    $manager->addMember($workspace, $viewer->getAccountName(), 'viewer');
    $this->drupalLogin($viewer);
    $this->drupalGet('tasks/' . $beta->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Bloqueada');
    $this->assertSession()->pageTextContains('Gama');
    $this->assertSession()->buttonNotExists('Adicionar dependência');
    $this->assertSession()->buttonNotExists('Remover Gama');
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

}
