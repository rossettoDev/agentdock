<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_api\Unit;

require_once dirname(__DIR__, 7) . '/tools/mcp-adapter/src/ApiClientInterface.php';
require_once dirname(__DIR__, 7) . '/tools/mcp-adapter/src/ProtocolException.php';
require_once dirname(__DIR__, 7) . '/tools/mcp-adapter/src/ToolCatalog.php';
require_once dirname(__DIR__, 7) . '/tools/mcp-adapter/src/ToolGateway.php';
require_once dirname(__DIR__, 7) . '/tools/mcp-adapter/src/StdioServer.php';

use Drupal\Tests\UnitTestCase;
use McpAdapter\ApiClientInterface;
use McpAdapter\ProtocolException;
use McpAdapter\StdioServer;
use McpAdapter\ToolCatalog;
use McpAdapter\ToolGateway;

/**
 * Proves each MCP tool is one API call and the session owns no lease.
 *
 * @group ab_api
 */
class McpAdapterTest extends UnitTestCase {

  /**
   * Workspace the adapter is configured to use.
   */
  private const WORKSPACE = '11111111-1111-4111-8111-111111111111';

  /**
   * The catalog names match the architecture and each call is one request.
   */
  public function testToolsMapOntoTheApi(): void {
    $names = array_column(ToolCatalog::tools(), 'name');
    $this->assertSame([
      'list_boards',
      'list_ready_tasks',
      'get_task',
      'claim_task',
      'report_progress',
      'post_message',
      'request_human_input',
      'get_execution',
      'submit_result',
      'fail_execution',
      'release_task',
      'get_agent_inbox',
    ], $names);

    $client = new RecordingClient();
    $gateway = new ToolGateway($client, self::WORKSPACE);
    $task = '33333333-3333-4333-8333-333333333333';
    $board = '22222222-2222-4222-8222-222222222222';
    $execution = '44444444-4444-4444-8444-444444444444';
    $key = '55555555-5555-4555-8555-555555555555';
    $thread = '66666666-6666-4666-8666-666666666666';
    $lease = 'ab_lease_' . str_repeat('ab', 16) . '.' . str_repeat('cd', 32);
    $prefix = '/workspaces/' . self::WORKSPACE;

    $gateway->call('list_boards', ['limit' => 50]);
    $gateway->call('list_ready_tasks', ['board_id' => $board]);
    $gateway->call('get_task', ['task_id' => $task]);
    $client->response = ['status' => 201, 'json' => ['data' => [
      'execution_id' => $execution,
      'lease_token' => $lease,
    ]]];
    $claimed = $gateway->call('claim_task', [
      'task_id' => $task,
      'idempotency_key' => $key,
    ]);
    $gateway->call('report_progress', [
      'execution_id' => $execution,
      'lease_token' => $lease,
      'idempotency_key' => $key,
      'note' => 'Andamento',
    ]);
    $gateway->call('post_message', [
      'thread_id' => $thread,
      'idempotency_key' => $key,
      'kind' => 'comment',
      'body' => 'Texto',
    ]);
    $gateway->call('request_human_input', [
      'execution_id' => $execution,
      'lease_token' => $lease,
      'idempotency_key' => $key,
      'body' => 'Pergunta',
    ]);
    $gateway->call('get_execution', ['execution_id' => $execution]);
    $gateway->call('submit_result', [
      'execution_id' => $execution,
      'lease_token' => $lease,
      'idempotency_key' => $key,
      'summary' => 'Entrega',
    ]);
    $gateway->call('fail_execution', [
      'execution_id' => $execution,
      'lease_token' => $lease,
      'idempotency_key' => $key,
      'code' => 'blocked',
    ]);
    $gateway->call('release_task', [
      'execution_id' => $execution,
      'lease_token' => $lease,
      'idempotency_key' => $key,
    ]);
    $gateway->call('get_agent_inbox', []);

    $paths = array_column($client->calls, 'path');
    $this->assertSame([
      $prefix . '/boards?limit=50',
      $prefix . '/boards/' . $board . '/tasks?status=ready',
      $prefix . '/tasks/' . $task,
      $prefix . '/tasks/' . $task . '/claim',
      $prefix . '/executions/' . $execution . '/progress',
      $prefix . '/threads/' . $thread . '/messages',
      $prefix . '/executions/' . $execution . '/request-input',
      $prefix . '/executions/' . $execution,
      $prefix . '/executions/' . $execution . '/submit',
      $prefix . '/executions/' . $execution . '/fail',
      $prefix . '/executions/' . $execution . '/release',
      $prefix . '/agent-inbox',
    ], $paths);
    $this->assertSame('{}', $client->calls[3]['body']);
    $this->assertSame($key, $client->calls[3]['headers']['Idempotency-Key']);
    $this->assertArrayNotHasKey('Authorization', $client->calls[3]['headers']);
    $this->assertSame($lease, $client->calls[4]['headers']['X-Execution-Lease']);
    $this->assertSame('{"note":"Andamento"}', $client->calls[4]['body']);
    $this->assertFalse($claimed['isError']);
    $this->assertSame($lease, $claimed['structuredContent']['data']['lease_token']);
    $names = [];
    foreach ((new \ReflectionClass(ToolGateway::class))->getProperties() as $property) {
      $names[] = $property->getName();
    }
    $this->assertSame(['client', 'workspace'], $names);

    $client->calls = [];
    $client->response = ['status' => 409, 'json' => [
      'code' => 'conflict',
      'detail' => 'The operation conflicts.',
      'lease_token' => $lease,
    ]];
    $rejected = $gateway->call('report_progress', [
      'execution_id' => $execution,
      'lease_token' => $lease,
      'idempotency_key' => $key,
      'note' => 'De novo',
    ]);
    $this->assertTrue($rejected['isError']);
    $this->assertSame(['status', 'code', 'detail'], array_keys($rejected['structuredContent']));
    $this->assertStringNotContainsString($lease, $rejected['content'][0]['text']);

    try {
      $gateway->call('claim_task', [
        'task_id' => $task,
        'idempotency_key' => $key,
        'agent_id' => $execution,
      ]);
      $this->fail('An agent id was accepted.');
    }
    catch (ProtocolException $exception) {
      $this->assertSame(-32602, $exception->rpcCode);
    }
    $this->assertCount(1, $client->calls);
  }

