<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_core\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Simple unit test example for AgentDock Core.
 *
 * @group ab_core
 * @coversDefaultClass \Drupal\ab_core
 */
class ExampleTest extends TestCase {

  /**
   * Tests basic PHP functionality.
   */
  public function testBasicAssertions(): void {
    $this->assertTrue(TRUE, 'TRUE is true');
    $this->assertFalse(FALSE, 'FALSE is false');
    $this->assertEquals(1 + 1, 2, '1 + 1 equals 2');
  }

  /**
   * Tests string operations.
   */
  public function testStringOperations(): void {
    $string = 'AgentDock';
    $this->assertIsString($string);
    $this->assertEquals(9, strlen($string));
    $this->assertStringContainsString('Agent', $string);
  }

  /**
   * Tests array operations.
   */
  public function testArrayOperations(): void {
    $array = ['workspace', 'board', 'task'];
    $this->assertIsArray($array);
    $this->assertCount(3, $array);
    $this->assertContains('workspace', $array);
  }

}
