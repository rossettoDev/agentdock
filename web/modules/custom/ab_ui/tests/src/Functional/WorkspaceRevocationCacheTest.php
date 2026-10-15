<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the next request after revocation does not reuse old access.
 *
 * The browser session stays with the member. Revocation runs in this process
 * so the following HTTP request is the same session, not a new login.
 *
 * @group ab_ui
 */
class WorkspaceRevocationCacheTest extends BrowserTestBase {

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
    'dynamic_page_cache',
  ];

  /**
   * A revoked member loses the workspace on the following request.
   */
  public function testNextRequestDropsRevokedWorkspace(): void {
    $ada = $this->drupalCreateUser();
    $grace = $this->drupalCreateUser();
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->container->get('current_user')->setAccount($ada);
    $membership = $this->manager()->addMember($workspace, $grace->getAccountName(), 'member');

    $this->drupalLogin($grace);
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $workspace->uuid(),
    ], 'Usar este workspace');
    $this->assertSession()->pageTextContains('Workspace atual: Casa Ada');

    $view = 'workspaces/' . $workspace->uuid();
    $this->drupalGet($view);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Casa Ada');
    $this->drupalGet($view);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Casa Ada');

    $this->container->get('current_user')->setAccount($ada);
    $this->manager()->remove($workspace, $membership->uuid());

    $this->drupalGet($view);
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Casa Ada');

    $this->drupalGet('workspaces');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Nenhum workspace selecionado.');
    $this->assertSession()->pageTextContains('Você ainda não tem um workspace.');
    $this->assertSession()->pageTextNotContains('Casa Ada');
    $this->assertSession()->fieldNotExists('workspace');

    $this->drupalGet($view);
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Casa Ada');
    $this->assertFalse($this->reload($workspace)->access('view', $grace));
    $this->assertTrue($this->reload($workspace)->access('view', $ada));
  }

  /**
   * Loads the workspace creator.
   */
  private function creator(): WorkspaceCreator {
    $creator = $this->container->get(WorkspaceCreator::class);
    $this->assertInstanceOf(WorkspaceCreator::class, $creator);
    return $creator;
  }

  /**
   * Loads the membership manager.
   */
  private function manager(): MembershipManager {
    $manager = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $manager);
    return $manager;
  }

  /**
   * Reloads a workspace from storage.
   */
  private function reload(Workspace $workspace): Workspace {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_workspace');
    $storage->resetCache([$workspace->id()]);
    $reloaded = $storage->load($workspace->id());
    $this->assertInstanceOf(Workspace::class, $reloaded);
    return $reloaded;
  }

}
