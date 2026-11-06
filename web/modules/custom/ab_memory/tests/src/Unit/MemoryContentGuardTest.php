<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_memory\Unit;

use Drupal\ab_memory\MemoryContentGuard;
use Drupal\ab_memory\MemoryRejectedException;
use Drupal\Tests\UnitTestCase;

/**
 * Verifies the pilot secret heuristics.
 *
 * @group ab_memory
 */
class MemoryContentGuardTest extends UnitTestCase {

  /**
   * Safe markdown is accepted.
   */
  public function testSafeContentPasses(): void {
    $guard = new MemoryContentGuard();
    $guard->assertSafe('Decisão', "Usamos o board X.\nSem segredo.");
    $this->assertTrue(TRUE);
  }

  /**
   * Bearer headers are refused.
   */
  public function testBearerIsRejected(): void {
    $guard = new MemoryContentGuard();
    $this->expectException(MemoryRejectedException::class);
    $guard->assertSafe('x', "Authorization: Bearer abc.def");
  }

  /**
   * AgentDock token shapes are refused.
   */
  public function testAgentTokenShapeIsRejected(): void {
    $guard = new MemoryContentGuard();
    $this->expectException(MemoryRejectedException::class);
    $guard->assertSafe('x', 'token ab_' . str_repeat('a', 16) . '.' . str_repeat('b', 32));
  }

  /**
   * Explicit secret assignments are refused.
   */
  public function testSecretAssignmentIsRejected(): void {
    $guard = new MemoryContentGuard();
    $this->expectException(MemoryRejectedException::class);
    $guard->assertSafe('x', "api_key=super-secret-value\n");
  }

  /**
   * Secrets in tags are refused.
   */
  public function testSecretInTagIsRejected(): void {
    $guard = new MemoryContentGuard();
    $this->expectException(MemoryRejectedException::class);
    $guard->assertSafe('ok', 'corpo limpo', ['password=hunter2']);
  }

  /**
   * Secrets in change_reason are refused.
   */
  public function testSecretInChangeReasonIsRejected(): void {
    $guard = new MemoryContentGuard();
    $this->expectException(MemoryRejectedException::class);
    $guard->assertSafe('ok', 'corpo limpo', [], 'Authorization: Bearer abc.def');
  }

}
