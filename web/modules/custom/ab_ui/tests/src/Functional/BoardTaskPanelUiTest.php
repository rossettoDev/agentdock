<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the board opens on its tasks, with the selected task beside them.
 *
 * @group ab_ui
 */
class BoardTaskPanelUiTest extends BrowserTestBase {

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
   * The board list, the task page and a comment stay on the same board.
   */
  public function testTheBoardKeepsTheSelectedTask(): void {
    $ada = $this->drupalCreateUser();
    $this->drupalLogin($ada);
    $this->drupalGet('workspaces/add');
    $this->submitForm([
      'name' => 'Casa Painel',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $this->drupalGet('boards/add');
    $this->submitForm([
      'name' => 'Quadro Painel',
      'description' => 'Texto do painel',
    ], 'Criar board');
    $board = $this->boardByName('Quadro Painel');

    $this->drupalGet('boards');
    $this->assertSession()->linkByHrefExists('/boards/' . $board->uuid() . '/tasks');
    $this->drupalGet('boards/' . $board->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('boards/' . $board->uuid() . '/tasks');
    $this->assertSession()->pageTextContains('Texto do painel');
    $this->assertSession()->buttonExists('Arquivar');

    $this->drupalGet('boards/' . $board->uuid() . '/tasks/add');
    $this->submitForm([
      'title' => 'Tema do painel',
      'description' => '**negrito**',
    ], 'Criar tarefa');
    $task = $this->taskByTitle('Tema do painel');
    $this->assertSession()->addressEquals('boards/' . $board->uuid() . '/tasks/' . $task->uuid());
    $this->assertSession()->pageTextContains('Discussão');
    $this->assertSession()->pageTextContains('Nenhuma mensagem nesta conversa.');
    $this->assertSession()->responseContains('<strong>negrito</strong>');

    $this->submitForm([
      'body' => 'Pode atualizar o andamento?',
    ], 'Enviar');
    $this->assertSession()->pageTextContains('Mensagem enviada.');
    $this->assertSession()->pageTextContains('Pode atualizar o andamento?');
    $this->assertSession()->addressEquals('boards/' . $board->uuid() . '/tasks/' . $task->uuid());

    $this->drupalGet('boards/add');
    $this->submitForm([
      'name' => 'Outro quadro',
      'description' => 'Outro texto',
    ], 'Criar board');
    $other = $this->boardByName('Outro quadro');
    $this->drupalGet('boards/' . $other->uuid() . '/tasks/add');
    $this->submitForm([
      'title' => 'Tarefa alheia',
    ], 'Criar tarefa');
    $foreign = $this->taskByTitle('Tarefa alheia');
    $this->drupalGet('boards/' . $board->uuid() . '/tasks/' . $foreign->uuid());
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Tarefa alheia');
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
   * Loads the task stored with the given title.
   */
  private function taskByTitle(string $title): Task {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_task');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('title', $title)
      ->execute();
    $this->assertCount(1, $ids);
    $task = $storage->load((string) reset($ids));
    $this->assertInstanceOf(Task::class, $task);
    return $task;
  }

}
