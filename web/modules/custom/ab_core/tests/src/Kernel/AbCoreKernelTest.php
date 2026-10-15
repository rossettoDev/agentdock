<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_core\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel test for AgentDock Core module.
 *
 * @group ab_core
 */
class AbCoreKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'options', 'ab_core'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('system', ['sequences']);
  }

  /**
   * Tests that ab_core module installs correctly.
   */
  public function testModuleInstallation(): void {
    $module_handler = \Drupal::moduleHandler();
    $this->assertTrue($module_handler->moduleExists('ab_core'), 'ab_core module is installed');
    $this->assertTrue($module_handler->moduleExists('user'), 'user module is installed as dependency');
  }

  /**
   * Tests database connection with PostgreSQL.
   */
  public function testDatabaseConnection(): void {
    $database = \Drupal::database();
    $this->assertNotNull($database, 'Database connection exists');

    $driver = $database->driver();
    $this->assertEquals('pgsql', $driver, 'Database driver is PostgreSQL');
  }

  /**
   * Tests basic entity operations.
   */
  public function testEntityOperations(): void {
    // Test that we can query users.
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $this->assertNotNull($storage, 'User entity storage is available');

    $users = $storage->loadMultiple();
    $this->assertIsArray($users, 'Can load users');
  }

}
