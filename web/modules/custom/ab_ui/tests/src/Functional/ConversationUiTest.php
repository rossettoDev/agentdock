<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskManager;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the conversation screen and the absence of direct messages.
 *
 * @group ab_ui
 */
class ConversationUiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'agent_board';

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
   * A human comment is shown sanitized, and a viewer cannot send one.
   */
  public function testConversationStaysOnTheTask(): void {
    $this->drupalGet('conversations');
    $this->assertSession()->statusCodeEquals(403);

    $ada = $this->drupalCreateUser();
    $viewer = $this->drupalCreateUser();
    $grace = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($ada);
    $workspace = $this->container->get(WorkspaceCreator::class)->create($ada, 'Casa Fala', 'America/Sao_Paulo');
    $this->assertInstanceOf(Workspace::class, $workspace);
    $board = $this->container->get(BoardManager::class)->create($workspace, 'Quadro Fala', '');
    $this->assertInstanceOf(Board::class, $board);
    $tasks = $this->container->get(TaskManager::class);
    $this->assertInstanceOf(TaskManager::class, $tasks);
    $task = $tasks->create($board, ['title' => 'Tema da conversa']);
    $this->assertInstanceOf(Task::class, $task);
    $members = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $members);
    $members->addMember($workspace, (string) $viewer->getAccountName(), 'viewer');

    $this->drupalLogin($ada);
    $this->drupalGet('tasks/' . $task->uuid());
    $this->assertSession()->linkExists('Conversa');
    $this->drupalGet('tasks/' . $task->uuid() . '/conversation');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Nenhuma mensagem nesta conversa.');
    $this->assertSession()->pageTextNotContains('mensagem direta');
    $this->assertSession()->buttonExists('Enviar');
    $this->submitForm([
      'body' => '',
    ], 'Enviar');
    $this->assertSession()->pageTextContains('Informe uma mensagem.');
    $body = "**oi**\n\n<script>alert(1)</script>\n\n[clique](javascript:alert(1))";
    $this->submitForm([
      'body' => $body,
    ], 'Enviar');
    $this->assertSession()->pageTextContains('Mensagem enviada.');
    $this->assertSession()->pageTextContains('Tema da conversa');
    $this->assertSession()->pageTextContains('Comentário');
    $this->assertSession()->responseContains('<strong>oi</strong>');
    $this->assertSession()->responseContains('&lt;script&gt;');
    $this->assertSession()->responseNotContains('<script>alert(1)</script>');
    $this->assertSession()->responseNotContains('javascript:');
    $this->assertSession()->linkExists('Tarefa');
    $message = $this->container->get('database')->select('ab_message', 'm')
      ->fields('m', ['uuid'])
      ->execute()
      ->fetchField();
    $this->assertIsString($message);
    $this->submitForm([
      'body' => 'Segunda nota',
      'reply_to' => $message,
      'mentions[]' => ['human:' . $viewer->id()],
    ], 'Enviar');
    $this->assertSession()->pageTextContains('Segunda nota');
    $this->assertSession()->pageTextContains('Em resposta a uma mensagem.');
    $this->assertSession()->pageTextContains('Humano');
    $this->assertSession()->pageTextContains($ada->getAccountName());
    $this->assertSession()->pageTextContains('Menção:');
    $this->assertSession()->pageTextContains($viewer->getAccountName());
    $this->assertSession()->pageTextContains('Backlog');
    $this->assertSession()->pageTextContains('Normal');
    $region = $this->assertSession()->elementExists('css', '[data-ab-sync="messages"]');
    $this->assertNull($region->find('css', '[name="body"]'));
    $this->assertSession()->elementExists('css', '.ab-conversation__context');
    $this->drupalGet('boards/' . $board->uuid() . '/conversation');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Quadro');
    $this->assertSession()->pageTextContains('Quadro Fala');
    $this->assertSession()->linkExists('Tarefas');
    $this->assertSession()->pageTextNotContains('Tema da conversa');
    $this->assertSession()->pageTextNotContains('Não lidas');
    $this->drupalGet('workspaces');
    $this->submitForm([
      'workspace' => $workspace->uuid(),
    ], 'Usar este workspace');
    $this->drupalGet('conversations');
    $this->assertSession()->pageTextContains('Tema da conversa');
    $this->assertSession()->pageTextContains('Tarefa');
    $this->assertSession()->elementExists('css', '.ab-conversations time');
    $this->assertSession()->pageTextNotContains('mensagem direta');
    $this->assertSession()->pageTextNotContains('Não lidas');
    $this->assertSession()->linkExists('Tarefa');

    $this->drupalLogin($viewer);
    $this->drupalGet('tasks/' . $task->uuid() . '/conversation');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Segunda nota');
    $this->assertSession()->responseContains('&lt;script&gt;');
    $this->assertSession()->buttonNotExists('Enviar');
    $this->assertSession()->pageTextNotContains('mensagem direta');

    $this->drupalLogin($grace);
    $this->drupalGet('tasks/' . $task->uuid() . '/conversation');
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Segunda nota');
    $this->assertSession()->pageTextNotContains('Tema da conversa');
    $this->assertSession()->pageTextNotContains($viewer->getAccountName());
  }

}
