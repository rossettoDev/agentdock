<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies board screens stay inside the selected workspace.
 *
 * @group ab_ui
 */
class WorkspaceBoardsUiTest extends BrowserTestBase {

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
   * Owners and admins manage boards; other roles only read them.
   */
  public function testBoardScreensFollowTheSelectedWorkspace(): void {
    $this->drupalGet('boards');
    $this->assertSession()->statusCodeEquals(403);

    $ada = $this->drupalCreateUser();
    $grace = $this->drupalCreateUser();
    $viewer = $this->drupalCreateUser();
    $admin = $this->drupalCreateUser();

    $this->drupalLogin($ada);
    $this->drupalGet('workspaces/add');
    $this->submitForm([
      'name' => 'Casa Ada',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $ada_workspace = $this->workspaceByName('Casa Ada');
    $this->drupalGet('boards/add');
    $this->assertSession()->fieldNotExists('workspace');
    $this->submitForm([], 'Criar board');
    $this->assertSession()->pageTextNotContains('Board criado.');
    $this->submitForm([
      'name' => 'Quadro Ada',
      'description' => 'Texto Ada',
    ], 'Criar board');
    $this->assertSession()->pageTextContains('Board criado.');
    $this->assertSession()->pageTextContains('Workspace atual: Casa Ada');
    $this->assertSession()->pageTextContains('Quadro Ada');
    $ada_board = $this->boardByName('Quadro Ada');

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
    $this->drupalGet('boards', [
      'query' => ['workspace' => $ada_workspace->uuid()],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Workspace atual: Casa Grace');
    $this->assertSession()->pageTextContains('Quadro Grace');
    $this->assertSession()->pageTextNotContains('Quadro Ada');

    $this->drupalGet('boards/' . $ada_board->uuid());
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Quadro Ada');
    $this->assertSession()->pageTextNotContains('Texto Ada');
    $this->drupalGet('workspaces');
    $this->assertSession()->pageTextContains('Workspace atual: Casa Grace');

    $this->container->get('current_user')->setAccount($ada);
    $manager = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $manager);
    $manager->addMember($ada_workspace, $viewer->getAccountName(), 'viewer');
    $manager->addMember($ada_workspace, $admin->getAccountName(), 'admin');

    $this->drupalLogin($viewer);
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $ada_workspace->uuid(),
    ], 'Usar este workspace');
    $this->drupalGet('boards');
    $this->assertSession()->pageTextContains('Quadro Ada');
    $this->assertSession()->pageTextNotContains('Quadro Grace');
    $this->assertSession()->linkNotExists('Criar board');
    $this->drupalGet('boards/add');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('boards/' . $ada_board->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Quadro Ada');
    $this->assertSession()->pageTextContains('Texto Ada');
    $this->assertSession()->buttonNotExists('Arquivar');
    $this->drupalGet('workspaces');
    $this->assertSession()->pageTextContains('Workspace atual: Casa Ada');

    $this->drupalLogin($admin);
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $ada_workspace->uuid(),
    ], 'Usar este workspace');
    $this->drupalGet('boards/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldNotExists('workspace');
    $this->submitForm([
      'name' => 'Quadro Admin',
      'description' => 'Texto Admin',
    ], 'Criar board');
    $this->assertSession()->pageTextContains('Quadro Admin');
    $this->assertSession()->pageTextNotContains('Quadro Grace');

    $this->drupalLogin($ada);
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $ada_workspace->uuid(),
    ], 'Usar este workspace');
    $this->drupalGet('boards/' . $ada_board->uuid());
    $this->assertSession()->buttonExists('Arquivar');
    $this->submitForm([], 'Arquivar');
    $this->assertSession()->pageTextContains('Board arquivado.');
    $this->assertSession()->pageTextContains('Quadro Ada');
    $this->assertSession()->pageTextContains('Texto Ada');
    $this->assertSession()->pageTextContains('Arquivado');
    $this->assertSession()->buttonNotExists('Arquivar');
    $stored = $this->reloadBoard($ada_board);
    $this->assertSame('Quadro Ada', (string) $stored->label());
    $this->assertSame('Texto Ada', $stored->get('description')->getString());
    $this->assertSame((string) $ada_workspace->id(), $stored->get('workspace')->getString());
    $this->assertSame(1, (int) $stored->get('archived')->getString());
    $this->drupalGet('workspaces');
    $this->assertSession()->pageTextContains('Workspace atual: Casa Ada');
  }

  /**
   * Loads the workspace stored with the given name.
   */
  private function workspaceByName(string $name): Workspace {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_workspace');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $name)
      ->execute();
    $this->assertCount(1, $ids);
    $workspace = $storage->load((string) reset($ids));
    $this->assertInstanceOf(Workspace::class, $workspace);
    return $workspace;
  }

  /**
   * Loads the board stored with the given name.
   */
  private function boardByName(string $name): Board {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_board');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $name)
      ->execute();
    $this->assertCount(1, $ids);
    $board = $storage->load((string) reset($ids));
    $this->assertInstanceOf(Board::class, $board);
    return $board;
  }

  /**
   * Reloads a board from storage.
   */
  private function reloadBoard(Board $board): Board {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_board');
    $storage->resetCache([$board->id()]);
    $reloaded = $storage->load($board->id());
    $this->assertInstanceOf(Board::class, $reloaded);
    return $reloaded;
  }

}
