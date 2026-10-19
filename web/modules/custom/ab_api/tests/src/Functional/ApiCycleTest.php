<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_api\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\TaskManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Core\Site\Settings;
use Drupal\Tests\BrowserTestBase;
use Symfony\Component\BrowserKit\Response;

/**
 * Proves the runner cycle stays on the existing services.
 *
 * @group ab_api
 */
class ApiCycleTest extends BrowserTestBase {

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
      'ab_api_idempotency_key' => base64_encode(str_repeat('k', 32)),
    ]);
  }

  /**
   * A repeated claim does not open a second execution, and review stays human.
   */
  public function testReplayAndHumanReview(): void {
    $owner = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($owner);
    $workspace = $this->container->get(WorkspaceCreator::class)->create($owner, 'Casa ciclo', 'America/Sao_Paulo');
    $board = $this->container->get(BoardManager::class)->create($workspace, 'Quadro ciclo');
    $tasks = $this->container->get(TaskManager::class);
    $first = $tasks->create($board, ['title' => 'Entrega do ciclo']);
    $second = $tasks->create($board, ['title' => 'Liberar o ciclo']);
    $tasks->transition($first, 'ready', 1);
    $tasks->transition($second, 'ready', 1);
    $agent = $this->container->get(AgentManager::class)->create($workspace, 'Executor', '', '', TRUE, 1);
    $this->container->get(AgentGrantManager::class)->grant($agent, $board, ['read', 'write']);
    $issued = $this->container->get(AgentTokenManager::class)->issue($agent, [
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::TASKS_READ,
      AgentTokenScope::EXECUTIONS_WRITE,
      AgentTokenScope::MESSAGES_READ,
      AgentTokenScope::MESSAGES_WRITE,
    ]);
    $bearer = ['HTTP_AUTHORIZATION' => 'Bearer ' . $issued->token];
    $prefix = '/api/agent-board/v1/workspaces/' . $workspace->uuid();
    $json = ['CONTENT_TYPE' => 'application/json'];

    $claimed = $this->json('POST', $prefix . '/tasks/' . $first->uuid() . '/claim', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => '11111111-1111-4111-8111-111111111111',
    ], '{}');
    $this->assertSame(201, $claimed['status']);
    $execution = $claimed['body']['data']['execution_id'];
    $lease = $claimed['body']['data']['lease_token'];
    $this->assertIsString($execution);
    $this->assertIsString($lease);
    $this->assertSame(1, $this->executionCount((string) $first->uuid()));
    $stored = (string) $this->container->get('database')->query(
      "SELECT response_body FROM {ab_idempotency} WHERE operation = 'task.claim' AND resource_id = :task",
      [':task' => (string) $first->uuid()],
    )->fetchField();
    $this->assertStringNotContainsString($lease, $stored);

    $replay = $this->json('POST', $prefix . '/tasks/' . $first->uuid() . '/claim', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => '11111111-1111-4111-8111-111111111111',
    ], '{}');
    $this->assertSame(201, $replay['status']);
    $this->assertSame($execution, $replay['body']['data']['execution_id']);
    $this->assertSame($lease, $replay['body']['data']['lease_token']);
    $this->assertSame(1, $this->executionCount((string) $first->uuid()));

    $conflict = $this->call('POST', $prefix . '/tasks/' . $first->uuid() . '/claim', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => '11111111-1111-4111-8111-111111111111',
    ], '{ }');
    $this->assertProblem($conflict, 409, 'idempotency_conflict');
    $this->assertSame(1, $this->executionCount((string) $first->uuid()));

    $badLease = 'ab_lease_' . str_repeat('ab', 16) . '.' . str_repeat('cd', 32);
    $rejected = $this->call('POST', $prefix . '/executions/' . $execution . '/heartbeat', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => '22222222-2222-4222-8222-222222222222',
      'HTTP_X_EXECUTION_LEASE' => $badLease,
    ], '{}');
    $this->assertProblem($rejected, 409, 'conflict');
    $this->assertStringNotContainsString($badLease, (string) $rejected->getContent());

    $missing = $this->call('POST', $prefix . '/executions/' . $execution . '/heartbeat', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => '33333333-3333-4333-8333-333333333333',
    ], '{}');
    $this->assertProblem($missing, 428, 'precondition_required');

    $beat = $this->json('POST', $prefix . '/executions/' . $execution . '/heartbeat', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => '44444444-4444-4444-8444-444444444444',
      'HTTP_X_EXECUTION_LEASE' => $lease,
    ], '{}');
    $this->assertSame(200, $beat['status']);
    $this->assertArrayNotHasKey('lease_token', $beat['body']['data']);

    $note = $this->json('POST', $prefix . '/executions/' . $execution . '/progress', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => '55555555-5555-4555-8555-555555555555',
      'HTTP_X_EXECUTION_LEASE' => $lease,
    ], '{"note":"Andamento estavel"}');
    $this->assertSame(201, $note['status']);
    $this->assertSame('Andamento estavel', $note['body']['data']['body']);
    $again = $this->json('POST', $prefix . '/executions/' . $execution . '/progress', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => '55555555-5555-4555-8555-555555555555',
      'HTTP_X_EXECUTION_LEASE' => $lease,
    ], '{"note":"Andamento estavel"}');
    $this->assertSame($note['body'], $again['body']);
    $this->assertSame(1, $this->progressCount($execution));

    $question = $this->json('POST', $prefix . '/executions/' . $execution . '/request-input', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => '66666666-6666-4666-8666-666666666666',
      'HTTP_X_EXECUTION_LEASE' => $lease,
    ], '{"body":"Qual o prazo?"}');
    $this->assertSame(201, $question['status']);
    $this->assertSame('open', $question['body']['data']['status']);
    $requestId = $question['body']['data']['id'];

    $agentAnswer = $this->call('POST', $prefix . '/input-requests/' . $requestId . '/answer', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => '77777777-7777-4777-8777-777777777777',
    ], '{"body":"Agente responde"}');
    $this->assertProblem($agentAnswer, 403, 'forbidden');

    $agentReview = $this->call('POST', $prefix . '/tasks/' . $first->uuid() . '/approve', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => '88888888-8888-4888-8888-888888888888',
    ], '{"version":1}');
    $this->assertProblem($agentReview, 403, 'forbidden');

    $this->drupalLogin($owner);
    $token = trim((string) $this->call('GET', '/session/token')->getContent());
    $answer = $this->json('POST', $prefix . '/input-requests/' . $requestId . '/answer', $json + [
      'HTTP_X_CSRF_TOKEN' => $token,
      'HTTP_IDEMPOTENCY_KEY' => '99999999-9999-4999-8999-999999999999',
    ], '{"body":"O prazo e sexta"}');
    $this->assertSame(201, $answer['status']);
    $this->assertSame('answer', $answer['body']['data']['kind']);
    $thread = $answer['body']['data']['thread_id'];

    $submitted = $this->json('POST', $prefix . '/executions/' . $execution . '/submit', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      'HTTP_X_EXECUTION_LEASE' => $lease,
    ], '{"summary":"Entrega pronta"}');
    $this->assertSame(200, $submitted['status']);
    $this->assertSame('submitted', $submitted['body']['data']['status']);
    $this->assertArrayNotHasKey('lease_token', $submitted['body']['data']);
    $this->assertSame(0, $this->sealedClaims((string) $first->uuid()));

    $detail = $this->json('GET', $prefix . '/executions/' . $execution, $bearer);
    $this->assertSame(200, $detail['status']);
    $this->assertArrayNotHasKey('lease_token', $detail['body']['data']);
    $this->assertStringNotContainsString($lease, (string) json_encode($detail['body']));

    $task = $this->json('GET', $prefix . '/tasks/' . $first->uuid());
    $version = $task['body']['data']['version'];
    $stale = $this->call('POST', $prefix . '/tasks/' . $first->uuid() . '/reject', $json + [
      'HTTP_X_CSRF_TOKEN' => $token,
      'HTTP_IDEMPOTENCY_KEY' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    ], '{"version":1,"reason":"Cedo demais"}');
    $this->assertProblem($stale, 412, 'stale_version');

    $approved = $this->json('POST', $prefix . '/tasks/' . $first->uuid() . '/approve', $json + [
      'HTTP_X_CSRF_TOKEN' => $token,
      'HTTP_IDEMPOTENCY_KEY' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    ], '{"version":' . $version . '}');
    $this->assertSame(200, $approved['status']);
    $this->assertSame('done', $approved['body']['data']['status']);

    $page = $this->json('GET', $prefix . '/threads/' . $thread . '/messages');
    $this->assertSame(200, $page['status']);
    $bodies = array_column($page['body']['data'], 'body');
    $this->assertContains('Qual o prazo?', $bodies);
    $this->assertContains('O prazo e sexta', $bodies);

    $comment = $this->json('POST', $prefix . '/threads/' . $thread . '/messages', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
    ], '{"kind":"comment","body":"Segue o material"}');
    $this->assertSame(201, $comment['status']);
    $humanAnswer = $this->call('POST', $prefix . '/threads/' . $thread . '/messages', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
    ], '{"kind":"answer","body":"Agente nao responde"}');
    $this->assertProblem($humanAnswer, 403, 'forbidden');

    $releasedClaim = $this->json('POST', $prefix . '/tasks/' . $second->uuid() . '/claim', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
    ], '{}');
    $this->assertSame(201, $releasedClaim['status']);
    $secondLease = $releasedClaim['body']['data']['lease_token'];
    $secondExecution = $releasedClaim['body']['data']['execution_id'];
    $released = $this->json('POST', $prefix . '/executions/' . $secondExecution . '/release', $bearer + $json + [
      'HTTP_IDEMPOTENCY_KEY' => '12121212-1212-4212-8212-121212121212',
      'HTTP_X_EXECUTION_LEASE' => $secondLease,
    ], '{}');
    $this->assertSame(200, $released['status']);
    $this->assertSame('interrupted', $released['body']['data']['status']);
    $this->assertSame(0, $this->sealedClaims((string) $second->uuid()));
    $this->assertSame('ready', $this->taskStatus((string) $second->uuid()));
  }

  /**
   * Puts settings where this process and the next request can read them.
   *
   * @param array<string, mixed> $values
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
   * Sends one request and decodes a JSON body.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   Path under the site.
   * @param array<string, string> $server
   *   Server headers.
   * @param string $body
   *   Raw request body.
   *
   * @return array{status: int, body: array<string, mixed>}
   *   Status and decoded body.
   */
  private function json(string $method, string $path, array $server = [], string $body = ''): array {
    $response = $this->call($method, $path, $server, $body);
    $decoded = json_decode((string) $response->getContent(), TRUE);
    $this->assertIsArray($decoded);
    return [
      'status' => $response->getStatusCode(),
      'body' => $decoded,
    ];
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

  /**
   * Counts executions of one task.
   */
  private function executionCount(string $taskUuid): int {
    return (int) $this->container->get('database')->query(
      'SELECT COUNT(*) FROM {ab_execution} e INNER JOIN {ab_task} t ON t.id = e.task WHERE t.uuid = :uuid',
      [':uuid' => $taskUuid],
    )->fetchField();
  }

  /**
   * Counts progress notes of one execution.
   */
  private function progressCount(string $executionUuid): int {
    return (int) $this->container->get('database')->query(
      'SELECT COUNT(*) FROM {ab_execution_progress} p INNER JOIN {ab_execution} e ON e.id = p.execution_id WHERE e.uuid = :uuid',
      [':uuid' => $executionUuid],
    )->fetchField();
  }

  /**
   * Counts sealed claim replays of one task.
   */
  private function sealedClaims(string $taskUuid): int {
    return (int) $this->container->get('database')->query(
      "SELECT COUNT(*) FROM {ab_idempotency} WHERE operation = 'task.claim' AND resource_id = :task AND sealed = 1",
      [':task' => $taskUuid],
    )->fetchField();
  }

  /**
   * Reads the stored status of a task.
   */
  private function taskStatus(string $taskUuid): string {
    $status = $this->container->get('database')->query(
      'SELECT status FROM {ab_task} WHERE uuid = :uuid',
      [':uuid' => $taskUuid],
    )->fetchField();
    return is_string($status) ? $status : '';
  }

}
