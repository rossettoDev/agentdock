<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies column order and the Hoje filter on the board.
 *
 * @group ab_ui
 */
class TaskKanbanOrderUiTest extends BrowserTestBase {

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
   * Descer changes order, a stale click does not, and Hoje does not write.
   */
  public function testOrderAndTodayFilter(): void {
    $ada = $this->drupalCreateUser();
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
    $alfa = $this->storeTask($board, 'Alfa', 'backlog', []);
    $beta = $this->storeTask($board, 'Beta', 'backlog', []);

    $this->drupalGet('boards/' . $board->uuid() . '/tasks');
    $this->assertSession()->buttonExists('Descer');
    $this->assertSame(['Alfa', 'Beta'], $this->titles('Backlog'));
    $this->submitForm([], 'Descer');
    $this->assertSession()->pageTextContains('Ordem atualizada.');
    $this->assertSame(['Beta', 'Alfa'], $this->titles('Backlog'));
    $this->assertSame('2', $this->reload($alfa)->get('version')->getString());
    $this->assertSame('1', $this->reload($beta)->get('version')->getString());

    $beta = $this->reload($beta);
    $beta->set('version', 4);
    $beta->save();
    $this->submitForm([], 'Descer');
    $this->assertSession()->pageTextContains('A tarefa foi alterada. Recarregue e tente de novo.');
    $this->assertSession()->pageTextNotContains('Ordem atualizada.');
    $this->assertSame(['Beta', 'Alfa'], $this->titles('Backlog'));
    $this->assertSame('4', $this->reload($beta)->get('version')->getString());

    $zone = new \DateTimeZone('America/Sao_Paulo');
    $today = new \DateTimeImmutable('today', $zone);
    $this->storeTask($board, 'Vencida', 'backlog', [
      'due_at' => $today->modify('-1 day')->getTimestamp(),
    ]);
    $this->storeTask($board, 'Amanhã', 'backlog', [
      'due_at' => $today->modify('+1 day')->getTimestamp(),
    ]);
    $running = $this->storeTask($board, 'Em execução', 'in_progress', []);
    $before = $this->reload($running);
    $this->drupalGet('boards/' . $board->uuid() . '/tasks', [
      'query' => ['today' => '1'],
    ]);
    $this->assertSession()->pageTextContains('Vencida');
    $this->assertSession()->pageTextContains('Em execução');
    $this->assertSession()->pageTextNotContains('Amanhã');
    $this->assertSession()->pageTextNotContains('Alfa');
    $this->assertSession()->linkExists('Voltar ao quadro');
    $after = $this->reload($running);
    $this->assertSame($before->get('version')->getString(), $after->get('version')->getString());
    $this->assertSame($before->get('status')->getString(), $after->get('status')->getString());
    $this->assertSame($before->get('position')->getString(), $after->get('position')->getString());
    $this->assertSame((string) $workspace->id(), $after->get('workspace')->getString());
  }

  /**
   * Reads the card titles of one column in display order.
   *
   * @return string[]
   *   Titles from top to bottom.
   */
  private function titles(string $label): array {
    $titles = [];
    foreach ($this->xpath($this->column($label) . '//h4/a') as $link) {
      $titles[] = $link->getText();
    }
    return $titles;
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
   * @param array<string, mixed> $extra
   *   Field values beyond the title and status.
   */
  private function storeTask(Board $board, string $title, string $status, array $extra): Task {
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
