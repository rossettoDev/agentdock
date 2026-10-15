<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_core\Entity\Workspace;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the workspace list, the create form and context switching.
 *
 * @group ab_ui
 */
class WorkspaceUiTest extends BrowserTestBase {

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
   * A URL workspace id does not grant access or change the selection.
   */
  public function testSelectedWorkspaceIgnoresTheUrl(): void {
    $this->drupalGet('workspaces');
    $this->assertSession()->statusCodeEquals(403);

    $grace = $this->drupalCreateUser();
    $ada = $this->drupalCreateUser();

    $this->drupalLogin($grace);
    $this->drupalGet('workspaces/add');
    $this->assertSession()->fieldNotExists('owner');
    $this->submitForm([
      'name' => 'Casa Grace',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $this->assertSession()->pageTextContains('Workspace atual: Casa Grace');
    $grace_workspace = $this->workspaceByName('Casa Grace');

    $this->drupalLogin($ada);
    $this->drupalGet('workspaces');
    $this->assertSession()->pageTextContains('Nenhum workspace selecionado.');
    $this->assertSession()->pageTextContains('Você ainda não tem um workspace.');
    $this->assertSession()->pageTextNotContains('Casa Grace');

    $this->drupalGet('workspaces', [
      'query' => ['workspace' => $grace_workspace->uuid()],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Nenhum workspace selecionado.');
    $this->assertSession()->pageTextNotContains('Casa Grace');
    $this->assertSession()->fieldNotExists('workspace');

    $this->drupalGet('workspaces/' . $grace_workspace->uuid());
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Casa Grace');

    $this->drupalGet('workspaces/add');
    $this->submitForm([], 'Criar workspace');
    $this->assertSession()->pageTextNotContains('Workspace criado.');

    $this->submitForm([
      'name' => 'Casa Ada',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $this->assertSession()->pageTextContains('Workspace atual: Casa Ada');
    $this->assertSession()->pageTextNotContains('Casa Grace');
    $ada_workspace = $this->workspaceByName('Casa Ada');

    $this->drupalGet('workspaces/add');
    $this->submitForm([
      'name' => 'Casa Dois',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $this->assertSession()->pageTextContains('Workspace atual: Casa Dois');
    $second = $this->workspaceByName('Casa Dois');

    $this->submitForm([
      'workspace' => $ada_workspace->uuid(),
    ], 'Usar este workspace');
    $this->assertSession()->pageTextContains('Workspace atual: Casa Ada');
    $this->assertSession()->pageTextNotContains('Casa Grace');

    $this->drupalGet('workspaces', [
      'query' => ['workspace' => $grace_workspace->uuid()],
    ]);
    $this->assertSession()->pageTextContains('Workspace atual: Casa Ada');
    $this->assertSession()->optionNotExists('workspace', $grace_workspace->uuid());

    $this->drupalGet('workspaces', [
      'query' => ['workspace' => $second->uuid()],
    ]);
    $this->assertSession()->pageTextContains('Workspace atual: Casa Ada');

    $this->drupalGet('workspaces/' . $second->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Casa Dois');
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

}
