<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_api\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\TaskManager;
use Drupal\ab_communication\MessageService;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Core\Site\Settings;
use Drupal\Tests\BrowserTestBase;
use Symfony\Component\BrowserKit\Response;

/**
 * Proves the shared minute budget returns 429.
 *
 * @group ab_api
 */
class ApiRateTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ab_api'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->configure([
      'ab_api_rate_limit' => 10,
      'ab_api_message_rate_limit' => 1,
    ]);
  }

  /**
   * A second message waits, and the same window blocks later calls.
   */
  public function testSharedWindowReturnsRetryAfter(): void {
    $owner = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($owner);
    $workspace = $this->container->get(WorkspaceCreator::class)->create($owner, 'Casa limite', 'America/Sao_Paulo');
    $board = $this->container->get(BoardManager::class)->create($workspace, 'Quadro limite');
    $task = $this->container->get(TaskManager::class)->create($board, ['title' => 'Fala limitada']);
    $actor = $this->container->get(HumanActorContextFactory::class)->create($workspace);
    $opened = $this->container->get(MessageService::class)->post($actor, 'task', (string) $task->uuid(), 'comment', 'Abre a conversa');
    $this->drupalLogin($owner);
    $token = trim((string) $this->call('GET', '/session/token')->getContent());
    $path = '/api/agent-board/v1/workspaces/' . $workspace->uuid() . '/threads/' . $opened->threadUuid . '/messages';
    $headers = [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_CSRF_TOKEN' => $token,
    ];

    $first = $this->call('POST', $path, $headers + [
      'HTTP_IDEMPOTENCY_KEY' => '11111111-1111-4111-8111-111111111111',
    ], '{"kind":"comment","body":"Primeira"}');
    $this->assertSame(201, $first->getStatusCode());
    $second = $this->call('POST', $path, $headers + [
      'HTTP_IDEMPOTENCY_KEY' => '22222222-2222-4222-8222-222222222222',
    ], '{"kind":"comment","body":"Segunda"}');
    $this->assertProblem($second, 429, 'rate_limited');

    $this->configure(['ab_api_rate_limit' => 2]);
    $blocked = $this->call('GET', '/api/agent-board/v1/workspaces');
    $this->assertProblem($blocked, 429, 'rate_limited');
    $retry = (string) $blocked->getHeader('Retry-After');
    $this->assertMatchesRegularExpression('/^[1-9][0-9]*$/', $retry);
    $this->assertLessThanOrEqual(60, (int) $retry);
  }

  /**
   * Puts settings where this process and the next request can read them.
   *
   * @param array<string, int> $values
   *   Settings keys.
   */
  private function configure(array $values): void {
    $exported = [];
    foreach ($values as $key => $value) {
      $exported[$key] = (object) [
        'value' => $value,
        'required' => TRUE,
      ];
    }
    $this->writeSettings(['settings' => $exported]);
    $current = Settings::getAll();
    foreach ($values as $key => $value) {
      $current[$key] = $value;
    }
    new Settings($current);
  }

  /**
   * Sends one API request through the HTTP kernel.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   Path under the site.
   * @param array<string, string> $server
   *   Server headers.
   * @param string $body
   *   Raw request body.
   */
  private function call(string $method, string $path, array $server = [], string $body = ''): Response {
    $server += [
      'HTTP_X_REQUEST_ID' => '11111111-1111-4111-8111-111111111111',
    ];
    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(BrowserKitDriver::class, $driver);
    $client = $driver->getClient();
    $client->request($method, $this->buildUrl($path), [], [], $server, $body);
    $response = $client->getResponse();
    $this->assertInstanceOf(Response::class, $response);
    return $response;
  }

  /**
   * Asserts a problem response.
   */
  private function assertProblem(Response $response, int $status, string $code): void {
    $this->assertSame($status, $response->getStatusCode());
    $this->assertStringContainsString('application/problem+json', (string) $response->getHeader('Content-Type'));
    $decoded = json_decode((string) $response->getContent(), TRUE);
    $this->assertIsArray($decoded);
    $this->assertSame($code, $decoded['code']);
  }

}
