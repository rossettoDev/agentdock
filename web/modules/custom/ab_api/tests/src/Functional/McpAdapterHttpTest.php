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

/**
 * Proves an MCP process uses HTTP and does not keep the execution alive.
 *
 * @group ab_api
 */
class McpAdapterHttpTest extends BrowserTestBase {

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
   * A claim survives process exit until an explicit release.
   */
  public function testIsolatedCallLeavesTheExecutionRunning(): void {
    $bare = $this->spawn([], '');
    $this->assertSame(1, $bare['exit'], $bare['stderr']);
    $this->assertStringContainsString('Missing AGENT_BOARD_URL', $bare['stderr']);

    $owner = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($owner);
    $workspace = $this->container->get(WorkspaceCreator::class)->create($owner, 'Casa mcp', 'America/Sao_Paulo');
    $board = $this->container->get(BoardManager::class)->create($workspace, 'Quadro mcp');
    $tasks = $this->container->get(TaskManager::class);
    $task = $tasks->create($board, ['title' => 'Entrega do adaptador']);
    $task = $tasks->transition($task, 'ready', 1);
    $agent = $this->container->get(AgentManager::class)->create($workspace, 'Executor mcp', '', '', TRUE, 1);
    $this->container->get(AgentGrantManager::class)->grant($agent, $board, ['read', 'write']);
    $issued = $this->container->get(AgentTokenManager::class)->issue($agent, [
      AgentTokenScope::BOARDS_READ,
      AgentTokenScope::TASKS_READ,
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::EXECUTIONS_WRITE,
    ]);
    $workspaceId = (string) $workspace->uuid();
    $taskId = (string) $task->uuid();
    $key = '55555555-5555-4555-8555-555555555555';

    $opened = $this->talk($issued->token, $workspaceId, [
      $this->request(1, 'initialize', [
        'protocolVersion' => '2024-11-05',
        'capabilities' => new \stdClass(),
        'clientInfo' => ['name' => 'phpunit', 'version' => '0'],
      ]),
      ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
      ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => [
        'requestId' => 1,
      ]],
      $this->request(2, 'tools/list', new \stdClass()),
      $this->request(3, 'tools/call', [
        'name' => 'claim_task',
        'arguments' => [
          'task_id' => $taskId,
          'idempotency_key' => $key,
          'agent_id' => '99999999-9999-4999-8999-999999999999',
        ],
      ]),
      $this->request(4, 'tools/call', [
        'name' => 'list_ready_tasks',
        'arguments' => ['board_id' => (string) $board->uuid()],
      ]),
      $this->request(5, 'tools/call', [
        'name' => 'claim_task',
        'arguments' => [
          'task_id' => $taskId,
          'idempotency_key' => $key,
        ],
      ]),
    ]);
    $this->assertSame(0, $opened['exit'], $opened['stderr']);
    $this->assertStringNotContainsString($issued->token, $opened['stderr']);
    $replies = $this->replies($opened['stdout']);
    $this->assertSame('2024-11-05', $replies[1]['result']['protocolVersion']);
    $this->assertCount(12, $replies[2]['result']['tools']);
    $this->assertSame(-32602, $replies[3]['error']['code']);
    $this->assertContains($taskId, array_column($replies[4]['result']['structuredContent']['data'], 'id'));
    $claim = $replies[5]['result']['structuredContent']['data'];
    $this->assertSame($taskId, $claim['task_id']);
    $execution = $claim['execution_id'];
    $lease = $claim['lease_token'];
    $this->assertIsString($execution);
    $this->assertIsString($lease);
    $this->assertStringNotContainsString($lease, $opened['stderr']);
    $this->assertSame(1, $this->executionCount($taskId));
    $this->assertSame('running', $this->executionStatus($execution));
    $this->assertSame('in_progress', $this->taskStatus($taskId));
    $this->assertSame((string) $agent->uuid(), $this->actor($taskId));

