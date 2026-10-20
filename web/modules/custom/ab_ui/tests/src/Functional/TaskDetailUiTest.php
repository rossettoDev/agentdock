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
use Drupal\ab_communication\MessageService;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the task detail keeps only the sections that have data.
 *
 * @group ab_ui
 */
class TaskDetailUiTest extends BrowserTestBase {

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
   * Tabs, delivery and the thread stay inside the task workspace.
   */
  public function testDetailUsesStoredSections(): void {
    $ada = $this->drupalCreateUser();
    $viewer = $this->drupalCreateUser();
    $grace = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($ada);

    $workspace = $this->container->get(WorkspaceCreator::class)->create($ada, 'Casa Detalhe', 'America/Sao_Paulo');
    $this->assertInstanceOf(Workspace::class, $workspace);
    $board = $this->container->get(BoardManager::class)->create($workspace, 'Quadro Detalhe', '');
    $this->assertInstanceOf(Board::class, $board);
    $agent = $this->container->get(AgentManager::class)->create($workspace, 'Revisor Detalhe', '', '', TRUE, 2);
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
    $empty = $tasks->create($board, ['title' => 'Tema vazio']);
    $this->assertInstanceOf(Task::class, $empty);
    $empty->set('acceptance_criteria', 'Primeiro criterio');
    $empty->save();
    $this->container->get(MessageService::class)->post(
      $this->container->get(HumanActorContextFactory::class)->create($workspace),
      'task',
      (string) $empty->uuid(),
      'comment',
      'comentario da casa',
    );

    $failed = $this->readyTask($tasks, $board, 'Tema falho');
    $claims = $this->container->get(TaskClaim::class);
    $this->assertInstanceOf(TaskClaim::class, $claims);
    $leases = $this->container->get(ExecutionMaintenance::class);
    $this->assertInstanceOf(ExecutionMaintenance::class, $leases);
    $broken = $claims->claim($holder, (string) $failed->uuid());
    $leases->fail($holder, $broken->executionUuid, $broken->leaseToken, $broken->generation, 'build_failed');

    $expired = $this->readyTask($tasks, $board, 'Tema expirado');
    $live = $claims->claim($holder, (string) $expired->uuid());
    $this->container->get('database')->update('ab_execution')
      ->fields(['lease_expires_at' => 1])
      ->condition('uuid', $live->executionUuid)
      ->execute();

    $this->container->get('current_user')->setAccount($grace);
    $foreign = $this->container->get(WorkspaceCreator::class)->create($grace, 'Casa Alheia', 'America/Sao_Paulo');
    $this->assertInstanceOf(Workspace::class, $foreign);
    $foreign_board = $this->container->get(BoardManager::class)->create($foreign, 'Quadro Alheio', '');
    $this->assertInstanceOf(Board::class, $foreign_board);
    $secret = $this->container->get(TaskManager::class)->create($foreign_board, ['title' => 'Tema alheio']);
    $this->assertInstanceOf(Task::class, $secret);
    $this->container->get(MessageService::class)->post(
      $this->container->get(HumanActorContextFactory::class)->create($foreign),
      'task',
      (string) $secret->uuid(),
      'comment',
      'texto alheio da casa',
    );

    $this->container->get('current_user')->setAccount($ada);
    $members = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $members);
    $members->addMember($workspace, (string) $viewer->getAccountName(), 'viewer');

    $this->drupalLogin($ada);
    $this->drupalGet('tasks/' . $empty->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Tema vazio');
    $this->assertSession()->pageTextContains((string) $empty->uuid());
    $this->assertSession()->pageTextContains('Quadro Detalhe');
    $this->assertSession()->pageTextContains('Nenhuma descrição.');
    $criteria = $this->assertSession()->elementExists('css', '.ab-task-criteria');
    $this->assertStringContainsString('Primeiro criterio', $criteria->getText());
    $this->assertNull($criteria->find('css', 'input'));
    $this->assertSession()->pageTextContains('Nenhuma execução nesta tarefa.');
    $this->assertSession()->pageTextContains('Nenhum link de entrega.');
    $this->assertSession()->pageTextContains('comentario da casa');
    $this->assertSession()->pageTextNotContains('texto alheio da casa');
    $this->assertSession()->pageTextNotContains('Checklist');
    $this->assertSession()->pageTextNotContains('Arquivos');
    $this->assertSession()->pageTextNotContains('Anexo');
    $this->assertSession()->elementNotExists('css', 'progress');
    $this->assertSession()->elementNotExists('css', '[role="progressbar"]');
    $this->assertSession()->linkNotExists('Arquivos');
    $this->assertSession()->linkNotExists('Checklist');
    $current = $this->assertSession()->elementExists('css', 'a[aria-current="page"]');
    $this->assertSame('Descrição', $current->getText());
    $back = $this->assertSession()->elementExists('named', ['link', 'Tarefas']);
    $href = (string) $back->getAttribute('href');
    $this->assertStringContainsString((string) $board->uuid(), $href);
    $this->assertStringNotContainsString('workspace=', $href);
    $conversas = $this->assertSession()->elementExists('css', 'a[href*="tab=conversas"]');
    $this->assertStringNotContainsString('workspace=', (string) $conversas->getAttribute('href'));

    $this->drupalGet('tasks/' . $empty->uuid(), ['query' => ['tab' => 'conversas']]);
    $current = $this->assertSession()->elementExists('css', 'a[aria-current="page"]');
    $this->assertSame('Conversas', $current->getText());
    $this->assertSession()->pageTextContains('comentario da casa');
    $this->assertSession()->pageTextContains('Comentário');
    $this->assertSession()->buttonExists('Enviar');

    $this->drupalGet('tasks/' . $empty->uuid(), ['query' => ['tab' => 'arquivos']]);
    $current = $this->assertSession()->elementExists('css', 'a[aria-current="page"]');
    $this->assertSame('Descrição', $current->getText());
    $this->assertSession()->linkNotExists('Arquivos');

    $this->drupalGet('tasks/' . $failed->uuid());
    $this->assertSession()->pageTextContains('A execução falhou.');
    $this->assertSession()->pageTextContains('build_failed');
    $this->assertSession()->pageTextContains('Falhou');
    $this->assertSession()->elementNotExists('css', 'progress');

    $this->drupalGet('tasks/' . $expired->uuid());
    $this->assertSession()->pageTextContains('A execução expirou.');
    $this->assertSession()->pageTextContains('Revisor Detalhe');
    $this->assertSession()->pageTextNotContains('online');
    $this->assertSession()->buttonExists('Cancelar execução');
    $this->assertSession()->buttonNotExists('Aprovar');

    $this->drupalLogin($viewer);
    $this->drupalGet('tasks/' . $empty->uuid(), ['query' => ['tab' => 'conversas']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('comentario da casa');
    $this->assertSession()->pageTextNotContains('texto alheio da casa');
    $this->assertSession()->buttonNotExists('Enviar');
    $this->assertSession()->buttonNotExists('Aprovar');
    $this->assertSession()->buttonNotExists('Responder');

    $this->drupalLogin($grace);
    $this->drupalGet('tasks/' . $empty->uuid());
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('comentario da casa');
    $this->assertSession()->pageTextNotContains('Tema vazio');
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
