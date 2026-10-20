<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the design sample renders statuses and a disabled control.
 *
 * @group ab_ui
 */
class DesignSystemUiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'agent_board';

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install(['agent_board']);
    $this->config('system.theme')
      ->set('default', 'agent_board')
      ->save();
    $this->drupalLogin($this->drupalCreateUser());
  }

  /**
   * The sample exposes shared components without an online claim.
   */
  public function testSampleRendersComponentStates(): void {
    $this->drupalGet('/ui/amostra');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'a.ab-button[href="/boards"]');
    $this->assertSession()->elementExists('css', 'button.ab-button--quiet');
    $disabled = $this->assertSession()->elementExists('css', 'button.ab-button[disabled]');
    $this->assertSame('Indisponível', $disabled->getText());
    $this->assertSession()->elementExists('css', '.ab-badge--blocked');
    $this->assertSession()->elementExists('css', '.ab-badge--enabled');
    $this->assertSession()->elementExists('css', '.ab-badge--disabled');
    $this->assertSession()->elementExists('css', '.ab-tabs__link[aria-current="page"]');
    $this->assertSession()->elementExists('css', '.ab-avatar--human');
    $this->assertSession()->elementExists('css', '.ab-avatar--agent');
    $this->assertSession()->elementExists('css', '.ab-skeleton[role="status"]');
    $this->assertSession()->elementExists('css', '.ab-state--empty');
    $this->assertSession()->elementExists('css', '.ab-state--error[role="alert"]');
    $this->assertSession()->pageTextContains('Não há sinal de execução.');
    $this->assertSession()->pageTextNotContains('Online');
    $this->assertSession()->pageTextNotContains('Desconectado');
    $this->assertSession()->responseNotContains('70%');
  }

}
