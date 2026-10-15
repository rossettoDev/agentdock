<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional test for AgentDock shell visual.
 *
 * @group ab_ui
 */
class ShellVisualTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'agent_board';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'ab_core',
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
  }

  /**
   * Tests that the shell visual renders correctly.
   */
  public function testShellVisualLayout(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '.ab-layout');
    $this->assertSession()->elementExists('css', '.ab-header');
    $this->assertSession()->elementExists('css', '.ab-main');
    $this->assertSession()->elementExists('css', '.ab-logo');
    $this->assertSession()->elementExists('css', '.ab-content');
  }

  /**
   * Tests that all ab_* modules are installed.
   */
  public function testModulesInstalled(): void {
    $module_handler = \Drupal::moduleHandler();
    $expected_modules = [
      'ab_core',
      'ab_board',
      'ab_agent',
      'ab_communication',
      'ab_ui',
    ];
    foreach ($expected_modules as $module) {
      $this->assertTrue(
        $module_handler->moduleExists($module),
        sprintf('Module %s is installed', $module)
      );
    }
  }

  /**
   * Tests that agent_board theme is active.
   */
  public function testThemeActive(): void {
    $active_theme = \Drupal::theme()->getActiveTheme()->getName();
    $this->assertEquals('agent_board', $active_theme, 'agent_board theme is active');
  }

  /**
   * Tests responsive layout classes.
   */
  public function testResponsiveClasses(): void {
    $this->drupalGet('<front>');
    $element = $this->assertSession()->elementExists('css', '.ab-layout');
    $this->assertNotNull($element, 'Layout container exists');
  }

}
