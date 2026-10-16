<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Kernel;

use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\ab_core\WorkspaceRepository;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Verifies revocation drops cached access and the selected workspace.
 *
 * @group ab_ui
 */
class MembershipRevocationCacheKernelTest extends KernelTestBase {

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
    'ab_agent',
    'ab_communication',
    'ab_ui',
  ];

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
   * A render-cache entry for the member is not served after revocation.
   */
  public function testCachedListingIsNotReusedAfterRevocation(): void {
    [$ada, $grace, $workspace] = $this->workspaceWithMember();
    $this->setCurrentUser($grace);
    $tag = 'ab_membership_list:' . $grace->id();
    $keys = ['ab_membership_revocation', (string) $grace->id()];
    $renderer = $this->renderer();

    $build = [
      '#markup' => 'Casa Ada',
      '#cache' => [
        'keys' => $keys,
        'tags' => [$tag],
      ],
    ];
    $renderer->renderRoot($build);

    $cached = [
      '#markup' => 'VAZIO',
      '#cache' => [
        'keys' => $keys,
        'tags' => [$tag],
      ],
    ];
    $this->assertSame('Casa Ada', (string) $renderer->renderRoot($cached));

    $this->setCurrentUser($ada);
    $this->manager()->remove($workspace, $this->membershipUuid($workspace, $grace));
    $this->nextRequest();

    $this->setCurrentUser($grace);
    $fresh = [
      '#markup' => 'VAZIO',
      '#cache' => [
        'keys' => $keys,
        'tags' => [$tag],
      ],
    ];
    $this->assertSame('VAZIO', (string) $renderer->renderRoot($fresh));
    $this->assertSame([], $this->repository()->accessibleWorkspaces());
    $loaded = $this->repository()->loadAccessibleByUuid($workspace->uuid());
    $this->assertNull($loaded);
    $this->assertFalse($workspace->access('view', $grace));
  }

  /**
   * The next read clears a session that still names the revoked workspace.
   */
  public function testSelectedWorkspaceLeavesTheSession(): void {
    [$ada, $grace, $workspace] = $this->workspaceWithMember();
    $session = $this->openRequest();
    $this->setCurrentUser($grace);
    $this->context()->select($workspace->uuid());
    $this->assertSame($workspace->uuid(), $session->get('ab_workspace'));

    $this->setCurrentUser($ada);
    $this->manager()->remove($workspace, $this->membershipUuid($workspace, $grace));
    $this->nextRequest();

    $this->setCurrentUser($grace);
    $this->assertNull($this->context()->workspace());
    $this->assertNull($session->get('ab_workspace'));
    $loaded = $this->repository()->loadAccessibleByUuid($workspace->uuid());
    $this->assertNull($loaded);
  }

  /**
   * Creates an owner and a member of one workspace.
   *
   * @return array{0: \Drupal\user\Entity\User, 1: \Drupal\user\Entity\User, 2: \Drupal\ab_core\Entity\Workspace}
   *   The owner, the member and the workspace.
   */
  private function workspaceWithMember(): array {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $grace = $this->createUserAccount('grace', 'grace@example.com');
    $this->setCurrentUser($ada);
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->manager()->addMember($workspace, 'grace', 'member');
    return [$ada, $grace, $workspace];
  }

  /**
   * Drops in-request caches while keeping the render cache bin.
   */
  private function nextRequest(): void {
    $manager = $this->container->get('entity_type.manager');
    $manager->getStorage('ab_membership')->resetCache();
    $manager->getStorage('ab_workspace')->resetCache();
    $manager->getAccessControlHandler('ab_workspace')->resetCache();
    $manager->getAccessControlHandler('ab_membership')->resetCache();
  }

  /**
   * Returns the membership UUID of a user in a workspace.
   */
  private function membershipUuid(Workspace $workspace, User $account): string {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_membership');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('user', $account->id())
      ->execute();
    $this->assertCount(1, $ids);
    $membership = $storage->load((string) reset($ids));
    $this->assertInstanceOf(Membership::class, $membership);
    return $membership->uuid();
  }

  /**
   * Pushes a request with an empty session.
   */
  private function openRequest(): Session {
    $session = new Session(new MockArraySessionStorage());
    $session->start();
    $request = Request::create('/workspaces');
    $request->setSession($session);
    $this->container->get('request_stack')->push($request);
    return $session;
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

  /**
   * Loads the workspace repository.
   */
  private function repository(): WorkspaceRepository {
    $repository = $this->container->get(WorkspaceRepository::class);
    $this->assertInstanceOf(WorkspaceRepository::class, $repository);
    return $repository;
  }

  /**
   * Loads the workspace context.
   */
  private function context(): WorkspaceContext {
    $context = $this->container->get(WorkspaceContext::class);
    $this->assertInstanceOf(WorkspaceContext::class, $context);
    return $context;
  }

  /**
   * Loads the renderer.
   */
  private function renderer(): RendererInterface {
    $renderer = $this->container->get('renderer');
    $this->assertInstanceOf(RendererInterface::class, $renderer);
    return $renderer;
  }

}
