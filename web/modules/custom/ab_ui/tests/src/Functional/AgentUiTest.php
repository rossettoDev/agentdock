<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the agent screen stays inside the workspace and does not claim a signal.
 *
 * @group ab_ui
 */
class AgentUiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

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
   * Owners manage agents; viewers read them; outsiders get 404.
   */
  public function testAgentScreenDoesNotClaimASignal(): void {
    $this->drupalGet('agents');
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

    $this->drupalGet('agents');
    $this->assertSession()->pageTextContains('Nenhum agente neste workspace.');
    $this->assertSession()->linkExists('Criar agente');
    $this->drupalGet('agents/add');
    $this->submitForm([
      'name' => '',
      'concurrency_limit' => '1',
    ], 'Criar agente');
    $this->assertSession()->pageTextNotContains('Agente criado.');
    $this->submitForm([
      'name' => 'Cursor local',
      'concurrency_limit' => '0',
    ], 'Criar agente');
    $this->assertSession()->pageTextContains('Limite inválido.');
    $this->assertSession()->pageTextNotContains('Agente criado.');
    $this->submitForm([
      'name' => 'Cursor local',
      'description' => 'Revisa textos',
      'capabilities' => 'Revisão',
      'concurrency_limit' => '2',
      'enabled' => TRUE,
    ], 'Criar agente');
    $this->assertSession()->pageTextContains('Agente criado.');
    $this->assertSession()->pageTextContains('Habilitado');
    $this->assertSession()->pageTextContains('Não há sinal de execução.');
    $this->assertSession()->pageTextNotContains('Online');
    $this->assertSession()->pageTextNotContains('Trabalhando');
    $agent = $this->agentByName('Cursor local');

    $this->submitForm([
      'name' => 'Cursor local',
      'description' => 'Revisa textos',
      'capabilities' => 'Revisão',
      'concurrency_limit' => '2',
      'enabled' => FALSE,
    ], 'Salvar');
    $this->assertSession()->pageTextContains('Agente atualizado.');
    $this->assertSession()->pageTextContains('Desabilitado');
    $this->assertSession()->pageTextContains('Não há sinal de execução.');

    $this->submitForm([
      'board' => $ada_board->uuid(),
      'permissions[read]' => TRUE,
      'permissions[write]' => TRUE,
    ], 'Adicionar grant');
    $this->assertSession()->pageTextContains('Grant adicionado.');
    $this->assertSession()->pageTextContains('Quadro Ada: Ler, Escrever');

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
    $this->drupalGet('agents/' . $agent->uuid());
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Cursor local');
    $this->drupalGet('agents', [
      'query' => ['workspace' => $ada_workspace->uuid()],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Workspace atual: Casa Grace');
    $this->assertSession()->pageTextNotContains('Cursor local');
    $this->drupalGet('workspaces');
    $this->assertSession()->pageTextContains('Workspace atual: Casa Grace');

    $this->drupalLogin($ada);
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $ada_workspace->uuid(),
    ], 'Usar este workspace');
    $this->drupalGet('agents/' . $agent->uuid());
    $this->assertSession()->pageTextNotContains('Quadro Grace');
    $this->assertSession()->pageTextContains('Quadro Ada: Ler, Escrever');
    $this->drupalGet('workspaces');
    $this->assertSession()->pageTextContains('Workspace atual: Casa Ada');

    $this->container->get('current_user')->setAccount($ada);
    $manager = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $manager);
    $manager->addMember($ada_workspace, $viewer->getAccountName(), 'viewer');

    $this->drupalLogin($viewer);
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $ada_workspace->uuid(),
    ], 'Usar este workspace');
    $this->drupalGet('agents');
    $this->assertSession()->pageTextContains('Cursor local');
    $this->assertSession()->pageTextContains('Desabilitado');
    $this->assertSession()->linkNotExists('Criar agente');
    $this->drupalGet('agents/' . $agent->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Não há sinal de execução.');
    $this->assertSession()->pageTextNotContains('Online');
    $this->assertSession()->pageTextNotContains('Trabalhando');
    $this->assertSession()->buttonNotExists('Adicionar grant');
    $this->assertSession()->buttonNotExists('Salvar');
    $this->drupalGet('agents/add');
    $this->assertSession()->statusCodeEquals(403);
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
   * Loads the agent stored with the given name.
   */
  private function agentByName(string $name): Agent {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_agent');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $name)
      ->execute();
    $this->assertCount(1, $ids);
    $agent = $storage->load((string) reset($ids));
    $this->assertInstanceOf(Agent::class, $agent);
    return $agent;
  }

}
