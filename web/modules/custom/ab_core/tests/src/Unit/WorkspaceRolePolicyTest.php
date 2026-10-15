<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_core\Unit;

use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Authorization\WorkspaceRolePolicy;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the workspace role matrix.
 *
 * @group ab_core
 */
class WorkspaceRolePolicyTest extends TestCase {

  /**
   * The policy under test.
   */
  private WorkspaceRolePolicy $policy;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->policy = new WorkspaceRolePolicy();
  }

  /**
   * A viewer can read and cannot write or review.
   */
  public function testViewerCannotWrite(): void {
    $permissions = $this->policy->permissions('viewer', 'active');
    $this->assertSame([WorkspacePermission::READ], $permissions);
    $this->assertNotContains(WorkspacePermission::WRITE, $permissions);
    $this->assertNotContains(WorkspacePermission::REVIEW, $permissions);
    $this->assertNotContains(WorkspacePermission::MANAGE_MEMBERS, $permissions);
  }

  /**
   * A member can write and cannot administer members.
   */
  public function testMemberCannotManageMembers(): void {
    $permissions = $this->policy->permissions('member', 'active');
    $this->assertContains(WorkspacePermission::WRITE, $permissions);
    $this->assertContains(WorkspacePermission::REVIEW, $permissions);
    $this->assertNotContains(WorkspacePermission::MANAGE_MEMBERS, $permissions);
    $this->assertNotContains(WorkspacePermission::TRANSFER_OWNERSHIP, $permissions);
  }

  /**
   * An admin manages members without transferring ownership.
   */
  public function testAdminManagesMembersWithoutOwnership(): void {
    $permissions = $this->policy->permissions('admin', 'active');
    $this->assertContains(WorkspacePermission::MANAGE_MEMBERS, $permissions);
    $this->assertContains(WorkspacePermission::MANAGE_BOARDS, $permissions);
    $this->assertNotContains(WorkspacePermission::TRANSFER_OWNERSHIP, $permissions);
    $this->assertNotContains(WorkspacePermission::MANAGE_CREDENTIALS, $permissions);
    $this->assertNotContains(WorkspacePermission::MANAGE_WORKSPACE, $permissions);
  }

  /**
   * An owner can transfer ownership.
   */
  public function testOwnerCanTransferOwnership(): void {
    $permissions = $this->policy->permissions('owner', 'active');
    $this->assertContains(WorkspacePermission::MANAGE_MEMBERS, $permissions);
    $this->assertContains(WorkspacePermission::TRANSFER_OWNERSHIP, $permissions);
    $this->assertContains(WorkspacePermission::MANAGE_CREDENTIALS, $permissions);
    $this->assertContains(WorkspacePermission::MANAGE_WORKSPACE, $permissions);
  }

  /**
   * A revoked membership grants nothing.
   */
  public function testRevokedRoleGrantsNothing(): void {
    $this->assertSame([], $this->policy->permissions('owner', 'revoked'));
    $this->assertSame([], $this->policy->permissions('viewer', 'revoked'));
    $this->assertSame([], $this->policy->permissions('agent', 'active'));
  }

}
