<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Kernel;

use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Verifies that a URL workspace id does not select a workspace.
 *
 * @group ab_ui
 */
class WorkspaceContextKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'options',
    'ab_core',
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
   * A query parameter does not replace the selected workspace.
   */
  public function testQueryParameterDoesNotSelectWorkspace(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $grace = $this->createUserAccount('grace', 'grace@example.com');
    $ada_workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $grace_workspace = $this->creator()->create($grace, 'Casa Grace', 'America/Sao_Paulo');
    $session = $this->openRequest($grace_workspace->uuid());
    $this->setCurrentUser($ada);

    $selected = $this->context()->select($ada_workspace->uuid());
    $this->assertSame($ada_workspace->uuid(), $selected->uuid());
    $this->assertSame($ada_workspace->uuid(), $this->context()->workspace()?->uuid());
    $this->assertSame($grace_workspace->uuid(), $this->container->get('request_stack')->getCurrentRequest()->query->get('workspace'));

    try {
      $this->context()->select($grace_workspace->uuid());
      $this->fail('A foreign workspace UUID was selected.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertSame($ada_workspace->uuid(), $session->get('ab_workspace'));
    }

    $session->set('ab_workspace', $grace_workspace->uuid());
    $this->assertNull($this->context()->workspace());
    $this->assertNull($session->get('ab_workspace'));
  }

  /**
   * Pushes a request whose query names another workspace.
   */
  private function openRequest(string $foreign_uuid): Session {
    $session = new Session(new MockArraySessionStorage());
    $session->start();
    $request = Request::create('/workspaces', 'GET', [
      'workspace' => $foreign_uuid,
    ]);
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
   * Loads the workspace context.
   */
  private function context(): WorkspaceContext {
    $context = $this->container->get(WorkspaceContext::class);
    $this->assertInstanceOf(WorkspaceContext::class, $context);
    return $context;
  }

}
