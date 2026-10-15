<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_core\Kernel;

use Drupal\ab_core\Authorization\WorkspaceBoundary;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceRepository;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies workspace scope for listings, counts and cache.
 *
 * @group ab_core
 */
class WorkspaceScopeKernelTest extends KernelTestBase {

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
   * Two users see only their own workspaces in lists and counts.
   */
  public function testListingsAndCountsStaySeparate(): void {
    [$ada, $grace, $ada_workspace, $grace_workspace] = $this->twoUsers();

    $this->setCurrentUser($ada);
    $repository = $this->repository();
    $visible = $repository->accessibleWorkspaces();
    $this->assertCount(1, $visible);
    $this->assertSame($ada_workspace->id(), $visible[0]->id());
    $this->assertSame(1, $repository->countAccessibleWorkspaces());

    $this->setCurrentUser($grace);
    $repository = $this->repository();
    $visible = $repository->accessibleWorkspaces();
    $this->assertCount(1, $visible);
    $this->assertSame($grace_workspace->id(), $visible[0]->id());
    $this->assertSame(1, $repository->countAccessibleWorkspaces());
  }

  /**
   * An access-checked entity query does not cross workspaces.
   */
  public function testAccessQueryStaysInsideWorkspace(): void {
    [$ada, $grace] = $this->twoUsers();
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_workspace');

    $this->setCurrentUser($ada);
    $ada_ids = array_values($storage->getQuery()->accessCheck(TRUE)->execute());
    $this->setCurrentUser($grace);
    $grace_ids = array_values($storage->getQuery()->accessCheck(TRUE)->execute());

    $this->assertCount(1, $ada_ids);
    $this->assertCount(1, $grace_ids);
    $this->assertNotSame($ada_ids[0], $grace_ids[0]);
  }

  /**
   * A cached listing rendered for one user is not reused for the other.
   */
  public function testRenderedListingDoesNotLeak(): void {
    [$ada, $grace] = $this->twoUsers();
    $renderer = $this->container->get('renderer');

    $this->setCurrentUser($ada);
    $ada_build = $this->repository()->workspaceListing();
    $this->assertContains('ab_memberships', $ada_build['#cache']['contexts']);
    $this->assertContains('ab_membership_list:' . $ada->id(), $ada_build['#cache']['tags']);
    $ada_markup = (string) $renderer->renderRoot($ada_build);

    $this->setCurrentUser($grace);
    $grace_build = $this->repository()->workspaceListing();
    $grace_markup = (string) $renderer->renderRoot($grace_build);

    $this->assertStringContainsString('Casa Ada', $ada_markup);
    $this->assertStringNotContainsString('Casa Grace', $ada_markup);
    $this->assertStringContainsString('Casa Grace', $grace_markup);
    $this->assertStringNotContainsString('Casa Ada', $grace_markup);
  }

  /**
   * An outsider cannot view a workspace, and a viewer cannot update it.
   */
  public function testWorkspaceAccessFollowsMembership(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $grace = $this->createUserAccount('grace', 'grace@example.com');
    $viewer = $this->createUserAccount('view', 'view@example.com');
    $workspace = $this->createWorkspace($ada, 'Casa Ada');
    $this->membership($workspace, $ada, 'owner');
    $this->membership($workspace, $viewer, 'viewer');

    $this->assertTrue($workspace->access('view', $ada));
    $this->assertTrue($workspace->access('update', $ada));
    $this->assertTrue($workspace->access('view', $viewer));
    $this->assertFalse($workspace->access('update', $viewer));
    $this->assertFalse($workspace->access('view', $grace));
    $this->assertFalse($workspace->access('update', $grace));
  }

  /**
   * A member cannot administer a membership, including one elsewhere.
   */
  public function testMembershipAccessStaysInsideWorkspace(): void {
    [$ada, $grace, $ada_workspace, $grace_workspace] = $this->twoUsers();
    $member = $this->createUserAccount('member', 'member@example.com');
    $ada_membership = $this->onlyMembership($ada_workspace, $ada);
    $this->membership($ada_workspace, $member, 'member');
    $grace_membership = $this->onlyMembership($grace_workspace, $grace);

    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('ab_membership');
    $this->assertTrue($ada_membership->access('view', $member));
    $this->assertFalse($ada_membership->access('update', $member));
    $this->assertFalse($handler->createAccess(NULL, $member, ['workspace' => $ada_workspace]));
    $this->assertFalse($grace_membership->access('view', $member));
    $this->assertFalse($handler->createAccess(NULL, $ada, ['workspace' => $grace_workspace]));
    $this->assertTrue($handler->createAccess(NULL, $ada, ['workspace' => $ada_workspace]));
  }

  /**
   * A membership from one workspace is a cross-workspace reference in another.
   */
  public function testCrossWorkspaceReferenceIsRejected(): void {
    [$ada, $grace, $ada_workspace] = $this->twoUsers();
    $membership = $this->onlyMembership($ada_workspace, $ada);
    $boundary = new WorkspaceBoundary();

    $this->assertFalse($boundary->isCrossWorkspaceReference($ada_workspace, $membership));
    $grace_workspace = Workspace::load($this->repositoryIdsFor($grace)[0]);
    $this->assertInstanceOf(Workspace::class, $grace_workspace);
    $this->assertTrue($boundary->isCrossWorkspaceReference($grace_workspace, $membership));
  }

  /**
   * Creates two owners, each with a personal-looking separate workspace.
   *
   * @return array{0: \Drupal\user\Entity\User, 1: \Drupal\user\Entity\User, 2: \Drupal\ab_core\Entity\Workspace, 3: \Drupal\ab_core\Entity\Workspace}
   *   The two users and their workspaces.
   */
  private function twoUsers(): array {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $grace = $this->createUserAccount('grace', 'grace@example.com');
    $ada_workspace = $this->createWorkspace($ada, 'Casa Ada');
    $grace_workspace = $this->createWorkspace($grace, 'Casa Grace');
    $this->membership($ada_workspace, $ada, 'owner');
    $this->membership($grace_workspace, $grace, 'owner');
    return [$ada, $grace, $ada_workspace, $grace_workspace];
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
   * Loads the membership stored for a user in a workspace.
   */
  private function onlyMembership(Workspace $workspace, User $user): Membership {
    $ids = $this->container->get('entity_type.manager')->getStorage('ab_membership')->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('user', $user->id())
      ->execute();
    $this->assertCount(1, $ids);
    $membership = Membership::load(reset($ids));
    $this->assertInstanceOf(Membership::class, $membership);
    return $membership;
  }

  /**
   * Returns workspace ids visible to a user.
   *
   * @return string[]
   *   Workspace ids.
   */
  private function repositoryIdsFor(User $user): array {
    $this->setCurrentUser($user);
    $ids = [];
    foreach ($this->repository()->accessibleWorkspaces() as $workspace) {
      $ids[] = (string) $workspace->id();
    }
    return $ids;
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
  private function repository(): WorkspaceRepository {
    $repository = $this->container->get(WorkspaceRepository::class);
    $this->assertInstanceOf(WorkspaceRepository::class, $repository);
    return $repository;
  }

}
