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
 * Verifies the task detail shows a delivery and the human actions.
 *
 * @group ab_ui
 */
class TaskReviewUiTest extends BrowserTestBase {

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
   * The delivery is visible, sanitized, and decided by the human.
   */
  public function testDeliveryAppearsForHumanReview(): void {
    $ada = $this->drupalCreateUser();
    $viewer = $this->drupalCreateUser();
    $grace = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($ada);

    $workspace = $this->container->get(WorkspaceCreator::class)->create($ada, 'Casa Revisao', 'America/Sao_Paulo');
    $this->assertInstanceOf(Workspace::class, $workspace);
    $board = $this->container->get(BoardManager::class)->create($workspace, 'Quadro Revisao', '');
    $this->assertInstanceOf(Board::class, $board);
    $agent = $this->container->get(AgentManager::class)->create($workspace, 'Revisor da revisao', '', '', TRUE, 2);
    $this->assertInstanceOf(Agent::class, $agent);
    $this->container->get(AgentGrantManager::class)->grant($agent, $board, [WorkspacePermission::WRITE]);
    $issued = $this->container->get(AgentTokenManager::class)->issue($agent, [
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::EXECUTIONS_WRITE,
    ]);
    $holder = new ActorContext(
      ActorContext::TYPE_AGENT,
      (string) $agent->uuid(),
      (string) $workspace->uuid(),
      [],
      $issued->publicId,
    );
    $tasks = $this->container->get(TaskManager::class);
    $this->assertInstanceOf(TaskManager::class, $tasks);
    $claims = $this->container->get(TaskClaim::class);
    $this->assertInstanceOf(TaskClaim::class, $claims);
    $leases = $this->container->get(ExecutionMaintenance::class);
    $this->assertInstanceOf(ExecutionMaintenance::class, $leases);

    $review = $this->readyTask($tasks, $board, 'Tema da revisao');
    $claimed = $claims->claim($holder, (string) $review->uuid());
    $summary = "**pronto**\n\n<script>alert(1)</script>\n\n[clique](javascript:alert(1))";
    $leases->progress($holder, $claimed->executionUuid, $claimed->leaseToken, $claimed->generation, 'Criterio conferido');
    $leases->submit($holder, $claimed->executionUuid, $claimed->leaseToken, $claimed->generation, $summary, [
      ['url' => 'https://example.com/resultado', 'label' => 'Resultado'],
    ]);

    $running = $this->readyTask($tasks, $board, 'Tema em curso');
    $live = $claims->claim($holder, (string) $running->uuid());
    $leases->heartbeat($holder, $live->executionUuid, $live->leaseToken, $live->generation);

    $rejected = $this->readyTask($tasks, $board, 'Tema recusado');
    $sent_back = $claims->claim($holder, (string) $rejected->uuid());
    $leases->submit($holder, $sent_back->executionUuid, $sent_back->leaseToken, $sent_back->generation, 'Quase pronto', []);

    $members = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $members);
    $members->addMember($workspace, (string) $viewer->getAccountName(), 'viewer');

    $secret = substr($claimed->leaseToken, (int) strrpos($claimed->leaseToken, '.') + 1);
    $token_secret = substr($issued->token, (int) strrpos($issued->token, '.') + 1);

