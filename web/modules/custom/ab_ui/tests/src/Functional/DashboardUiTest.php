<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskManager;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the dashboard counts stay inside the selected workspace.
 *
 * @group ab_ui
 */
class DashboardUiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'agent_board';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ab_core',
    'ab_event',
    'ab_board',
    'ab_agent',
    'ab_communication',
    'ab_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install(['agent_board']);
    $this->config('system.theme')
      ->set('default', 'agent_board')
      ->save();
  }

  /**
   * Counts come from stored rows and do not cross workspaces.
   */
  public function testDashboardUsesStoredWorkspaceData(): void {
    $this->drupalGet('dashboard');
    $this->assertSession()->statusCodeEquals(403);

    $ada = $this->drupalCreateUser();
    $viewer = $this->drupalCreateUser();
    $grace = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($grace);
    $foreign = $this->container->get(WorkspaceCreator::class)->create($grace, 'Casa Alheia', 'America/Sao_Paulo');
    $this->assertInstanceOf(Workspace::class, $foreign);
    $hidden = $this->container->get(BoardManager::class)->create($foreign, 'Quadro Alheio', '');
    $this->assertInstanceOf(Board::class, $hidden);

    $this->container->get('current_user')->setAccount($ada);
    $workspace = $this->container->get(WorkspaceCreator::class)->create($ada, 'Casa Painel', 'America/Sao_Paulo');
    $this->assertInstanceOf(Workspace::class, $workspace);
    $board = $this->container->get(BoardManager::class)->create($workspace, 'Quadro Painel', '');
    $this->assertInstanceOf(Board::class, $board);
    $tasks = $this->container->get(TaskManager::class);
    $this->assertInstanceOf(TaskManager::class, $tasks);
    $late = $tasks->create($board, [
      'title' => 'Atrasada do painel',
      'due_at' => $this->container->get('datetime.time')->getRequestTime() - 3 * 86400,
    ]);
    $this->assertInstanceOf(Task::class, $late);
    $today = $tasks->create($board, [
      'title' => 'Hoje do painel',
      'due_at' => $this->container->get('datetime.time')->getRequestTime(),
    ]);
    $this->assertInstanceOf(Task::class, $today);
    $loose = $tasks->create($board, ['title' => 'Solta do painel']);
    $this->assertInstanceOf(Task::class, $loose);
    $review = $tasks->create($board, ['title' => 'Revisão do painel']);
    $this->assertInstanceOf(Task::class, $review);
    $review->set('status', 'in_review');
    $review->save();
    $members = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $members);
    $members->addMember($workspace, (string) $viewer->getAccountName(), 'viewer');

    $this->drupalLogin($ada);
    $this->drupalGet('dashboard');
    $this->assertSession()->pageTextContains('Nenhum workspace selecionado.');
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $workspace->uuid(),
    ], 'Usar este workspace');
    $this->drupalGet('dashboard');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Casa Painel');
    $this->assertSession()->pageTextContains('Quadro Painel');
    $this->assertSession()->pageTextNotContains('Quadro Alheio');
    $this->assertSession()->pageTextNotContains('Casa Alheia');
    $this->assertSession()->pageTextContains('Atrasada do painel');
    $this->assertSession()->pageTextContains('Revisão do painel');
    $this->assertSession()->pageTextContains('Backlog: 3');
    $this->assertSession()->pageTextContains('Em revisão: 1');
    $this->assertSession()->pageTextContains('Hoje: 2');
    $this->assertSession()->pageTextContains('Atrasadas: 1');
    $this->assertSession()->pageTextContains('Tarefa criada');
    $this->assertSession()->pageTextContains('Nenhuma conversa neste workspace.');
    $this->assertSession()->pageTextNotContains('online');
    $this->assertSession()->pageTextNotContains('%');
    $this->assertSession()->linkExists('Novo board');
    $today_link = $this->assertSession()->elementExists('named', ['link', 'Hoje: 2']);
    $this->assertStringContainsString('today=1', (string) $today_link->getAttribute('href'));
    $this->assertStringNotContainsString('workspace=', (string) $today_link->getAttribute('href'));
    $running = $this->assertSession()->elementExists('named', ['link', 'Em execução']);
    $this->assertStringContainsString('work=running', (string) $running->getAttribute('href'));
    $page = $this->getSession()->getPage()->getContent();
    $this->assertSame(1, substr_count($page, 'data-ab-sync-root'));
    $this->assertStringContainsString('data-ab-sync="messages"', $page);
    $this->assertStringContainsString('data-ab-sync="notifications"', $page);

    $this->drupalLogin($viewer);
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $workspace->uuid(),
    ], 'Usar este workspace');
    $this->drupalGet('dashboard');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Quadro Painel');
    $this->assertSession()->pageTextNotContains('Quadro Alheio');
    $this->assertSession()->linkNotExists('Novo board');
    $this->assertSession()->linkNotExists('Criar tarefa');
    $this->assertSession()->linkNotExists('Criar agente');

    $this->drupalLogin($grace);
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $foreign->uuid(),
    ], 'Usar este workspace');
    $this->drupalGet('dashboard');
    $this->assertSession()->pageTextContains('Quadro Alheio');
    $this->assertSession()->pageTextNotContains('Quadro Painel');
    $this->assertSession()->pageTextNotContains('Revisão do painel');
  }

}
