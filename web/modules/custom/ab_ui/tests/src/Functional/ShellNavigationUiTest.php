<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the shell links, the session selector and the scoped bell.
 *
 * @group ab_ui
 */
class ShellNavigationUiTest extends BrowserTestBase {

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
   * Navigation uses real routes and does not reveal another workspace.
   */
  public function testShellStaysInsideTheSessionWorkspace(): void {
    $ada = $this->drupalCreateUser();
    $grace = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($grace);
    $foreign = $this->container->get(WorkspaceCreator::class)->create($grace, 'Casa Alheia', 'America/Sao_Paulo');
    $this->assertInstanceOf(Workspace::class, $foreign);

    $this->drupalLogin($ada);
    $this->drupalGet('workspaces/add');
    $this->submitForm([
      'name' => 'Casa Shell',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $this->drupalGet('boards/add');
    $this->submitForm([
      'name' => 'Quadro Shell',
      'description' => 'Texto',
    ], 'Criar board');
    $board = $this->boardByName('Quadro Shell');

    $this->drupalGet('boards/' . $board->uuid() . '/tasks');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkNotExists('Dashboard');
    $this->assertSession()->elementNotExists('css', 'input[type="search"]');
    $tasks = $this->assertSession()->elementExists('css', 'a[aria-current="page"]');
    $this->assertSame('Tarefas', $tasks->getText());
    $this->assertStringContainsString($board->uuid(), (string) $tasks->getAttribute('href'));
    $this->assertStringNotContainsString('workspace=', (string) $tasks->getAttribute('href'));
    $form = $this->assertSession()->elementExists('css', 'form.ab-workspace-selector');
    $this->assertStringNotContainsString('workspace=', (string) $form->getAttribute('action'));
    $this->assertSession()->elementExists('css', '[data-ab-nav-toggle][aria-expanded="true"]');
    $this->assertSession()->elementExists('css', '[data-ab-bell][aria-expanded="false"]');
    $this->assertSession()->elementExists('css', '#ab-context[hidden]');
    $this->assertSession()->pageTextContains('Nenhuma menção neste workspace.');
    $this->assertSession()->pageTextNotContains('Casa Alheia');

    $this->drupalGet('workspaces/' . $foreign->uuid());
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextContains('A página não foi encontrada.');
    $this->assertSession()->pageTextNotContains('Casa Alheia');

    $viewer = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($ada);
    $manager = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $manager);
    $workspace = $this->workspaceByName('Casa Shell');
    $manager->addMember($workspace, $viewer->getAccountName(), 'viewer');
    $this->drupalLogin($viewer);
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $workspace->uuid(),
    ], 'Usar este workspace');
    $this->drupalGet('boards/add');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextContains('Você não tem acesso a esta operação.');
    $this->assertSession()->pageTextNotContains('Casa Alheia');

    $this->drupalLogout();
    $this->drupalGet('user/login', ['query' => ['destination' => '/workspaces/segredo-alheio']]);
    $note = $this->assertSession()->elementExists('css', '.ab-session-expired');
    $this->assertSame('Sua sessão não está ativa. Entre para continuar.', $note->getText());
    $this->assertStringNotContainsString('segredo-alheio', $note->getText());
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

}
