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
 * Verifies the notification list hides the message text.
 *
 * @group ab_ui
 */
class NotificationUiTest extends BrowserTestBase {

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
   * A mention notifies the person without copying the private text.
   */
  public function testNotificationHidesTheMessage(): void {
    $this->drupalGet('notifications');
    $this->assertSession()->statusCodeEquals(403);

    $ada = $this->drupalCreateUser();
    $viewer = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($ada);
    $workspace = $this->container->get(WorkspaceCreator::class)->create($ada, 'Casa Aviso', 'America/Sao_Paulo');
    $this->assertInstanceOf(Workspace::class, $workspace);
    $board = $this->container->get(BoardManager::class)->create($workspace, 'Quadro Aviso', '');
    $this->assertInstanceOf(Board::class, $board);
    $tasks = $this->container->get(TaskManager::class);
    $this->assertInstanceOf(TaskManager::class, $tasks);
    $task = $tasks->create($board, ['title' => 'Tema do aviso']);
    $this->assertInstanceOf(Task::class, $task);
    $members = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $members);
    $membership = $members->addMember($workspace, (string) $viewer->getAccountName(), 'viewer');

    $this->drupalLogin($ada);
    $this->drupalGet('tasks/' . $task->uuid() . '/conversation');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('mensagem direta');
    $this->submitForm([
      'body' => 'Texto privado da mencao',
      'mentions[]' => ['human:' . $viewer->id()],
    ], 'Enviar');
    $this->assertSession()->pageTextContains('Mensagem enviada.');
    $this->submitForm([
      'body' => '@agente solto',
    ], 'Enviar');
    $this->assertSession()->pageTextContains('Mensagem enviada.');

    $this->drupalLogin($viewer);
    $this->drupalGet('notifications');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Menção');
    $this->assertSession()->pageTextContains('Não lida');
    $this->assertSession()->pageTextNotContains('Texto privado da mencao');
    $this->assertSession()->pageTextNotContains('@agente solto');
    $this->assertSession()->buttonExists('Marcar como lida');
    $this->submitForm([], 'Marcar como lida');
    $this->assertSession()->pageTextContains('Notificação lida.');
    $this->assertSession()->pageTextContains('Lida');
    $this->assertSession()->buttonNotExists('Marcar como lida');
    $this->assertSession()->pageTextNotContains('Texto privado da mencao');

    $this->container->get('current_user')->setAccount($ada);
    $members->remove($workspace, (string) $membership->uuid());
    $this->drupalGet('notifications');
    $this->assertSession()->pageTextContains('Nenhuma notificação.');
    $this->assertSession()->pageTextNotContains('Texto privado da mencao');
  }

}