    $this->drupalLogin($ada);
    $this->drupalGet('agents/' . $agent->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Último sinal:');
    $this->assertSession()->pageTextNotContains('online');
    $this->assertSession()->responseNotContains($secret);
    $this->assertSession()->responseNotContains($token_secret);

    $this->drupalGet('boards/' . $board->uuid() . '/tasks');
    $this->assertSession()->pageTextContains('Tema da revisao');
    $this->assertSession()->pageTextContains('Em revisão');

    $this->drupalLogin($viewer);
    $this->drupalGet('tasks/' . $review->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Tema da revisao');
    $this->assertSession()->pageTextContains('Em revisão');
    $this->assertSession()->pageTextContains('Tentativa 1');
    $this->assertSession()->pageTextContains('Enviada');
    $this->assertSession()->pageTextContains('Criterio conferido');
    $this->assertSession()->pageTextContains('Resultado');
    $this->assertSession()->responseContains('<strong>pronto</strong>');
    $this->assertSession()->responseContains('&lt;script&gt;');
    $this->assertSession()->responseNotContains('<script>alert(1)</script>');
    $this->assertSession()->responseNotContains('javascript:');
    $this->assertSession()->linkByHrefExists('https://example.com/resultado');
    $this->assertSession()->buttonNotExists('Aprovar');
    $this->assertSession()->buttonNotExists('Rejeitar');
    $this->assertSession()->buttonNotExists('Cancelar execução');
    $this->assertSession()->pageTextNotContains('Checklist');
    $this->assertSession()->pageTextNotContains('Anexo');
    $this->assertSession()->responseNotContains($secret);

    $this->drupalLogin($grace);
    $this->drupalGet('tasks/' . $review->uuid());
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Tema da revisao');
    $this->assertSession()->pageTextNotContains('Criterio conferido');

    $this->drupalLogin($ada);
    $this->drupalGet('tasks/' . $review->uuid());
    $this->assertSession()->buttonExists('Aprovar');
    $this->assertSession()->buttonExists('Rejeitar');
    $this->assertSession()->buttonExists('Cancelar execução');
    $this->assertSession()->buttonNotExists('Liberar');
    $this->submitForm([], 'Aprovar');
    $this->assertSession()->pageTextContains('Tarefa aprovada.');
    $this->assertSession()->pageTextContains('Concluída');
    $this->assertSession()->buttonNotExists('Aprovar');
    $stored = $this->reload($review);
    $this->assertSame('done', $stored->get('status')->getString());
    $this->assertSame('', $stored->get('active_execution')->getString());

    $this->drupalGet('tasks/' . $rejected->uuid());
    $this->submitForm(['reason' => ''], 'Rejeitar');
    $this->assertSession()->pageTextContains('Informe o motivo.');
    $this->assertSame('in_review', $this->reload($rejected)->get('status')->getString());
    $this->submitForm([
      'reason' => 'Falta o criterio.',
    ], 'Rejeitar');
    $this->assertSession()->pageTextContains('Tarefa rejeitada.');
    $this->assertSession()->pageTextContains('Pronta');
    $this->assertSession()->pageTextContains('Quase pronto');
    $this->assertSame('ready', $this->reload($rejected)->get('status')->getString());
    $this->assertSame('', $this->reload($rejected)->get('active_execution')->getString());

    $this->drupalGet('tasks/' . $running->uuid());
    $this->assertSession()->pageTextContains('Em andamento');
    $this->assertSession()->buttonExists('Cancelar execução');
    $this->assertSession()->buttonNotExists('Aprovar');
    $this->submitForm([], 'Cancelar execução');
    $this->assertSession()->pageTextContains('Execução cancelada.');
    $this->assertSession()->pageTextContains('Cancelada');
    $this->assertSame('cancelled', $this->reload($running)->get('status')->getString());
    $this->assertSame('', $this->reload($running)->get('active_execution')->getString());

    $this->drupalGet('agents/' . $agent->uuid());
    $this->assertSession()->pageTextContains('Não há sinal de execução.');
    $this->assertSession()->pageTextNotContains('online');
  }

  /**
   * Creates a task and moves it to ready.
   */
  private function readyTask(TaskManager $tasks, Board $board, string $title): Task {
    $task = $tasks->create($board, ['title' => $title]);
    return $tasks->transition($task, 'ready', 1);
  }

  /**
   * Reloads a task after a form write.
   */
  private function reload(Task $task): Task {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_task');
    $storage->resetCache([(int) $task->id()]);
    $reloaded = $storage->load($task->id());
    $this->assertInstanceOf(Task::class, $reloaded);
    return $reloaded;
  }

}
