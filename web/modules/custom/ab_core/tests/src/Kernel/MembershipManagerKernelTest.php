<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_core\Kernel;

use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\PersonalWorkspaceProvisionerInterface;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies membership administration and ownership transfer.
 *
 * @group ab_core
 */
class MembershipManagerKernelTest extends KernelTestBase {

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
    $this->installEntitySchema('ab_workspace');
    $this->installEntitySchema('ab_membership');
  }

  /**
   * An admin cannot grant or remove the owner role.
   */
  public function testAdminCannotPromoteOrRemoveOwner(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $admin = $this->createUserAccount('admin', 'admin@example.com');
    $this->createUserAccount('grace', 'grace@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $manager = $this->manager();
    $manager->addMember($workspace, 'admin', 'admin');
    $manager->addMember($workspace, 'grace', 'member');
    $owner = $this->onlyRole($workspace, 'owner');

    $this->setCurrentUser($admin);
    try {
      $manager->changeRole($workspace, $owner->uuid(), 'admin');
      $this->fail('An admin changed the owner role.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame('owner', $this->reloadMembership($owner)->get('role')->value);
    }
    try {
      $manager->addMember($workspace, 'grace', 'owner');
      $this->fail('An admin assigned the owner role.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame('member', $this->onlyRole($workspace, 'member')->get('role')->value);
    }
    try {
      $manager->remove($workspace, $owner->uuid());
      $this->fail('An admin removed the owner.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame('active', $this->reloadMembership($owner)->get('status')->value);
    }

    $grace_membership = $this->onlyRole($workspace, 'member');
    $manager->changeRole($workspace, $grace_membership->uuid(), 'viewer');
    $this->assertSame('viewer', $this->reloadMembership($grace_membership)->get('role')->value);
    $manager->remove($workspace, $grace_membership->uuid());
    $this->assertSame('revoked', $this->reloadMembership($grace_membership)->get('status')->value);
    $restored = $manager->addMember($workspace, 'grace', 'member');
    $this->assertSame($grace_membership->id(), $restored->id());
    $this->assertSame('active', $restored->get('status')->value);
  }

  /**
   * Transfer leaves one owner and keeps the previous owner as admin.
   */
  public function testTransferKeepsOneOwner(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $grace = $this->createUserAccount('grace', 'grace@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $this->manager()->transferOwnership($workspace, 'grace');

    $stored = $this->reloadWorkspace($workspace);
    $this->assertSame((string) $grace->id(), $stored->get('owner')->getString());
    $this->assertSame((string) $grace->id(), $this->onlyRole($stored, 'owner')->get('user')->getString());
    $this->assertSame((string) $ada->id(), $this->onlyRole($stored, 'admin')->get('user')->getString());
    $this->assertCount(1, $this->ownerIds($stored));
  }

  /**
   * A failed transfer does not leave a second owner or a new membership.
   */
  public function testTransferRollsBack(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $grace = $this->createUserAccount('grace', 'grace@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $manager = new class(
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get(MembershipScope::class),
    ) extends MembershipManager {

      /**
       * Forces the transfer transaction to roll back.
       */
      protected function afterTransfer(Workspace $workspace): void {
        parent::afterTransfer($workspace);
        throw new \RuntimeException('Force rollback.');
      }

    };

    try {
      $manager->transferOwnership($workspace, 'grace');
      $this->fail('The forced transfer failure was committed.');
    }
    catch (\RuntimeException) {
      $stored = $this->reloadWorkspace($workspace);
      $this->assertSame((string) $ada->id(), $stored->get('owner')->getString());
      $this->assertSame((string) $ada->id(), $this->onlyRole($stored, 'owner')->get('user')->getString());
      $this->assertSame([], $this->membershipIds($stored, $grace));
    }
  }

  /**
   * The personal workspace stays with its owner.
   */
  public function testPersonalWorkspaceIsNotTransferred(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $this->createUserAccount('grace', 'grace@example.com');
    $provisioner = $this->container->get(PersonalWorkspaceProvisionerInterface::class);
    $this->assertInstanceOf(PersonalWorkspaceProvisionerInterface::class, $provisioner);
    $workspace = $provisioner->provision($ada);
    $this->setCurrentUser($ada);
    try {
      $this->manager()->transferOwnership($workspace, 'grace');
      $this->fail('A personal workspace was transferred.');
    }
    catch (ScopedOperationDeniedException) {
      $stored = $this->reloadWorkspace($workspace);
      $this->assertSame((string) $ada->id(), $stored->get('owner')->getString());
    }
  }

  /**
   * A member cannot add people, and an outsider cannot list them.
   */
  public function testScopeStillApplies(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $member = $this->createUserAccount('member', 'member@example.com');
    $outsider = $this->createUserAccount('outsider', 'outsider@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $this->manager()->addMember($workspace, 'member', 'member');

    $this->setCurrentUser($member);
    try {
      $this->manager()->addMember($workspace, 'outsider', 'viewer');
      $this->fail('A member added another user.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame([], $this->membershipIds($workspace, $outsider));
    }

    $this->setCurrentUser($outsider);
    $this->expectException(ScopedResourceNotFoundException::class);
    $this->manager()->listMembers($workspace);
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
   * Loads the membership with one role.
   */
  private function onlyRole(Workspace $workspace, string $role): Membership {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_membership');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('role', $role)
      ->condition('status', 'active')
      ->execute();
    $this->assertCount(1, $ids);
    $membership = $storage->load((string) reset($ids));
    $this->assertInstanceOf(Membership::class, $membership);
    return $membership;
  }

  /**
   * Returns membership ids of a user in a workspace.
   *
   * @return string[]
   *   Membership ids.
   */
  private function membershipIds(Workspace $workspace, User $user): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_membership');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('user', $user->id())
      ->execute();
    return array_values(array_map('strval', $ids));
  }

  /**
   * Returns active owner membership ids.
   *
   * @return string[]
   *   Membership ids.
   */
  private function ownerIds(Workspace $workspace): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_membership');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('role', 'owner')
      ->condition('status', 'active')
      ->execute();
    return array_values(array_map('strval', $ids));
  }

  /**
   * Reloads a workspace from storage.
   */
  private function reloadWorkspace(Workspace $workspace): Workspace {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_workspace');
    $storage->resetCache([$workspace->id()]);
    $reloaded = $storage->load($workspace->id());
    $this->assertInstanceOf(Workspace::class, $reloaded);
    return $reloaded;
  }

  /**
   * Reloads a membership from storage.
   */
  private function reloadMembership(Membership $membership): Membership {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_membership');
    $storage->resetCache([$membership->id()]);
    $reloaded = $storage->load($membership->id());
    $this->assertInstanceOf(Membership::class, $reloaded);
    return $reloaded;
  }

  /**
   * Authenticates the account.
   */
  private function setCurrentUser(User $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Loads the workspace creator.
   */
  private function creator(): WorkspaceCreator {
    $creator = $this->container->get(WorkspaceCreator::class);
    $this->assertInstanceOf(WorkspaceCreator::class, $creator);
    return $creator;
  }

  /**
   * Loads the membership manager.
   */
  private function manager(): MembershipManager {
    $manager = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $manager);
    return $manager;
  }

}