    $read = $this->talk($issued->token, $workspaceId, [
      $this->request(1, 'initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => new \stdClass(),
        'clientInfo' => ['name' => 'phpunit', 'version' => '0'],
      ]),
      $this->request(2, 'tools/call', [
        'name' => 'get_execution',
        'arguments' => ['execution_id' => $execution],
      ]),
    ]);
    $this->assertSame(0, $read['exit'], $read['stderr']);
    $seen = $this->replies($read['stdout']);
    $this->assertSame('running', $seen[2]['result']['structuredContent']['data']['status']);
    $this->assertStringNotContainsString('lease_token', $seen[2]['result']['content'][0]['text']);
    $this->assertSame('running', $this->executionStatus($execution));

    $released = $this->talk($issued->token, $workspaceId, [
      $this->request(1, 'initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => new \stdClass(),
        'clientInfo' => ['name' => 'phpunit', 'version' => '0'],
      ]),
      $this->request(2, 'tools/call', [
        'name' => 'release_task',
        'arguments' => [
          'execution_id' => $execution,
          'lease_token' => $lease,
          'idempotency_key' => '66666666-6666-4666-8666-666666666666',
        ],
      ]),
    ]);
    $this->assertSame(0, $released['exit'], $released['stderr']);
    $this->assertStringNotContainsString($lease, $released['stderr']);
    $done = $this->replies($released['stdout']);
    $this->assertFalse($done[2]['result']['isError']);
    $this->assertSame('interrupted', $this->executionStatus($execution));
    $this->assertSame('ready', $this->taskStatus($taskId));
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
   * Speaks one stdio session and closes it.
   *
   * @param string $token
   *   Agent credential. It is not written to stderr.
   * @param string $workspace
   *   Workspace UUID.
   * @param list<array<string, mixed>> $messages
   *   JSON-RPC messages.
   *
   * @return array{exit: int, stdout: string, stderr: string}
   *   Process result. The lease is not part of stderr.
   */
  private function talk(string $token, string $workspace, array $messages): array {
    $body = '';
    foreach ($messages as $message) {
      $encoded = json_encode($message);
      $this->assertIsString($encoded);
      $body .= $encoded . "\n";
    }
    return $this->spawn([
      'PATH' => (string) getenv('PATH'),
      'AGENT_BOARD_URL' => $this->baseUrl,
      'AGENT_BOARD_TOKEN' => $token,
      'AGENT_BOARD_WORKSPACE' => $workspace,
      'AGENT_BOARD_TIMEOUT' => '10',
      'AGENT_BOARD_COOKIE' => $this->cookie(),
    ], $body);
  }

  /**
   * Reads the test-site cookie the subprocess must send.
   */
  private function cookie(): string {
    $this->prepareRequest();
    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(BrowserKitDriver::class, $driver);
    foreach ($driver->getClient()->getCookieJar()->all() as $stored) {
      if ($stored->getName() === 'SIMPLETEST_USER_AGENT') {
        return $stored->getValue();
      }
    }
    $this->fail('The test cookie was not set.');
    return '';
  }

  /**
   * Starts the adapter and feeds it one session.
   *
   * @param array<string, string> $env
   *   Environment passed to the process.
   * @param string $body
   *   Stdin payload.
   *
   * @return array{exit: int, stdout: string, stderr: string}
   *   Process result.
   */
  private function spawn(array $env, string $body): array {
    $script = dirname(DRUPAL_ROOT) . '/tools/mcp-adapter/adapter.php';
    $line = escapeshellarg(PHP_BINARY) . ' -d memory_limit=-1 ' . escapeshellarg($script);
    $pipes = [];
    $process = proc_open($line, [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ], $pipes, NULL, $env);
    $this->assertTrue(is_resource($process));
    fwrite($pipes[0], $body);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
      'exit' => proc_close($process),
      'stdout' => is_string($stdout) ? $stdout : '',
      'stderr' => is_string($stderr) ? $stderr : '',
    ];
  }

  /**
   * Decodes JSON-RPC responses keyed by id.
   *
   * @return array<int|string, array<string, mixed>>
   *   Responses.
   */
  private function replies(string $stdout): array {
    $decoded = [];
    foreach (explode("\n", trim($stdout)) as $line) {
      if ($line === '' || !str_starts_with($line, '{')) {
        continue;
      }
      $row = json_decode($line, TRUE);
      $this->assertIsArray($row, 'A response line was not JSON.');
      $decoded[$row['id']] = $row;
    }
    return $decoded;
  }

  /**
   * Builds one JSON-RPC request.
   *
   * @param int $id
   *   Request id.
   * @param string $method
   *   JSON-RPC method.
   * @param array<string, mixed>|\stdClass $params
   *   Request parameters.
   *
   * @return array<string, mixed>
   *   JSON-RPC request.
   */
  private function request(int $id, string $method, array|\stdClass $params): array {
    return [
      'jsonrpc' => '2.0',
      'id' => $id,
      'method' => $method,
      'params' => $params,
    ];
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
   * Reads the stored status of an execution.
   */
  private function executionStatus(string $executionUuid): string {
    $status = $this->container->get('database')->query(
      'SELECT status FROM {ab_execution} WHERE uuid = :uuid',
      [':uuid' => $executionUuid],
    )->fetchField();
    return is_string($status) ? $status : '';
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

  /**
   * Reads the agent that owns the execution of one task.
   */
  private function actor(string $taskUuid): string {
    $agent = $this->container->get('database')->query(
      'SELECT a.uuid FROM {ab_execution} e INNER JOIN {ab_task} t ON t.id = e.task INNER JOIN {ab_agent} a ON a.id = e.agent WHERE t.uuid = :uuid',
      [':uuid' => $taskUuid],
    )->fetchField();
    return is_string($agent) ? $agent : '';
  }

}
