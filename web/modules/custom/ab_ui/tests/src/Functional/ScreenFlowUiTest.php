<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Tests\BrowserTestBase;

/**
 * Walks the five screens inside the workspace kept in the session.
 *
 * @group ab_ui
 */
class ScreenFlowUiTest extends BrowserTestBase {

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
   * Dashboard, board, card, conversation and agents share one session.
   */
  public function testFiveScreensStayOnTheSessionWorkspace(): void {
    $ada = $this->drupalCreateUser();
    $grace = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($grace);
    $foreign = $this->container->get(WorkspaceCreator::class)->create($grace, 'Casa Alheia', 'America/Sao_Paulo');
    $this->assertInstanceOf(Workspace::class, $foreign);
    $hidden = $this->container->get(BoardManager::class)->create($foreign, 'Quadro Alheio', '');
    $this->assertInstanceOf(Board::class, $hidden);

    $this->drupalLogin($ada);
    $this->drupalGet('workspaces/add');
    $this->submitForm([
      'name' => 'Casa Fluxo',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $this->drupalGet('boards/add');
    $this->submitForm([
      'name' => 'Quadro Fluxo',
      'description' => 'Texto',
    ], 'Criar board');
    $board = $this->boardByName('Quadro Fluxo');
    $this->drupalGet('boards/' . $board->uuid() . '/tasks/add');
    $this->submitForm([
      'title' => 'Tema do fluxo',
    ], 'Criar tarefa');
    $task = $this->taskByTitle('Tema do fluxo');

    $this->drupalGet('dashboard');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Casa Fluxo');
    $this->assertSession()->pageTextContains('Quadro Fluxo');
    $this->assertSession()->pageTextNotContains('Casa Alheia');
    $this->assertSession()->pageTextNotContains('Quadro Alheio');
    $this->assertNoWorkspaceQuery();
    $this->assertSession()->elementExists('css', '[data-ab-layout="fluid"][aria-pressed="true"]');
    $this->assertSession()->elementExists('css', 'a[href="#main-content"]');

    $board_url = 'boards/' . $board->uuid() . '/tasks';
    $this->drupalGet($board_url);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Tema do fluxo');
    $move = $this->assertSession()->elementExists('css', 'details.ab-card__move summary');
    $this->assertSame('Mover', $move->getText());
    $this->assertSession()->elementExists('css', 'article[draggable="true"]');
    $this->assertNoWorkspaceQuery();

    $this->drupalGet('tasks/' . $task->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Tema do fluxo');
    $this->assertSession()->linkExists('Conversa');
    $this->assertNoWorkspaceQuery();

    $this->drupalGet('tasks/' . $task->uuid() . '/conversation');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Nenhuma mensagem nesta conversa.');
    $this->assertSession()->pageTextNotContains('mensagem direta');
    $this->assertSession()->buttonExists('Enviar');
    $this->assertNoWorkspaceQuery();

    $this->drupalGet('agents');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Nenhum agente neste workspace.');
    $this->assertSession()->pageTextNotContains('Casa Alheia');
    $this->assertNoWorkspaceQuery();

    $this->drupalGet($board_url);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Quadro Fluxo');
    $this->assertSession()->pageTextContains('Tema do fluxo');
    $this->assertSession()->pageTextNotContains('Quadro Alheio');

    $this->drupalGet('boards/' . $hidden->uuid() . '/tasks');
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Quadro Alheio');
    $this->assertSession()->pageTextNotContains('Tema do fluxo');
  }

  /**
   * Asserts that links and forms do not select a workspace by query.
   */
  private function assertNoWorkspaceQuery(): void {
    $page = $this->getSession()->getPage();
    foreach ($page->findAll('css', 'a[href], form[action]') as $element) {
      $href = $element->getAttribute('href');
      $action = $element->getAttribute('action');
      $value = (string) ($href ?? $action);
      $this->assertStringNotContainsString('workspace=', $value);
    }
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
   * Loads the task stored with the given title.
   */
  private function taskByTitle(string $title): Task {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_task');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('title', $title)->execute();
    $task = $storage->load((string) reset($ids));
    $this->assertInstanceOf(Task::class, $task);
    return $task;
  }

}
