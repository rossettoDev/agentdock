<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_core\Kernel;

use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Verifies the human actor context derived from authentication.
 *
 * @group ab_core
 */
class HumanActorContextKernelTest extends KernelTestBase {

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
    $this->installEntitySchema('user_role');
    $this->installEntitySchema('ab_workspace');
    $this->installEntitySchema('ab_membership');
  }

  /**
   * A viewer membership does not grant writing.
   */
  public function testViewerCannotWrite(): void {
    $user = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->createWorkspace($user);
    $this->membership($workspace, $user, 'viewer');
    $this->setCurrentUser($user);

    $context = $this->factory()->create($workspace);
    $this->assertSame(ActorContext::TYPE_HUMAN, $context->actorType);
    $this->assertSame((string) $user->id(), $context->actorId);
    $this->assertSame($workspace->uuid(), $context->workspaceId);
    $this->assertNull($context->tokenId);
    $this->assertTrue($context->allows(WorkspacePermission::READ));
    $this->assertFalse($context->allows(WorkspacePermission::WRITE));
    $this->assertFalse($context->allows(WorkspacePermission::REVIEW));
    $this->assertFalse($context->allows(WorkspacePermission::MANAGE_MEMBERS));
  }

  /**
   * A member can write and cannot administer members.
   */
  public function testMemberCannotManageMembers(): void {
    $owner = $this->createUserAccount('ada', 'ada@example.com');
    $member = $this->createUserAccount('grace', 'grace@example.com');
    $workspace = $this->createWorkspace($owner);
    $this->membership($workspace, $member, 'member');
    $this->setCurrentUser($member);

    $context = $this->factory()->create($workspace);
    $this->assertTrue($context->allows(WorkspacePermission::WRITE));
    $this->assertTrue($context->allows(WorkspacePermission::REVIEW));
    $this->assertFalse($context->allows(WorkspacePermission::MANAGE_MEMBERS));
    $this->assertFalse($context->allows(WorkspacePermission::TRANSFER_OWNERSHIP));
  }

  /**
   * A Drupal administrator role does not grant workspace writing.
   */
  public function testGlobalRoleDoesNotGrantWrite(): void {
    $role = Role::create([
      'id' => 'platform_admin',
      'label' => 'Platform admin',
    ]);
    $role->grantPermission('administer site configuration');
    $role->save();

    $user = $this->createUserAccount('ada', 'ada@example.com');
    $user->addRole('platform_admin');
    $user->save();
    $workspace = $this->createWorkspace($user);
    $this->membership($workspace, $user, 'viewer');
    $this->setCurrentUser($user);

    $this->assertTrue($user->hasPermission('administer site configuration'));
    $context = $this->factory()->create($workspace);
    $this->assertFalse($context->allows(WorkspacePermission::WRITE));
    $this->assertFalse($context->allows(WorkspacePermission::MANAGE_MEMBERS));
  }

  /**
   * Membership in another workspace does not grant this one.
   */
  public function testOtherWorkspaceGrantsNothing(): void {
    $user = $this->createUserAccount('ada', 'ada@example.com');
    $home = $this->createWorkspace($user);
    $other = $this->createWorkspace($user);
    $this->membership($home, $user, 'owner');
    $this->setCurrentUser($user);

    $context = $this->factory()->create($other);
    $this->assertFalse($context->allows(WorkspacePermission::READ));
    $this->assertFalse($context->allows(WorkspacePermission::WRITE));
    $this->assertFalse($context->allows(WorkspacePermission::MANAGE_MEMBERS));
  }

  /**
   * A revoked membership grants nothing.
   */
  public function testRevokedMembershipGrantsNothing(): void {
    $user = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->createWorkspace($user);
    $membership = $this->membership($workspace, $user, 'owner');
    $membership->set('status', 'revoked');
    $membership->save();
    $this->setCurrentUser($user);

    $context = $this->factory()->create($workspace);
    $this->assertFalse($context->allows(WorkspacePermission::READ));
    $this->assertFalse($context->allows(WorkspacePermission::WRITE));
    $this->assertFalse($context->allows(WorkspacePermission::TRANSFER_OWNERSHIP));
  }

  /**
   * Creates an active user.
   */
  private function createUserAccount(string $name, string $mail): User {
    $user = User::create([
      'name' => $name,
      'mail' => $mail,
      'status' => 1,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Creates a workspace owned by the given user.
   */
  private function createWorkspace(User $owner): Workspace {
    $workspace = Workspace::create([
      'name' => 'Equipe ' . $owner->getAccountName(),
      'owner' => $owner->id(),
      'timezone' => 'America/Sao_Paulo',
    ]);
    $workspace->save();
    $this->assertInstanceOf(Workspace::class, $workspace);
    return $workspace;
  }

  /**
   * Stores an active membership.
   */
  private function membership(Workspace $workspace, User $user, string $role): Membership {
    $membership = Membership::create([
      'workspace' => $workspace->id(),
      'user' => $user->id(),
      'role' => $role,
      'status' => 'active',
    ]);
    $membership->save();
    $this->assertInstanceOf(Membership::class, $membership);
    return $membership;
  }

  /**
   * Authenticates the account for the factory.
   */
  private function setCurrentUser(User $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Loads the human context factory.
   */
  private function factory(): HumanActorContextFactory {
    $factory = $this->container->get(HumanActorContextFactory::class);
    $this->assertInstanceOf(HumanActorContextFactory::class, $factory);
    return $factory;
  }

}
