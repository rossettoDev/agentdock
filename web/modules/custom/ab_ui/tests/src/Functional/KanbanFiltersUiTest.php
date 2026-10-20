<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_agent\Entity\Execution;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies board filters stay inside the tasks the member can read.
 *
 * @group ab_ui
 */
class KanbanFiltersUiTest extends BrowserTestBase {

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
   * Priority and agent filters do not reveal another workspace.
   */
  public function testFiltersStayInsideTheBoard(): void {
    $ada = $this->drupalCreateUser();
    $grace = $this->drupalCreateUser();

    $this->drupalLogin($grace);
    $this->drupalGet('workspaces/add');
    $this->submitForm([
      'name' => 'Casa Alheia',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $this->drupalGet('agents/add');
    $this->submitForm([
      'name' => 'Revisor Alheio',
      'description' => 'Fora',
      'capabilities' => 'Leitura',
      'concurrency_limit' => '1',
    ], 'Criar agente');
    $foreign = $this->agentByName('Revisor Alheio');

    $this->drupalLogin($ada);
    $this->drupalGet('workspaces/add');
    $this->submitForm([
      'name' => 'Casa Filtro',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $workspace = $this->workspaceByName('Casa Filtro');
    $this->drupalGet('boards/add');
    $this->submitForm([
      'name' => 'Quadro Filtro',
      'description' => 'Texto do quadro',
    ], 'Criar board');
    $board = $this->boardByName('Quadro Filtro');
    $this->drupalGet('agents/add');
    $this->submitForm([
      'name' => 'Revisor Casa',
      'description' => 'Revisa',
      'capabilities' => 'Revisão',
      'concurrency_limit' => '1',
    ], 'Criar agente');
    $agent = $this->agentByName('Revisor Casa');

    $high = $this->storeTask($board, 'Texto alto', 'backlog', [
      'priority' => 'high',
      'assigned_agent' => $agent->id(),
    ]);
    $low = $this->storeTask($board, 'Texto baixo', 'backlog', [
      'priority' => 'low',
      'assigned_agent' => $foreign->id(),
    ]);
    $execution = Execution::create([
      'workspace' => $workspace->id(),
      'task' => $high->id(),
      'agent' => $agent->id(),
      'status' => 'running',
      'attempt' => 2,
    ]);
    $execution->save();
    $high->set('active_execution', $execution->id());
    $high->save();
    $low->set('active_execution', $execution->id());
    $low->save();

    $this->drupalGet('boards/' . $board->uuid() . '/tasks');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Texto do quadro');
    $this->assertSession()->linkNotExists('Lista');
    $this->assertSession()->linkNotExists('Calendário');
    $this->assertSession()->pageTextContains('Revisor Casa');
    $this->assertSession()->pageTextNotContains('Revisor Alheio');
    $this->assertSession()->elementTextContains('xpath', $this->card('Texto alto'), 'Tentativa 2');
    $this->assertSession()->elementTextNotContains('xpath', $this->card('Texto baixo'), 'Tentativa');
    $this->assertSession()->pageTextNotContains('%');
    $this->assertSame('2', $this->countIn('Backlog'));
    $alta = $this->assertSession()->elementExists('xpath', '//a[normalize-space(.)="Alta"]');
    $this->assertStringContainsString('priority=high', (string) $alta->getAttribute('href'));
    $this->assertStringNotContainsString('workspace=', (string) $alta->getAttribute('href'));

    $this->drupalGet('boards/' . $board->uuid() . '/tasks', [
      'query' => ['priority' => 'high'],
    ]);
    $this->assertSession()->pageTextContains('Texto alto');
    $this->assertSession()->pageTextNotContains('Texto baixo');
    $this->assertSame('1', $this->countIn('Backlog'));
    $this->assertSame('0', $this->countIn('Pronta'));

    $this->drupalGet('boards/' . $board->uuid() . '/tasks', [
      'query' => ['priority' => 'urgent'],
    ]);
    $this->assertSession()->pageTextContains('Nenhuma tarefa com este filtro.');
    $this->assertSession()->pageTextNotContains('Texto alto');
    $this->assertSession()->elementExists('css', '.ab-kanban__column');

    $this->drupalGet('boards/' . $board->uuid() . '/tasks', [
      'query' => ['agent' => $agent->uuid()],
    ]);
    $this->assertSession()->pageTextContains('Texto alto');
    $this->assertSession()->pageTextNotContains('Texto baixo');
    $this->assertSession()->pageTextNotContains('Revisor Alheio');

    $this->drupalGet('boards/' . $board->uuid() . '/tasks', [
      'query' => ['agent' => $foreign->uuid()],
    ]);
    $this->assertSession()->pageTextContains('Texto alto');
    $this->assertSession()->pageTextContains('Texto baixo');
    $this->assertSession()->pageTextNotContains('Revisor Alheio');
  }

  /**
   * Reads the count printed in one column heading.
   */
  private function countIn(string $label): string {
    $xpath = $this->column($label) . '//span[contains(@class, "ab-kanban__count")]';
    return $this->assertSession()->elementExists('xpath', $xpath)->getText();
  }

  /**
   * XPath of the column whose heading contains the label.
   */
  private function column(string $label): string {
    $section = 'section[contains(@class, "ab-kanban__column")]';
    $heading = 'h3[contains(normalize-space(.), "' . $label . '")]';
    return '//' . $section . '[.//' . $heading . ']';
  }

  /**
   * XPath of the card whose title is the given text.
   */
  private function card(string $title): string {
    return '//article[contains(@class, "ab-card")][.//h4/a[normalize-space(.)="' . $title . '"]]';
  }

  /**
   * Loads the workspace stored with the given name.
   */
  private function workspaceByName(string $name): Workspace {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_workspace');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('name', $name)->execute();
    $workspace = $storage->load((string) reset($ids));
    $this->assertInstanceOf(Workspace::class, $workspace);
    return $workspace;
  }

  /**
   * Loads the board stored with the given name.
   */
  private function boardByName(string $name): Board {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_board');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('name', $name)->execute();
    $board = $storage->load((string) reset($ids));
    $this->assertInstanceOf(Board::class, $board);
    return $board;
  }

  /**
   * Loads the agent stored with the given name.
   */
  private function agentByName(string $name): Agent {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_agent');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('name', $name)->execute();
    $agent = $storage->load((string) reset($ids));
    $this->assertInstanceOf(Agent::class, $agent);
    return $agent;
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
  private function storeTask(Board $board, string $title, string $status, array $extra = []): Task {
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
