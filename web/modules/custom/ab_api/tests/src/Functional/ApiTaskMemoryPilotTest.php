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
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\ab_memory\MemoryService;
use Drupal\Core\Site\Settings;
use Drupal\Tests\BrowserTestBase;
use Symfony\Component\BrowserKit\Response;

/**
 * Proves task-related memory for the A → human → B pilot without claim.
 *
 * @group ab_api
 */
class ApiTaskMemoryPilotTest extends BrowserTestBase {

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
   * Agent A drafts, human publishes, agent B reads task context without claim.
   */
  public function testRelatedMemoriesOnTaskWithoutClaim(): void {
    $owner = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($owner);
    $workspace = $this->container->get(WorkspaceCreator::class)->create($owner, 'Casa piloto 36', 'America/Sao_Paulo');
    $board = $this->container->get(BoardManager::class)->create($workspace, 'Quadro piloto');
    $tasks = $this->container->get(TaskManager::class);
    $task = $tasks->create($board, [
      'title' => 'Configurar heartbeat do lease',
      'description' => 'Documentar intervalo seguro de heartbeat.',
    ]);
    $task = $tasks->transition($task, 'ready', 1);

    $agentA = $this->container->get(AgentManager::class)->create($workspace, 'Piloto A', '', '', TRUE, 1);
    $agentB = $this->container->get(AgentManager::class)->create($workspace, 'Piloto B', '', '', TRUE, 1);
    $grants = $this->container->get(AgentGrantManager::class);
    $grants->grant($agentA, $board, ['read', 'write']);
    $grants->grant($agentB, $board, ['read', 'write']);

    $writeA = $this->container->get(AgentTokenManager::class)->issue($agentA, [
      AgentTokenScope::MEMORY_READ,
      AgentTokenScope::MEMORY_WRITE,
      AgentTokenScope::TASKS_READ,
    ]);
    $readB = $this->container->get(AgentTokenManager::class)->issue($agentB, [
      AgentTokenScope::MEMORY_READ,
      AgentTokenScope::TASKS_READ,
    ]);
    $tasksOnlyB = $this->container->get(AgentTokenManager::class)->issue($agentB, [
      AgentTokenScope::TASKS_READ,
    ]);

    $prefix = '/api/agent-board/v1';
    $ws = (string) $workspace->uuid();
    $taskId = (string) $task->uuid();
    $boardId = (string) $board->uuid();
    $headersA = [
      'HTTP_AUTHORIZATION' => 'Bearer ' . $writeA->token,
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IDEMPOTENCY_KEY' => '11111111-1111-4111-8111-111111111136',
    ];

    $created = $this->json('POST', $prefix . '/workspaces/' . $ws . '/memory', $headersA, json_encode([
      'title' => 'Lição heartbeat do piloto A',
      'content_markdown' => 'Heartbeat a cada 30s no lease do AgentDock.',
      'kind' => 'lesson',
      'board_id' => $boardId,
      'task_id' => $taskId,
      'source_type' => 'task',
      'source_id' => $taskId,
    ], JSON_THROW_ON_ERROR));
    $this->assertSame(201, $created['status']);
    $memoryId = $created['body']['data']['id'];
    $version = (int) $created['body']['data']['version'];

    $this->container->get('current_user')->setAccount($owner);
    $human = $this->container->get(HumanActorContextFactory::class)->create($workspace);
    $published = $this->container->get(MemoryService::class)->publish($human, $memoryId, $version);
    $this->assertSame('published', $published->status);
    $this->assertSame($taskId, $published->taskUuid);
    $this->assertSame('task', $published->sourceType);
    $this->assertSame($taskId, $published->sourceId);

    $before = $this->executionCount($taskId);
    $this->assertSame(0, $before);

    $narrow = $this->json('GET', $prefix . '/workspaces/' . $ws . '/tasks/' . $taskId, [
      'HTTP_AUTHORIZATION' => 'Bearer ' . $tasksOnlyB->token,
    ]);
    $this->assertSame(200, $narrow['status']);
    $this->assertArrayNotHasKey('related_memories', $narrow['body']['data']);
    $this->assertSame(0, $this->executionCount($taskId));

    $enriched = $this->json('GET', $prefix . '/workspaces/' . $ws . '/tasks/' . $taskId, [
      'HTTP_AUTHORIZATION' => 'Bearer ' . $readB->token,
    ]);
    $this->assertSame(200, $enriched['status'], json_encode($enriched['body']));
    $this->assertArrayHasKey('related_memories', $enriched['body']['data']);
    $hits = $enriched['body']['data']['related_memories'];
    $this->assertNotEmpty($hits);
    $ids = array_column($hits, 'memory_id');
    $this->assertContains($memoryId, $ids);
    $match = NULL;
    foreach ($hits as $hit) {
      if ($hit['memory_id'] === $memoryId) {
        $match = $hit;
        break;
      }
    }
    $this->assertIsArray($match);
    $this->assertSame($published->revisionVersion, $match['revision']);
    $this->assertSame('task', $match['source']['source_type']);
    $this->assertSame($taskId, $match['source']['source_id']);
    $this->assertSame(0, $this->executionCount($taskId));

    $mcp = $this->talk($readB->token, $ws, [
      $this->request(1, 'initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => new \stdClass(),
        'clientInfo' => ['name' => 'phpunit-b36', 'version' => '0'],
      ]),
      $this->request(2, 'tools/call', [
        'name' => 'get_task',
        'arguments' => ['task_id' => $taskId],
      ]),
      $this->request(3, 'tools/call', [
        'name' => 'get_memory',
        'arguments' => ['memory_id' => $memoryId],
      ]),
    ]);
    $this->assertSame(0, $mcp['exit'], $mcp['stderr']);
    $this->assertStringNotContainsString($readB->token, $mcp['stderr']);
    $replies = $this->replies($mcp['stdout']);
    $this->assertFalse($replies[2]['result']['isError']);
    $taskDoc = $replies[2]['result']['structuredContent']['data'];
    $this->assertContains($memoryId, array_column($taskDoc['related_memories'] ?? [], 'memory_id'));
    $this->assertFalse($replies[3]['result']['isError']);
    $memoryDoc = $replies[3]['result']['structuredContent']['data'];
    $this->assertSame($published->revisionVersion, $memoryDoc['revision']['version']);
    $this->assertSame('task', $memoryDoc['source']['source_type']);
    $this->assertSame($taskId, $memoryDoc['source']['source_id']);
    $this->assertSame(0, $this->executionCount($taskId));
  }

  /**
   * Counts executions for one task UUID.
   */
  private function executionCount(string $taskUuid): int {
    return (int) $this->container->get('database')->query(
      'SELECT COUNT(*) FROM {ab_execution} e INNER JOIN {ab_task} t ON t.id = e.task WHERE t.uuid = :uuid',
      [':uuid' => $taskUuid],
    )->fetchField();
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
   *   Agent credential.
   * @param string $workspace
   *   Workspace UUID.
   * @param list<array<string, mixed>> $messages
   *   JSON-RPC messages.
   *
   * @return array{exit: int, stdout: string, stderr: string}
   *   Process result.
   */
  private function talk(string $token, string $workspace, array $messages): array {
    $body = '';
    foreach ($messages as $message) {
      $encoded = json_encode($message);
      $this->assertIsString($encoded);
      $body .= $encoded . "\n";
    }
    $script = dirname(DRUPAL_ROOT) . '/tools/mcp-adapter/adapter.php';
    $line = escapeshellarg(PHP_BINARY) . ' -d memory_limit=-1 ' . escapeshellarg($script);
    $pipes = [];
    $process = proc_open($line, [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ], $pipes, NULL, [
      'PATH' => (string) getenv('PATH'),
      'AGENT_BOARD_URL' => $this->baseUrl,
      'AGENT_BOARD_TOKEN' => $token,
      'AGENT_BOARD_WORKSPACE' => $workspace,
      'AGENT_BOARD_TIMEOUT' => '10',
      'AGENT_BOARD_COOKIE' => $this->cookie(),
    ]);
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
      'HTTP_X_REQUEST_ID' => '11111111-1111-4111-8111-111111111136',
    ];
    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(BrowserKitDriver::class, $driver);
    $client = $driver->getClient();
    $client->request($method, $this->buildUrl($path), [], [], $server, $body);
    $response = $client->getResponse();
    $this->assertInstanceOf(Response::class, $response);
    return $response;
  }

}
