<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_core\Unit;

use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\WorkspacePermission;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the actor context contract.
 *
 * @group ab_core
 */
class ActorContextTest extends TestCase {

  /**
   * A human context checks only the permissions it was given.
   */
  public function testHumanContextAllowsGrantedPermissions(): void {
    $context = new ActorContext(
      ActorContext::TYPE_HUMAN,
      '4',
      '11111111-1111-4111-8111-111111111111',
      [WorkspacePermission::READ],
    );
    $this->assertTrue($context->allows(WorkspacePermission::READ));
    $this->assertFalse($context->allows(WorkspacePermission::WRITE));
    $this->assertNull($context->tokenId);
  }

  /**
   * A human actor cannot carry a token.
   */
  public function testHumanContextRejectsToken(): void {
    $this->expectException(\InvalidArgumentException::class);
    new ActorContext(
      ActorContext::TYPE_HUMAN,
      '4',
      '11111111-1111-4111-8111-111111111111',
      [],
      'token-1',
    );
  }

  /**
   * Unknown permissions are rejected.
   */
  public function testUnknownPermissionIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    new ActorContext(
      ActorContext::TYPE_HUMAN,
      '4',
      '11111111-1111-4111-8111-111111111111',
      ['administer site configuration'],
    );
  }

}
