<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the token screen shows the secret once and ignores the query string.
 *
 * @group ab_ui
 */
class AgentTokenUiTest extends BrowserTestBase {

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
   * The owner sees the secret once. A query string does not authenticate.
   */
  public function testSecretAppearsOnceAndQueryDoesNotAuthenticate(): void {
    $this->drupalGet('agents', [
      'query' => ['token' => 'ab_' . str_repeat('a', 32) . '.' . str_repeat('b', 64)],
    ]);
    $this->assertSession()->statusCodeEquals(403);

    $ada = $this->drupalCreateUser();
    $viewer = $this->drupalCreateUser();
    $this->drupalLogin($ada);
    $this->drupalGet('workspaces/add');
    $this->submitForm([
      'name' => 'Casa Ada',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $workspace = $this->workspaceByName('Casa Ada');
    $this->drupalGet('agents/add');
    $this->submitForm([
      'name' => 'Cursor local',
      'concurrency_limit' => '1',
      'enabled' => TRUE,
    ], 'Criar agente');
    $agent = $this->agentByName('Cursor local');
    $this->submitForm([], 'Criar token');
    $this->assertSession()->pageTextContains('Escolha um escopo.');
    $this->assertSession()->pageTextNotContains('Copie o token agora.');
    $this->submitForm([
      'scopes[tasks:read]' => TRUE,
    ], 'Criar token');
    $this->assertSession()->pageTextContains('Copie o token agora. Ele não será mostrado de novo:');
    $token = $this->presentedToken();
    $secret = substr($token, 36);
    $publicId = substr($token, 0, 35);

    $this->drupalGet('agents/' . $agent->uuid());
    $this->assertSession()->pageTextNotContains($token);
    $this->assertSession()->pageTextNotContains($secret);
    $this->assertSession()->pageTextContains($publicId);
    $this->assertSession()->pageTextNotContains($this->storedHash($agent));
    $this->assertSession()->pageTextContains('Ativo');

    $this->container->get('current_user')->setAccount($ada);
    $manager = $this->container->get(AgentTokenManager::class);
    $this->assertInstanceOf(AgentTokenManager::class, $manager);
    $expired = $manager->issue($agent, [AgentTokenScope::TASKS_READ], $this->container->get('datetime.time')->getRequestTime() - 10);

    $this->drupalResetSession();
    $this->getSession()->restart();
    $this->drupalGet('agents', [], [
      'Authorization' => 'Bearer ' . $token,
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Nenhum workspace selecionado.');
    $this->assertSession()->pageTextNotContains($secret);
    $this->assertSession()->pageTextNotContains('Cursor local');
    $this->getSession()->setRequestHeader('Authorization', '');
    $this->drupalGet('agents', [
      'query' => ['token' => $token],
    ]);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('agents', [], [
      'Authorization' => 'Bearer ' . $expired->token,
    ]);
    $this->assertSession()->statusCodeEquals(403);
    $this->getSession()->setRequestHeader('Authorization', '');

    $this->container->get('current_user')->setAccount($ada);
    $memberships = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $memberships);
    $memberships->addMember($workspace, $viewer->getAccountName(), 'viewer');
    $this->drupalLogin($viewer);
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $workspace->uuid(),
    ], 'Usar este workspace');
    $this->drupalGet('agents/' . $agent->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Cursor local');
    $this->assertSession()->buttonNotExists('Criar token');
    $this->assertSession()->pageTextNotContains($secret);
    $this->assertSession()->pageTextNotContains($publicId);
  }

  /**
   * Reads the bearer token from the status message.
   */
  private function presentedToken(): string {
    $content = $this->getSession()->getPage()->getContent();
    $matched = preg_match('/ab_[0-9a-f]{32}\.[0-9a-f]{64}/', $content, $found);
    $this->assertSame(1, $matched);
    return $found[0];
  }

  /**
   * Reads the stored hash so the page can be checked against it.
   */
  private function storedHash(Agent $agent): string {
    $hash = $this->container->get('database')->select('ab_agent_token', 't')
      ->fields('t', ['secret_hash'])
      ->condition('agent_id', $agent->id())
      ->execute()
      ->fetchField();
    $this->assertIsString($hash);
    return $hash;
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
