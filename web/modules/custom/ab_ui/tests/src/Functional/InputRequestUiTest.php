<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_agent\TaskClaim;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskManager;
use Drupal\ab_communication\InputRequestService;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the task screen answers a question without a status control.
 *
 * @group ab_ui
 */
class InputRequestUiTest extends BrowserTestBase {

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
   * The owner answers, and a viewer does not get the button.
   */
  public function testHumanAnswersWithoutTouchingTheExecution(): void {
    $ada = $this->drupalCreateUser();
    $viewer = $this->drupalCreateUser();
    $grace = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($ada);
    $workspace = $this->container->get(WorkspaceCreator::class)->create($ada, 'Casa Duvida', 'America/Sao_Paulo');
    $this->assertInstanceOf(Workspace::class, $workspace);
    $board = $this->container->get(BoardManager::class)->create($workspace, 'Quadro Duvida', '');
    $this->assertInstanceOf(Board::class, $board);
    $agent = $this->container->get(AgentManager::class)->create($workspace, 'Revisor', '', '', TRUE, 1);
    $this->assertInstanceOf(Agent::class, $agent);
    $this->container->get(AgentGrantManager::class)->grant($agent, $board, [WorkspacePermission::WRITE]);
    $issued = $this->container->get(AgentTokenManager::class)->issue($agent, [
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::EXECUTIONS_WRITE,
      AgentTokenScope::MESSAGES_WRITE,
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
    $task = $tasks->create($board, ['title' => 'Tema da duvida']);
    $this->assertInstanceOf(Task::class, $task);
    $task = $tasks->transition($task, 'ready', 1);
    $claimed = $this->container->get(TaskClaim::class)->claim($holder, (string) $task->uuid());
    $body = "**prazo**\n\n<script>alert(1)</script>";
    $this->container->get(InputRequestService::class)->request(
      $holder,
      $claimed->executionUuid,
      $claimed->leaseToken,
      1,
      $body,
    );
    $members = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $members);
    $members->addMember($workspace, (string) $viewer->getAccountName(), 'viewer');

    $this->drupalLogin($viewer);
    $this->drupalGet('tasks/' . $task->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Pendente');
    $this->assertSession()->pageTextContains('Aguardando');
    $this->assertSession()->responseContains('<strong>prazo</strong>');
    $this->assertSession()->responseContains('&lt;script&gt;');
    $this->assertSession()->responseNotContains('<script>alert(1)</script>');
    $this->assertSession()->buttonNotExists('Responder');
    $this->assertSession()->pageTextNotContains('mensagem direta');

    $this->drupalLogin($ada);
    $this->drupalGet('tasks/' . $task->uuid());
    $this->assertSession()->buttonExists('Responder');
    $this->assertSession()->fieldExists('Resposta');
    $this->submitForm([], 'Responder');
    $this->assertSession()->pageTextContains('Informe uma resposta.');
    $this->submitForm([
      'body' => 'Pode seguir',
    ], 'Responder');
    $this->assertSession()->pageTextContains('Resposta enviada.');
    $this->assertSession()->pageTextContains('Respondida');
    $this->assertSession()->pageTextContains('Resposta de ' . $ada->getAccountName());
    $this->assertSession()->pageTextContains('Pode seguir');
    $this->assertSession()->pageTextNotContains('Aguardando');
    $this->assertSession()->buttonNotExists('Responder');
    $this->assertSession()->pageTextNotContains('mensagem direta');
    $status = $this->container->get('database')->select('ab_execution', 'e')
      ->fields('e', ['status'])
      ->condition('uuid', $claimed->executionUuid)
      ->execute()
      ->fetchField();
    $this->assertSame('running', (string) $status);

    $this->drupalLogin($grace);
    $this->drupalGet('tasks/' . $task->uuid());
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Pode seguir');
    $this->assertSession()->pageTextNotContains('Tema da duvida');
  }

}
