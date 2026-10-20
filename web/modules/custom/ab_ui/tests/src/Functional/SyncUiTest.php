<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\Entity\Board;
use Drupal\Tests\BrowserTestBase;

/**
 * Proves the workspace screens share one poller and keep the compose form.
 *
 * @group ab_ui
 */
class SyncUiTest extends BrowserTestBase {

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
   * The board, the conversation and the notifications expose one sync root.
   */
  public function testScreensExposeOneSyncRoot(): void {
    $owner = $this->drupalCreateUser();
    $this->drupalLogin($owner);
    $this->drupalGet('workspaces/add');
    $this->submitForm([
      'name' => 'Casa sync',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $this->drupalGet('boards/add');
    $this->submitForm([
      'name' => 'Quadro sync',
      'description' => 'Texto',
    ], 'Criar board');
    $board = $this->boardByName('Quadro sync');

    $this->drupalGet('boards/' . $board->uuid() . '/tasks');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '[data-ab-sync="tasks"][data-ab-sync-root]');
    $this->assertSession()->elementExists('css', '.ab-sync-status');
    $this->assertSession()->elementExists('css', 'meta[name="ab-sync-cursor"]');
    $page = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString('/events', $page);
    $this->assertStringContainsString('/snapshot', $page);
    $this->assertSame(1, substr_count($page, 'data-ab-sync-root'));

    $this->drupalGet('boards/' . $board->uuid() . '/conversation');
    $this->assertSession()->statusCodeEquals(200);
    $messages = $this->assertSession()->elementExists('css', '[data-ab-sync="messages"]');
    $this->assertNull($messages->find('css', '[name="body"]'));
    $this->assertSession()->fieldExists('body');

    $this->drupalGet('notifications');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '[data-ab-sync="notifications"][data-ab-sync-root]');
  }

  /**
   * Loads the board stored with the given name.
   */
  private function boardByName(string $name): Board {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_board');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('name', $name)->execute();
    $board = $storage->load(reset($ids));
    $this->assertInstanceOf(Board::class, $board);
    return $board;
  }

}
