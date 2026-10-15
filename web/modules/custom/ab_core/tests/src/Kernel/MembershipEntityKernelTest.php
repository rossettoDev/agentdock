<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_core\Kernel;

use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipSchemaInstaller;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies membership uniqueness and schema.
 *
 * @group ab_core
 */
class MembershipEntityKernelTest extends KernelTestBase {

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
   * A valid membership stores the association.
   */
  public function testValidMembershipPersists(): void {
    $user = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->createWorkspace($user);
    $membership = $this->membership($workspace, $user, 'member');
    $violations = $membership->validate();
    $this->assertSame(0, $violations->count(), (string) $violations);
    $membership->save();

    $loaded = Membership::load($membership->id());
    $this->assertInstanceOf(Membership::class, $loaded);
    $this->assertSame((string) $workspace->id(), $loaded->get('workspace')->getString());
    $this->assertSame((string) $user->id(), $loaded->get('user')->getString());
    $this->assertSame('member', $loaded->get('role')->value);
    $this->assertSame('active', $loaded->get('status')->value);
  }

  /**
   * The same user cannot join a workspace twice.
   */
  public function testDuplicateMembershipIsRejected(): void {
    $user = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->createWorkspace($user);
    $this->membership($workspace, $user, 'member')->save();

    $duplicate = $this->membership($workspace, $user, 'admin');
    $this->assertStringContainsString('already belongs', $this->violationText($duplicate));
    try {
      $duplicate->save();
      $this->fail('Duplicate membership was stored.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('already belongs', $exception->getMessage());
    }
    $this->assertCount(1, $this->membershipIds());
  }

  /**
   * The same user may belong to another workspace.
   */
  public function testSameUserMayJoinAnotherWorkspace(): void {
    $user = $this->createUserAccount('ada', 'ada@example.com');
    $first = $this->createWorkspace($user);
    $second = $this->createWorkspace($user);
    $this->membership($first, $user, 'owner')->save();
    $this->membership($second, $user, 'member')->save();
    $this->assertCount(2, $this->membershipIds());
  }

  /**
   * Another user may join the same workspace.
   */
  public function testAnotherUserMayJoinTheSameWorkspace(): void {
    $owner = $this->createUserAccount('ada', 'ada@example.com');
    $collaborator = $this->createUserAccount('grace', 'grace@example.com');
    $workspace = $this->createWorkspace($owner);
    $this->membership($workspace, $owner, 'owner')->save();
    $this->membership($workspace, $collaborator, 'member')->save();
    $this->assertCount(2, $this->membershipIds());
  }

  /**
   * The anonymous user cannot be a member.
   */
  public function testAnonymousUserIsRejected(): void {
    $owner = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->createWorkspace($owner);
    $membership = Membership::create([
      'workspace' => $workspace->id(),
      'user' => 0,
      'role' => 'viewer',
    ]);
    $this->assertStringContainsString('reference', strtolower($this->violationText($membership)));
  }

  /**
   * The database unique key exists after entity installation.
   */
  public function testWorkspaceUserUniqueKeyExists(): void {
    $schema = $this->container->get('database')->schema();
    $this->assertTrue($schema->constraintExists('ab_membership', 'workspace_user__key'));
  }

  /**
   * The update hook creates the same schema on an existing install.
   */
  public function testUpdateInstallsMembershipSchema(): void {
    $schema = $this->container->get('database')->schema();
    $schema->dropTable('ab_membership');
    $this->assertFalse($schema->tableExists('ab_membership'));

    $created = MembershipSchemaInstaller::install();
    $this->assertSame('Installed the membership entity schema.', $created);
    $this->assertTrue($schema->tableExists('ab_membership'));
    $this->assertTrue($schema->fieldExists('ab_membership', 'workspace'));
    $this->assertTrue($schema->fieldExists('ab_membership', 'user'));
    $this->assertTrue($schema->fieldExists('ab_membership', 'role'));
    $this->assertTrue($schema->fieldExists('ab_membership', 'status'));
    $this->assertTrue($schema->constraintExists('ab_membership', 'workspace_user__key'));

    $again = MembershipSchemaInstaller::install();
    $this->assertSame('Membership schema already present.', $again);
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
      'name' => 'Pessoal ' . $owner->getAccountName(),
      'owner' => $owner->id(),
      'timezone' => 'America/Sao_Paulo',
    ]);
    $workspace->save();
    $this->assertInstanceOf(Workspace::class, $workspace);
    return $workspace;
  }

  /**
   * Builds a membership.
   */
  private function membership(Workspace $workspace, User $user, string $role): Membership {
    $membership = Membership::create([
      'workspace' => $workspace->id(),
      'user' => $user->id(),
      'role' => $role,
    ]);
    $this->assertInstanceOf(Membership::class, $membership);
    return $membership;
  }

  /**
   * Returns stored membership ids.
   *
   * @return array<int|string>
   *   Membership ids.
   */
  private function membershipIds(): array {
    return $this->container->get('entity_type.manager')->getStorage('ab_membership')->getQuery()
      ->accessCheck(FALSE)
      ->execute();
  }

  /**
   * Returns property paths and messages for a membership.
   */
  private function violationText(Membership $membership): string {
    $lines = [];
    foreach ($membership->validate() as $violation) {
      $lines[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    $this->assertNotSame([], $lines);
    return implode("\n", $lines);
  }

}
