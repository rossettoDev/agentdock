<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_api\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_board\BoardManager;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\ab_memory\MemoryService;
use Drupal\Core\Site\Settings;
use Drupal\Tests\BrowserTestBase;

/**
 * Proves two MCP agents share memory only through the HTTP API.
 *
 * @group ab_api
 */
class McpMemoryAdapterHttpTest extends BrowserTestBase {

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
   * Agent A drafts via MCP; after human publish, agent B recovers it.
   */
  public function testAgentDraftIsRecoveredByPeerAfterPublish(): void {
    $owner = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($owner);
    $workspace = $this->container->get(WorkspaceCreator::class)->create($owner, 'Casa memória MCP', 'America/Sao_Paulo');
    $board = $this->container->get(BoardManager::class)->create($workspace, 'Quadro memória');
    $agentA = $this->container->get(AgentManager::class)->create($workspace, 'Agente A', '', '', TRUE, 1);
    $agentB = $this->container->get(AgentManager::class)->create($workspace, 'Agente B', '', '', TRUE, 1);
    $grants = $this->container->get(AgentGrantManager::class);
    $grants->grant($agentA, $board, ['read', 'write']);
    $grants->grant($agentB, $board, ['read', 'write']);
    $scopes = [
      AgentTokenScope::MEMORY_READ,
      AgentTokenScope::MEMORY_WRITE,
    ];
    $tokenA = $this->container->get(AgentTokenManager::class)->issue($agentA, $scopes);
    $tokenB = $this->container->get(AgentTokenManager::class)->issue($agentB, $scopes);
    $workspaceId = (string) $workspace->uuid();
    $boardId = (string) $board->uuid();
    $key = '11111111-1111-4111-8111-111111111134';

    $forged = $this->talk($tokenA->token, $workspaceId, [
      $this->request(1, 'initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => new \stdClass(),
        'clientInfo' => ['name' => 'phpunit-a', 'version' => '0'],
      ]),
      $this->request(2, 'tools/call', [
        'name' => 'create_memory_draft',
        'arguments' => [
          'idempotency_key' => $key,
          'title' => 'Lição MCP-34 do agente A',
          'content_markdown' => 'Marcador compartilhado entre agentes.',
          'kind' => 'lesson',
          'board_id' => $boardId,
          'author_id' => 'forged',
        ],
      ]),
    ]);
    $this->assertSame(0, $forged['exit'], $forged['stderr']);
    $forgedReplies = $this->replies($forged['stdout']);
    $this->assertSame(-32602, $forgedReplies[2]['error']['code']);

    $created = $this->talk($tokenA->token, $workspaceId, [
      $this->request(1, 'initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => new \stdClass(),
        'clientInfo' => ['name' => 'phpunit-a', 'version' => '0'],
      ]),
      $this->request(2, 'tools/call', [
        'name' => 'create_memory_draft',
        'arguments' => [
          'idempotency_key' => $key,
          'title' => 'Lição MCP-34 do agente A',
          'content_markdown' => 'Marcador compartilhado entre agentes.',
          'kind' => 'lesson',
          'board_id' => $boardId,
        ],
      ]),
    ]);
    $this->assertSame(0, $created['exit'], $created['stderr']);
    $this->assertStringNotContainsString($tokenA->token, $created['stderr']);
    $createdReplies = $this->replies($created['stdout']);
    $this->assertFalse($createdReplies[2]['result']['isError']);
    $draft = $createdReplies[2]['result']['structuredContent']['data'];
    $memoryId = $draft['id'];
    $this->assertSame('draft', $draft['status']);
    $this->assertSame((string) $agentA->uuid(), $draft['revision']['author_id']);

    $hidden = $this->talk($tokenB->token, $workspaceId, [
      $this->request(1, 'initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => new \stdClass(),
        'clientInfo' => ['name' => 'phpunit-b', 'version' => '0'],
      ]),
      $this->request(2, 'tools/call', [
        'name' => 'get_memory',
        'arguments' => ['memory_id' => $memoryId],
      ]),
      $this->request(3, 'tools/call', [
        'name' => 'search_memory',
        'arguments' => [
          'q' => 'MCP-34',
          'board_id' => $boardId,
        ],
      ]),
    ]);
    $this->assertSame(0, $hidden['exit'], $hidden['stderr']);
    $hiddenReplies = $this->replies($hidden['stdout']);
    $this->assertTrue($hiddenReplies[2]['result']['isError']);
    $this->assertSame(404, $hiddenReplies[2]['result']['structuredContent']['status']);
    $idsBefore = array_column($hiddenReplies[3]['result']['structuredContent']['data'] ?? [], 'memory_id');
    $this->assertNotContains($memoryId, $idsBefore);

    $this->container->get('current_user')->setAccount($owner);
    $human = $this->container->get(HumanActorContextFactory::class)->create($workspace);
    $published = $this->container->get(MemoryService::class)->publish($human, $memoryId, (int) $draft['version']);
    $this->assertSame('published', $published->status);

    $recovered = $this->talk($tokenB->token, $workspaceId, [
      $this->request(1, 'initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => new \stdClass(),
        'clientInfo' => ['name' => 'phpunit-b', 'version' => '0'],
      ]),
      $this->request(2, 'tools/call', [
        'name' => 'search_memory',
        'arguments' => [
          'q' => 'MCP-34',
          'board_id' => $boardId,
        ],
      ]),
      $this->request(3, 'tools/call', [
        'name' => 'get_memory',
        'arguments' => ['memory_id' => $memoryId],
      ]),
      $this->request(4, 'tools/call', [
        'name' => 'list_memory_revisions',
        'arguments' => ['memory_id' => $memoryId],
      ]),
    ]);
    $this->assertSame(0, $recovered['exit'], $recovered['stderr']);
    $this->assertStringNotContainsString($tokenB->token, $recovered['stderr']);
    $found = $this->replies($recovered['stdout']);
    $this->assertFalse($found[2]['result']['isError']);
    $this->assertContains($memoryId, array_column($found[2]['result']['structuredContent']['data'], 'memory_id'));
    $this->assertFalse($found[3]['result']['isError']);
    $this->assertSame($memoryId, $found[3]['result']['structuredContent']['data']['id']);
    $this->assertStringContainsString(
      'Marcador compartilhado entre agentes.',
      (string) $found[3]['result']['structuredContent']['data']['revision']['content_markdown'],
    );
    $this->assertFalse($found[4]['result']['isError']);
    $this->assertGreaterThanOrEqual(1, count($found[4]['result']['structuredContent']['data']));
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
   *   Process result.
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

}
