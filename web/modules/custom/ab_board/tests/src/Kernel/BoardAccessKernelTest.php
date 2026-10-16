<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_board\Kernel;

use Drupal\ab_board\BoardManager;
use Drupal\ab_board\BoardRepository;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies a user only reaches boards of a readable workspace.
 *
 * @group ab_board
 */
class BoardAccessKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'options',
    'ab_core',
    'ab_event',
    'ab_board',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('ab_workspace');
    $this->installEntitySchema('ab_membership');
    $this->installEntitySchema('ab_board');
    $this->installSchema('ab_event', [
      'ab_workspace_sequence',
      'ab_event',
      'ab_outbox',
    ]);
  }

  /**
   * Access checks and queries stay inside the caller's workspaces.
   */
  public function testBoardAccessFollowsWorkspaceMembership(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $grace = $this->createUserAccount('grace', 'grace@example.com');
    $viewer = $this->createUserAccount('view', 'view@example.com');
    $member = $this->createUserAccount('member', 'member@example.com');
    $admin = $this->createUserAccount('admin', 'admin@example.com');
    $ada_workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $grace_workspace = $this->creator()->create($grace, 'Casa Grace', 'America/Sao_Paulo');
    $this->membership($ada_workspace, $viewer, 'viewer');
    $this->membership($ada_workspace, $member, 'member');
    $this->membership($ada_workspace, $admin, 'admin');

    $this->setCurrentUser($ada);
    $ada_board = $this->manager()->create($ada_workspace, 'Quadro Ada', 'Texto Ada');
    $this->setCurrentUser($grace);
    $grace_board = $this->manager()->create($grace_workspace, 'Quadro Grace', 'Texto Grace');

    $this->setCurrentUser($ada);
    $ada_ids = $this->boardQueryIds();
    $this->setCurrentUser($grace);
    $grace_ids = $this->boardQueryIds();
    $this->assertSame([(string) $ada_board->id()], $ada_ids);
    $this->assertSame([(string) $grace_board->id()], $grace_ids);

    $this->assertTrue($ada_board->access('view', $ada));
    $this->assertTrue($ada_board->access('update', $ada));
    $this->assertTrue($ada_board->access('view', $admin));
    $this->assertTrue($ada_board->access('update', $admin));
    $this->assertTrue($ada_board->access('view', $viewer));
    $this->assertFalse($ada_board->access('update', $viewer));
    $this->assertTrue($ada_board->access('view', $member));
    $this->assertFalse($ada_board->access('update', $member));
    $this->assertFalse($ada_board->access('view', $grace));
    $this->assertFalse($ada_board->access('update', $grace));

    $this->setCurrentUser($grace);
    $this->assertNull($this->repository()->loadAccessibleByUuid($ada_board->uuid()));
    try {
      $this->repository()->accessibleBoards($ada_workspace);
      $this->fail('A foreign workspace listed its boards.');
    }
    catch (ScopedResourceNotFoundException) {
      $visible = $this->repository()->accessibleBoards($grace_workspace);
      $this->assertCount(1, $visible);
      $this->assertSame($grace_board->id(), $visible[0]->id());
    }
  }

  /**
   * Returns board ids from an access-checked query.
   *
   * @return string[]
   *   Board ids.
   */
  private function boardQueryIds(): array {
    $ids = $this->container->get('entity_type.manager')->getStorage('ab_board')->getQuery()
      ->accessCheck(TRUE)
      ->sort('id')
      ->execute();
    return array_map('strval', array_values($ids));
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
   * Adds an active membership.
   */
  private function membership(Workspace $workspace, User $account, string $role): void {
    $membership = Membership::create([
      'workspace' => $workspace->id(),
      'user' => $account->id(),
      'role' => $role,
      'status' => 'active',
    ]);
    $membership->save();
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
   * Loads the board manager.
   */
  private function manager(): BoardManager {
    $manager = $this->container->get(BoardManager::class);
    $this->assertInstanceOf(BoardManager::class, $manager);
    return $manager;
  }

  /**
   * Loads the board repository.
   */
  private function repository(): BoardRepository {
    $repository = $this->container->get(BoardRepository::class);
    $this->assertInstanceOf(BoardRepository::class, $repository);
    return $repository;
  }

  /**
   * Authenticates the account.
   */
  private function setCurrentUser(User $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

}
