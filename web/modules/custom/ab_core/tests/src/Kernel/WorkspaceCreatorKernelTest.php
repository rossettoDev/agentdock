<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_core\Kernel;

use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\ab_core\WorkspaceRepository;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies ordinary workspace creation.
 *
 * @group ab_core
 */
class WorkspaceCreatorKernelTest extends KernelTestBase {

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
   * The owner membership is stored with the workspace.
   */
  public function testCreateStoresOwnerMembership(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $grace = $this->createUserAccount('grace', 'grace@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');

    $this->assertInstanceOf(Workspace::class, $workspace);
    $this->assertSame('Casa Ada', $workspace->label());
    $this->assertSame((string) $ada->id(), $workspace->get('owner')->getString());
    $this->assertFalse((bool) $workspace->get('personal')->value);
    $this->assertSame('owner', $this->onlyMembership($workspace)->get('role')->value);

    $this->setCurrentUser($ada);
    $this->assertSame(1, $this->repository()->countAccessibleWorkspaces());
    $this->setCurrentUser($grace);
    $this->assertSame(0, $this->repository()->countAccessibleWorkspaces());
  }

  /**
   * A rejected workspace does not leave a row behind.
   */
  public function testInvalidTimezoneIsNotStored(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    try {
      $this->creator()->create($ada, 'Casa Ada', 'Nao/Existe');
      $this->fail('An unknown timezone was stored.');
    }
    catch (EntityStorageException) {
      $storage = $this->container->get('entity_type.manager')->getStorage('ab_workspace');
      $count = $storage->getQuery()->accessCheck(FALSE)->count()->execute();
      $this->assertSame(0, (int) $count);
    }
  }

  /**
   * The anonymous user cannot own a workspace.
   */
  public function testAnonymousIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->creator()->create(User::getAnonymousUser(), 'Casa', 'America/Sao_Paulo');
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
   * Loads the only membership of a workspace.
   */
  private function onlyMembership(Workspace $workspace): Membership {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_membership');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->execute();
    $this->assertCount(1, $ids);
    $membership = $storage->load((string) reset($ids));
    $this->assertInstanceOf(Membership::class, $membership);
    return $membership;
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
   * Loads the workspace repository.
   */
  private function repository(): WorkspaceRepository {
    $repository = $this->container->get(WorkspaceRepository::class);
    $this->assertInstanceOf(WorkspaceRepository::class, $repository);
    return $repository;
  }

}
