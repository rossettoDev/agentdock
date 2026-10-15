<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_core\Kernel;

use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipRepository;
use Drupal\ab_core\WorkspaceRepository;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies that a known UUID does not cross workspace boundaries.
 *
 * @group ab_core
 */
class WorkspaceUuidIsolationKernelTest extends KernelTestBase {

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
   * A known UUID from another workspace cannot be read or written.
   */
  public function testKnownForeignUuidCannotBeReadOrWritten(): void {
    [$ada, , , $grace_workspace] = $this->twoWorkspaces();
    $grace_membership = $this->onlyMembership($grace_workspace);
    $workspace_uuid = $grace_workspace->uuid();
    $membership_uuid = $grace_membership->uuid();

    $this->setCurrentUser($ada);
    $workspaces = $this->workspaces();
    $this->assertNull($workspaces->loadAccessibleByUuid($workspace_uuid));
    try {
      $this->workspaces()->renameAccessibleByUuid($workspace_uuid, 'Invadida');
      $this->fail('A known foreign workspace UUID was renamed.');
    }
    catch (ScopedResourceNotFoundException $exception) {
      $this->assertSame('Workspace was not found.', $exception->getMessage());
    }

    $memberships = $this->memberships();
    $this->assertNull($memberships->loadAccessibleByUuid($membership_uuid));
    try {
      $this->memberships()->updateRole($membership_uuid, 'viewer');
      $this->fail('A known foreign membership UUID was changed.');
    }
    catch (ScopedResourceNotFoundException $exception) {
      $this->assertSame('Membership was not found.', $exception->getMessage());
    }

    $reloaded_workspace = $this->reloadWorkspace($grace_workspace);
    $this->assertSame('Casa Grace', $reloaded_workspace->label());
    $reloaded_membership = $this->reloadMembership($grace_membership);
    $this->assertSame('owner', $reloaded_membership->get('role')->value);
  }

  /**
   * The owner and a viewer still reach their own UUIDs.
   */
  public function testAuthorizedUuidAccessStillWorks(): void {
    [$ada, $ada_workspace] = $this->twoWorkspaces();
    $viewer = $this->createUserAccount('view', 'view@example.com');
    $viewer_membership = $this->membership($ada_workspace, $viewer, 'viewer');
    $workspace_uuid = $ada_workspace->uuid();
    $membership_uuid = $viewer_membership->uuid();

    $this->setCurrentUser($ada);
    $loaded = $this->workspaces()->loadAccessibleByUuid($workspace_uuid);
    $this->assertInstanceOf(Workspace::class, $loaded);
    $this->assertSame('Casa Ada', $loaded->label());
    $renamed = $this->workspaces()->renameAccessibleByUuid(
      $workspace_uuid,
      'Casa Ada Nova',
    );
    $this->assertSame('Casa Ada Nova', $renamed->label());

    $this->setCurrentUser($viewer);
    $visible = $this->workspaces()->loadAccessibleByUuid($workspace_uuid);
    $this->assertInstanceOf(Workspace::class, $visible);
    $this->assertSame('Casa Ada Nova', $visible->label());
    try {
      $this->workspaces()->renameAccessibleByUuid(
        $workspace_uuid,
        'Casa do viewer',
      );
      $this->fail('A viewer renamed a visible workspace.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame(
        'Casa Ada Nova',
        $this->workspaces()->loadAccessibleByUuid($workspace_uuid)?->label(),
      );
    }
    $own = $this->memberships()->loadAccessibleByUuid($membership_uuid);
    $this->assertInstanceOf(Membership::class, $own);
    try {
      $this->memberships()->updateRole($membership_uuid, 'admin');
      $this->fail('A viewer changed a membership role.');
    }
    catch (ScopedOperationDeniedException) {
      $still = $this->memberships()->loadAccessibleByUuid($membership_uuid);
      $this->assertInstanceOf(Membership::class, $still);
      $this->assertSame('viewer', $still->get('role')->value);
    }

    $this->setCurrentUser($ada);
    $updated = $this->memberships()->updateRole($membership_uuid, 'member');
    $this->assertSame('member', $updated->get('role')->value);

    $reloaded_workspace = $this->reloadWorkspace($ada_workspace);
    $this->assertSame('Casa Ada Nova', $reloaded_workspace->label());
    $reloaded_membership = $this->reloadMembership($viewer_membership);
    $this->assertSame('member', $reloaded_membership->get('role')->value);
  }

  /**
   * An unknown lowercase UUID is treated as absent.
   */
  public function testMissingUuidLooksAbsent(): void {
    [$ada] = $this->twoWorkspaces();
    $missing = '00000000-0000-4000-8000-000000000000';
    $this->setCurrentUser($ada);
    $this->assertNull($this->workspaces()->loadAccessibleByUuid($missing));
    $this->expectException(ScopedResourceNotFoundException::class);
    $this->workspaces()->renameAccessibleByUuid($missing, 'Ninguem');
  }

  /**
   * A value that is not a lowercase UUID is rejected.
   */
  public function testMalformedUuidIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->workspaces()->loadAccessibleByUuid('NOT-A-UUID');
  }

  /**
   * Creates two owners, each with one workspace.
   *
   * @return array
   *   Ada, the Ada workspace, Grace and the Grace workspace.
   */
  private function twoWorkspaces(): array {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $grace = $this->createUserAccount('grace', 'grace@example.com');
    $ada_workspace = $this->createWorkspace($ada, 'Casa Ada');
    $grace_workspace = $this->createWorkspace($grace, 'Casa Grace');
    $this->membership($ada_workspace, $ada, 'owner');
    $this->membership($grace_workspace, $grace, 'owner');
    return [$ada, $ada_workspace, $grace, $grace_workspace];
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
  private function createWorkspace(User $owner, string $name): Workspace {
    $workspace = Workspace::create([
      'name' => $name,
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
  private function membership(
    Workspace $workspace,
    User $user,
    string $role,
  ): Membership {
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
   * Loads the owner membership of a workspace.
   */
  private function onlyMembership(Workspace $workspace): Membership {
    $manager = $this->container->get('entity_type.manager');
    $storage = $manager->getStorage('ab_membership');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->execute();
    $this->assertCount(1, $ids);
    $membership = Membership::load((string) reset($ids));
    $this->assertInstanceOf(Membership::class, $membership);
    return $membership;
  }

  /**
   * Reloads a workspace from storage.
   */
  private function reloadWorkspace(Workspace $workspace): Workspace {
    $manager = $this->container->get('entity_type.manager');
    $storage = $manager->getStorage('ab_workspace');
    $storage->resetCache([$workspace->id()]);
    $reloaded = $storage->load($workspace->id());
    $this->assertInstanceOf(Workspace::class, $reloaded);
    return $reloaded;
  }

  /**
   * Reloads a membership from storage.
   */
  private function reloadMembership(Membership $membership): Membership {
    $manager = $this->container->get('entity_type.manager');
    $storage = $manager->getStorage('ab_membership');
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
   * Loads the workspace repository.
   */
  private function workspaces(): WorkspaceRepository {
    $repository = $this->container->get(WorkspaceRepository::class);
    $this->assertInstanceOf(WorkspaceRepository::class, $repository);
    return $repository;
  }

  /**
   * Loads the membership repository.
   */
  private function memberships(): MembershipRepository {
    $repository = $this->container->get(MembershipRepository::class);
    $this->assertInstanceOf(MembershipRepository::class, $repository);
    return $repository;
  }

}
