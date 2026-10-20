<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_agent\ExecutionMaintenance;
use Drupal\ab_agent\TaskClaim;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskManager;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the agent center counts only stored work in the workspace.
 *
 * @group ab_ui
 */
class AgentCenterUiTest extends BrowserTestBase {

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
   * Filters, live executions and credentials stay inside the workspace.
   */
  public function testCenterUsesStoredExecutions(): void {
    $ada = $this->drupalCreateUser();
    $admin = $this->drupalCreateUser();
    $grace = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($ada);

    $workspace = $this->container->get(WorkspaceCreator::class)->create($ada, 'Casa Agente', 'America/Sao_Paulo');
    $this->assertInstanceOf(Workspace::class, $workspace);
    $board = $this->container->get(BoardManager::class)->create($workspace, 'Quadro Agente', '');
    $this->assertInstanceOf(Board::class, $board);
    $agents = $this->container->get(AgentManager::class);
    $this->assertInstanceOf(AgentManager::class, $agents);
    $reviewer = $agents->create($workspace, 'Revisor Casa', 'Revisa textos', 'Revisão', TRUE, 2);
    $this->assertInstanceOf(Agent::class, $reviewer);
    $inactive = $agents->create($workspace, 'Inativo Casa', '', '', FALSE, 1);
    $this->assertInstanceOf(Agent::class, $inactive);
    $failed = $agents->create($workspace, 'Falhou Casa', '', '', TRUE, 1);
    $this->assertInstanceOf(Agent::class, $failed);
    $this->container->get(AgentGrantManager::class)->grant($reviewer, $board, [WorkspacePermission::WRITE]);
    $this->container->get(AgentGrantManager::class)->grant($failed, $board, [WorkspacePermission::WRITE]);
    $issued = $this->container->get(AgentTokenManager::class)->issue($reviewer, [
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::EXECUTIONS_WRITE,
    ]);
    $failed_token = $this->container->get(AgentTokenManager::class)->issue($failed, [
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::EXECUTIONS_WRITE,
    ]);
    $holder = new ActorContext(
      ActorContext::TYPE_AGENT,
      (string) $reviewer->uuid(),
      (string) $workspace->uuid(),
      [],
      $issued->publicId,
    );
    $failed_holder = new ActorContext(
      ActorContext::TYPE_AGENT,
      (string) $failed->uuid(),
      (string) $workspace->uuid(),
      [],
      $failed_token->publicId,
    );
    $tasks = $this->container->get(TaskManager::class);
    $this->assertInstanceOf(TaskManager::class, $tasks);
    $first = $this->readyTask($tasks, $board, 'Texto do revisor');
    $second = $this->readyTask($tasks, $board, 'Texto seguinte');
    $broken = $this->readyTask($tasks, $board, 'Texto da falha');
    $claims = $this->container->get(TaskClaim::class);
    $this->assertInstanceOf(TaskClaim::class, $claims);
    $leases = $this->container->get(ExecutionMaintenance::class);
    $this->assertInstanceOf(ExecutionMaintenance::class, $leases);
    $one = $claims->claim($holder, (string) $first->uuid());
    $claims->claim($holder, (string) $second->uuid());
    $leases->heartbeat($holder, $one->executionUuid, $one->leaseToken, $one->generation);
    $stalled = $claims->claim($failed_holder, (string) $broken->uuid());
    $leases->fail($failed_holder, $stalled->executionUuid, $stalled->leaseToken, $stalled->generation, 'build_failed');

    $this->container->get('current_user')->setAccount($grace);
    $foreign = $this->container->get(WorkspaceCreator::class)->create($grace, 'Casa Alheia', 'America/Sao_Paulo');
    $this->assertInstanceOf(Workspace::class, $foreign);
    $foreign_agent = $this->container->get(AgentManager::class)->create($foreign, 'Revisor Alheio', '', '', TRUE, 1);
    $this->assertInstanceOf(Agent::class, $foreign_agent);

    $this->container->get('current_user')->setAccount($ada);
    $members = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $members);
    $members->addMember($workspace, (string) $admin->getAccountName(), 'admin');

