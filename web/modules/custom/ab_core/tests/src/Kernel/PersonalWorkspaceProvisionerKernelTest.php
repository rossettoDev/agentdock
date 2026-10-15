<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_core\Kernel;

use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\PersonalWorkspaceProvisioner;
use Drupal\ab_core\PersonalWorkspaceProvisionerInterface;
use Drupal\ab_core\PersonalWorkspaceSchema;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Verifies idempotent provisioning of a personal workspace.
 *
 * @group ab_core
 */
class PersonalWorkspaceProvisionerKernelTest extends KernelTestBase {

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
   * Provisioning creates one workspace and one owner membership.
   */
  public function testProvisionCreatesWorkspaceAndOwnerMembership(): void {
    $user = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->provisioner()->provision($user);

    $this->assertSame(PersonalWorkspaceProvisionerInterface::NAME, $workspace->label());
    $this->assertSame(PersonalWorkspaceProvisionerInterface::TIMEZONE, $workspace->get('timezone')->getString());
    $this->assertSame('1', $workspace->get('personal')->getString());
    $this->assertSame((string) $user->id(), (string) $workspace->getOwnerId());
    $this->assertSame('active', $workspace->get('status')->getString());

    $membership = $this->onlyMembership();
    $this->assertSame((string) $workspace->id(), $membership->get('workspace')->getString());
    $this->assertSame((string) $user->id(), $membership->get('user')->getString());
    $this->assertSame('owner', $membership->get('role')->getString());
    $this->assertSame('active', $membership->get('status')->getString());
    $this->assertTrue(PersonalWorkspaceSchema::indexExists($this->container->get('database')));
  }

  /**
   * A second call returns the same workspace and does not add a membership.
   */
  public function testRepeatedProvisionDoesNotDuplicate(): void {
    $user = $this->createUserAccount('ada', 'ada@example.com');
    $provisioner = $this->provisioner();
    $first = $provisioner->provision($user);
    $second = $provisioner->provision($user);

    $this->assertSame($first->id(), $second->id());
    $this->assertCount(1, $this->ids('ab_workspace'));
    $this->assertCount(1, $this->ids('ab_membership'));
  }

  /**
   * Another user receives a different personal workspace.
   */
  public function testEachUserReceivesOnePersonalWorkspace(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $grace = $this->createUserAccount('grace', 'grace@example.com');
    $provisioner = $this->provisioner();
    $first = $provisioner->provision($ada);
    $second = $provisioner->provision($grace);

    $this->assertNotSame($first->id(), $second->id());
    $this->assertCount(2, $this->ids('ab_workspace'));
    $this->assertCount(2, $this->ids('ab_membership'));
  }

  /**
   * A non-personal workspace does not satisfy provisioning.
   */
  public function testOrdinaryWorkspaceIsNotReused(): void {
    $user = $this->createUserAccount('ada', 'ada@example.com');
    $ordinary = Workspace::create([
      'name' => 'Equipe',
      'owner' => $user->id(),
      'timezone' => 'UTC',
      'personal' => FALSE,
    ]);
    $ordinary->save();

    $personal = $this->provisioner()->provision($user);
    $this->assertNotSame($ordinary->id(), $personal->id());
    $this->assertSame('1', $personal->get('personal')->getString());
    $this->assertCount(2, $this->ids('ab_workspace'));
  }

  /**
   * The database rejects a second personal workspace for the same owner.
   */
  public function testDatabaseRejectsSecondPersonalWorkspace(): void {
    $user = $this->createUserAccount('ada', 'ada@example.com');
    $this->provisioner()->provision($user);
    $duplicate = Workspace::create([
      'name' => 'Outro pessoal',
      'owner' => $user->id(),
      'timezone' => 'UTC',
      'personal' => TRUE,
    ]);
    try {
      $duplicate->save();
      $this->fail('A second personal workspace was stored.');
    }
    catch (\Exception $exception) {
      $message = $exception->getMessage();
      $previous = $exception->getPrevious();
      if ($previous instanceof \Throwable) {
        $message .= ' ' . $previous->getMessage();
      }
      $this->assertStringContainsString('personal_owner', $message);
    }
    $this->assertCount(1, $this->ids('ab_workspace', 'personal', 1));
  }

  /**
   * A membership failure rolls the new workspace back.
   */
  public function testMembershipFailureRollsWorkspaceBack(): void {
    $user = $this->createUserAccount('ada', 'ada@example.com');
    $provisioner = new class($this->container->get('database'), $this->container->get('entity_type.manager')) extends PersonalWorkspaceProvisioner {

      /**
       * {@inheritdoc}
       */
      protected function attachOwnerMembership(Workspace $workspace, UserInterface $account): Membership {
        throw new \RuntimeException('membership failed');
      }

    };

    try {
      $provisioner->provision($user);
      $this->fail('The failed provisioning was stored.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('membership failed', $exception->getMessage());
    }
    $this->assertSame([], $this->ids('ab_workspace'));
    $this->assertSame([], $this->ids('ab_membership'));
  }

  /**
   * The anonymous user cannot receive a personal workspace.
   */
  public function testAnonymousUserIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->provisioner()->provision(User::getAnonymousUser());
  }

  /**
   * The update hook is idempotent once the field and index exist.
   */
  public function testSchemaInstallIsIdempotent(): void {
    $this->assertSame('Personal workspace schema already present.', PersonalWorkspaceSchema::install());
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
   * Loads the provisioner from the container.
   */
  private function provisioner(): PersonalWorkspaceProvisionerInterface {
    $provisioner = $this->container->get(PersonalWorkspaceProvisionerInterface::class);
    $this->assertInstanceOf(PersonalWorkspaceProvisionerInterface::class, $provisioner);
    return $provisioner;
  }

  /**
   * Returns the single stored membership.
   */
  private function onlyMembership(): Membership {
    $ids = $this->ids('ab_membership');
    $this->assertCount(1, $ids);
    $membership = Membership::load(reset($ids));
    $this->assertInstanceOf(Membership::class, $membership);
    return $membership;
  }

  /**
   * Returns entity ids, optionally filtered by one field.
   *
   * @return array<int|string>
   *   Entity ids.
   */
  private function ids(string $entityTypeId, ?string $field = NULL, mixed $value = NULL): array {
    $query = $this->container->get('entity_type.manager')->getStorage($entityTypeId)->getQuery()
      ->accessCheck(FALSE);
    if ($field !== NULL) {
      $query->condition($field, $value);
    }
    return $query->execute();
  }

}