  /**
   * Closing stdin and a cancelled notification do not call the API.
   */
  public function testSessionEndDoesNotTouchAnExecution(): void {
    $client = new RecordingClient();
    $input = fopen('php://memory', 'r+');
    $output = fopen('php://memory', 'r+');
    $this->assertIsResource($input);
    $this->assertIsResource($output);
    $lease = 'ab_lease_' . str_repeat('ab', 16) . '.' . str_repeat('cd', 32);
    $lines = [
      $this->request(1, 'initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => new \stdClass(),
        'clientInfo' => ['name' => 'test', 'version' => '0'],
      ]),
      ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
      $this->request(2, 'tools/list', new \stdClass()),
      $this->request(3, 'tools/call', [
        'name' => 'list_boards',
        'arguments' => new \stdClass(),
      ]),
      [
        'jsonrpc' => '2.0',
        'method' => 'notifications/cancelled',
        'params' => ['requestId' => 3, 'reason' => $lease],
      ],
    ];
    fwrite($input, json_encode($lines[0]) . "\n");
    foreach (array_slice($lines, 1) as $line) {
      fwrite($input, json_encode($line) . "\n");
    }
    rewind($input);
    $server = new StdioServer(new ToolGateway($client, self::WORKSPACE), $input, $output);
    $this->assertSame(0, $server->serve());
    $this->assertCount(1, $client->calls);
    $this->assertSame('GET', $client->calls[0]['method']);
    rewind($output);
    $raw = stream_get_contents($output);
    $this->assertIsString($raw);
    $this->assertStringNotContainsString($lease, $raw);
    $decoded = [];
    foreach (explode("\n", trim($raw)) as $row) {
      $decoded[] = json_decode($row, TRUE);
    }
    $this->assertSame('2025-06-18', $decoded[0]['result']['protocolVersion']);
    $this->assertSame('list_boards', $decoded[1]['result']['tools'][0]['name']);
    $this->assertFalse($decoded[2]['result']['isError']);

    $throwing = new ThrowingClient('secret-token-value');
    $failedIn = fopen('php://memory', 'r+');
    $failedOut = fopen('php://memory', 'r+');
    $this->assertIsResource($failedIn);
    $this->assertIsResource($failedOut);
    fwrite($failedIn, json_encode($lines[0]) . "\n");
    fwrite($failedOut, '');
    $call = $this->request(4, 'tools/call', [
      'name' => 'get_task',
      'arguments' => ['task_id' => '33333333-3333-4333-8333-333333333333'],
    ]);
    fwrite($failedIn, json_encode($call) . "\n");
    rewind($failedIn);
    $again = new StdioServer(new ToolGateway($throwing, self::WORKSPACE), $failedIn, $failedOut);
    $this->assertSame(0, $again->serve());
    rewind($failedOut);
    $failedRaw = stream_get_contents($failedOut);
    $this->assertIsString($failedRaw);
    $this->assertStringNotContainsString('secret-token-value', $failedRaw);
    $this->assertStringContainsString('The HTTP call failed.', $failedRaw);
  }

  /**
   * The adapter source does not open storage or renew a lease.
   */
  public function testAdapterDoesNotOpenStorage(): void {
    $root = dirname(__DIR__, 7) . '/tools/mcp-adapter';
    $source = '';
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
      if ($file->getExtension() === 'php') {
        $source .= (string) file_get_contents($file->getPathname());
      }
    }
    foreach (['pgsql', 'PDO', 'mysqli', 'ab_task', 'ab_execution', '/approve', '/reject', '/heartbeat', 'Drupal\\'] as $needle) {
      $this->assertStringNotContainsString($needle, $source);
    }
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

}

/**
 * Records API calls and returns a fixed response.
 */
class RecordingClient implements ApiClientInterface {

  /**
   * Calls the gateway made.
   *
   * @var list<array{method: string, path: string, body: string|null, headers: array<string, string>}>
   */
  public array $calls = [];

  /**
   * Response returned for every call.
   *
   * @var array{status: int, json: array<string, mixed>|null}
   */
  public array $response = ['status' => 200, 'json' => ['data' => []]];

  /**
   * {@inheritdoc}
   */
  public function send(string $method, string $path, ?string $body, array $headers): array {
    $this->calls[] = [
      'method' => $method,
      'path' => $path,
      'body' => $body,
      'headers' => $headers,
    ];
    return $this->response;
  }

}

/**
 * Fails the HTTP call with a message that must not reach the client.
 */
class ThrowingClient implements ApiClientInterface {

  public function __construct(
    private readonly string $secret,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function send(string $method, string $path, ?string $body, array $headers): array {
    throw new \RuntimeException('transport ' . $this->secret);
  }

}