    $this->drupalLogin($ada);
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $workspace->uuid(),
    ], 'Usar este workspace');
    $this->drupalGet('agents');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Revisor Casa');
    $this->assertSession()->pageTextContains('Inativo Casa');
    $this->assertSession()->pageTextContains('Falhou Casa');
    $this->assertSession()->pageTextNotContains('Revisor Alheio');
    $this->assertSession()->pageTextContains('Habilitados: 2');
    $this->assertSession()->pageTextContains('Desabilitados: 1');
    $this->assertSession()->pageTextContains('Em execução: 1');
    $this->assertSession()->pageTextContains('Aguardando tarefa: 1');
    $this->assertSession()->pageTextContains('Sem continuação: 1');
    $this->assertSession()->pageTextContains('2 execuções');
    $this->assertSession()->pageTextNotContains('Online');
    $this->assertSession()->pageTextNotContains('online');
    $this->assertSession()->pageTextNotContains('Trabalhando');
    $this->assertSession()->elementNotExists('css', 'progress');
    $running = $this->assertSession()->elementExists('named', ['link', 'Em execução']);
    $href = (string) $running->getAttribute('href');
    $this->assertStringContainsString('work=running', $href);
    $this->assertStringNotContainsString('workspace=', $href);

    $this->drupalGet('agents', ['query' => ['q' => 'Alheio']]);
    $this->assertSession()->pageTextContains('Nenhum agente com este filtro.');
    $this->assertSession()->pageTextNotContains('Revisor Alheio');
    $this->assertSession()->pageTextNotContains('Revisor Casa');

    $this->drupalGet('agents', ['query' => ['work' => 'running']]);
    $this->assertSession()->pageTextContains('Revisor Casa');
    $this->assertSession()->pageTextNotContains('Falhou Casa');
    $this->assertSession()->pageTextNotContains('Inativo Casa');

    $this->drupalGet('agents', ['query' => ['enabled' => '0']]);
    $this->assertSession()->pageTextContains('Inativo Casa');
    $this->assertSession()->pageTextNotContains('Revisor Casa');

    $this->drupalGet('agents', ['query' => ['work' => 'attention']]);
    $this->assertSession()->pageTextContains('Falhou Casa');
    $this->assertSession()->pageTextNotContains('Revisor Casa');

    $this->drupalGet('agents/' . $reviewer->uuid());
    $this->assertSession()->pageTextContains('Texto do revisor');
    $this->assertSession()->pageTextContains('Texto seguinte');
    $this->assertSession()->pageTextContains('Último sinal:');
    $this->assertSession()->pageTextContains('Execução em andamento.');
    $this->assertSession()->pageTextContains('Você pode editar o agente e emitir credenciais.');
    $this->assertSession()->buttonExists('Criar token');
    $this->assertSession()->pageTextNotContains('Revisor Alheio');
    $this->assertSession()->pageTextNotContains($issued->token);

    $this->drupalGet('agents/' . $failed->uuid());
    $this->assertSession()->pageTextContains('Aguardando tarefa');
    $this->assertSession()->pageTextContains('Não há sinal de execução.');
    $this->assertSession()->pageTextContains('Falhou');
    $this->assertSession()->pageTextContains('Texto da falha');

    $this->drupalLogin($admin);
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $workspace->uuid(),
    ], 'Usar este workspace');
    $this->drupalGet('agents/' . $reviewer->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Salvar');
    $this->assertSession()->buttonNotExists('Criar token');
    $this->assertSession()->pageTextContains('A emissão de credenciais não está nesta conta.');
    $this->assertSession()->pageTextNotContains($issued->token);

    $this->drupalLogin($grace);
    $this->drupalGet('agents/' . $reviewer->uuid());
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Texto do revisor');
    $this->assertSession()->pageTextNotContains('Revisor Casa');
  }

  /**
   * Creates a task and moves it to ready.
   *
   * @param \Drupal\ab_board\TaskManager $tasks
   *   Task writer.
   * @param \Drupal\ab_board\Entity\Board $board
   *   Board that receives the task.
   * @param string $title
   *   Task title.
   */
  private function readyTask(TaskManager $tasks, Board $board, string $title): Task {
    $task = $tasks->create($board, ['title' => $title]);
    return $tasks->transition($task, 'ready', 1);
  }

}
