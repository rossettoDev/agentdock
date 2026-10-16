<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the task form and the sanitized detail.
 *
 * @group ab_ui
 */
class TaskScreensUiTest extends BrowserTestBase {

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
   * Stored Markdown is shown without executing script or raw HTML.
   */
  public function testTaskFormPersistsAndEscapesMarkup(): void {
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
    $ada_workspace = $this->workspaceByName('Casa Ada');
    $this->drupalGet('boards/add');
    $this->submitForm([
      'name' => 'Quadro Ada',
      'description' => 'Texto Ada',
    ], 'Criar board');
    $ada_board = $this->boardByName('Quadro Ada');

    $this->drupalGet('boards/' . $ada_board->uuid() . '/tasks/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldNotExists('workspace');
    $this->assertSession()->fieldNotExists('status');
    $this->assertSession()->fieldNotExists('board');
    $this->assertSession()->fieldNotExists('active_execution');
    $this->submitForm([], 'Criar tarefa');
    $this->assertSession()->pageTextNotContains('Tarefa criada.');
    $description = "**negrito**\n\n<script>alert(1)</script>\n\n[clique](javascript:alert(1))";
    $this->submitForm([
      'title' => 'Escrever o tema',
      'description' => $description,
      'acceptance_criteria' => 'Cabeçalho pronto',
      'priority' => 'high',
      'human_owner' => $grace->getAccountName(),
      'due_at' => '2026-10-20',
    ], 'Criar tarefa');
    $this->assertSession()->pageTextContains('Tarefa criada.');
    $this->assertSession()->pageTextContains('Escrever o tema');
    $this->assertSession()->pageTextContains('Alta');
    $this->assertSession()->pageTextContains('Backlog');
    $this->assertSession()->pageTextContains('Responsável: ' . $grace->getAccountName());
    $this->assertSession()->pageTextContains('Prazo: 20/10/2026');
    $this->assertSession()->responseContains('<strong>negrito</strong>');
    $this->assertSession()->responseContains('&lt;script&gt;');
    $this->assertSession()->responseNotContains('<script>alert(1)</script>');
    $this->assertSession()->responseNotContains('javascript:');
    $task = $this->taskByTitle('Escrever o tema');
    $this->assertSame($description, $task->get('description')->getString());
    $this->assertSame('backlog', $task->get('status')->getString());
    $this->assertSame('', $task->get('active_execution')->getString());
    $this->assertSame((string) $ada_workspace->id(), $task->get('workspace')->getString());
    $this->assertSame('1', $task->get('version')->getString());
    $this->drupalGet('workspaces');
    $this->assertSession()->pageTextContains('Workspace atual: Casa Ada');

    $this->drupalGet('tasks/' . $task->uuid() . '/edit');
    $this->assertSession()->fieldNotExists('status');
    $this->assertSession()->fieldNotExists('workspace');
    $this->submitForm([
      'title' => 'Tema revisado',
    ], 'Salvar');
    $this->assertSession()->pageTextContains('Tarefa atualizada.');
    $this->assertSession()->pageTextContains('Tema revisado');
    $stored = $this->reload($task);
    $this->assertSame('Tema revisado', (string) $stored->label());
    $this->assertSame('2', $stored->get('version')->getString());
    $this->assertSame('backlog', $stored->get('status')->getString());
    $this->assertSame($description, $stored->get('description')->getString());

    $this->drupalGet('tasks/' . $task->uuid() . '/edit');
    $stored->set('title', 'Pelo serviço');
    $stored->set('version', 3);
    $stored->save();
    $this->submitForm([
      'title' => 'Pelo formulário',
    ], 'Salvar');
    $this->assertSession()->pageTextContains('A tarefa foi alterada. Recarregue e tente de novo.');
    $this->assertSession()->pageTextNotContains('Tarefa atualizada.');
    $this->assertSame('Pelo serviço', (string) $this->reload($task)->label());

    $this->drupalLogin($grace);
    $this->drupalGet('workspaces/add');
    $this->submitForm([
      'name' => 'Casa Grace',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $this->drupalGet('boards/add');
    $this->submitForm([
      'name' => 'Quadro Grace',
      'description' => 'Texto Grace',
    ], 'Criar board');
    $grace_board = $this->boardByName('Quadro Grace');
    $this->drupalGet('boards/' . $grace_board->uuid() . '/tasks/add');
    $this->submitForm([
      'title' => 'Tarefa Grace',
      'description' => 'Texto secreto',
    ], 'Criar tarefa');
    $grace_task = $this->taskByTitle('Tarefa Grace');

    $this->drupalGet('boards/' . $ada_board->uuid() . '/tasks');
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Tema revisado');
    $this->assertSession()->pageTextNotContains('Pelo serviço');
    $this->drupalGet('tasks/' . $task->uuid());
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Pelo serviço');
    $this->assertSession()->pageTextNotContains('negrito');
    $this->drupalGet('workspaces');
    $this->assertSession()->pageTextContains('Workspace atual: Casa Grace');

    $this->container->get('current_user')->setAccount($ada);
    $manager = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $manager);
    $manager->addMember($ada_workspace, $viewer->getAccountName(), 'viewer');

    $this->drupalLogin($viewer);
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $ada_workspace->uuid(),
    ], 'Usar este workspace');
    $this->drupalGet('boards/' . $ada_board->uuid() . '/tasks');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Pelo serviço');
    $this->assertSession()->pageTextNotContains('Tarefa Grace');
    $this->assertSession()->linkNotExists('Criar tarefa');
    $this->drupalGet('boards/' . $ada_board->uuid() . '/tasks', [
      'query' => ['workspace' => $grace_board->uuid()],
    ]);
    $this->assertSession()->pageTextContains('Pelo serviço');
    $this->assertSession()->pageTextNotContains('Tarefa Grace');
    $this->drupalGet('boards/' . $ada_board->uuid() . '/tasks/add');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('tasks/' . $task->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('<strong>negrito</strong>');
    $this->assertSession()->responseContains('&lt;script&gt;');
    $this->assertSession()->responseNotContains('<script>alert(1)</script>');
    $this->assertSession()->linkNotExists('Editar');
    $this->drupalGet('tasks/' . $task->uuid() . '/edit');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('tasks/' . $grace_task->uuid());
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Texto secreto');
    $this->drupalGet('workspaces');
    $this->assertSession()->pageTextContains('Workspace atual: Casa Ada');
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
