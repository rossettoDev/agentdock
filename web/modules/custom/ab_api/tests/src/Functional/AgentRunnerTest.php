<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_api\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\TaskDependencyManager;
use Drupal\ab_board\TaskManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Core\Site\Settings;
use Drupal\Tests\BrowserTestBase;
use Symfony\Component\BrowserKit\Response;

/**
 * Proves the reference runner speaks HTTP and leaves review to a human.
 *
 * @group ab_api
 */
class AgentRunnerTest extends BrowserTestBase {

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
   * Two agents finish dependent tasks, and a lost lease stops the runner.
   */
  public function testRunnerCompletesTheCycleOverHttp(): void {
    $bare = $this->spawn(['PATH' => (string) getenv('PATH')], 'run');
    $this->assertSame(1, $bare['exit'], $bare['stderr']);
    $this->assertStringContainsString('Missing AGENT_BOARD_URL', $bare['stderr']);
    $source = $this->runnerSource();
    $this->assertStringNotContainsString('/approve', $source);
    $this->assertStringNotContainsString('/reject', $source);
    $this->assertStringNotContainsString('ab_task', $source);
    $this->assertStringNotContainsString('pgsql', $source);
    $this->assertStringNotContainsString('PDO', $source);

    $owner = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($owner);
    $workspace = $this->container->get(WorkspaceCreator::class)->create($owner, 'Casa runner', 'America/Sao_Paulo');
    $board = $this->container->get(BoardManager::class)->create($workspace, 'Quadro runner');
    $tasks = $this->container->get(TaskManager::class);
    $first = $tasks->create($board, ['title' => 'Entrega do runner']);
    $second = $tasks->create($board, ['title' => 'Revisao dependente']);
    $first = $tasks->transition($first, 'ready', 1);
    $second = $tasks->transition($second, 'ready', 1);
    $this->container->get(TaskDependencyManager::class)->add(
      $second,
      $first,
      (int) $second->get('version')->getString(),
    );
    $agents = $this->container->get(AgentManager::class);
    $grants = $this->container->get(AgentGrantManager::class);
    $tokens = $this->container->get(AgentTokenManager::class);
    $scopes = [
      AgentTokenScope::BOARDS_READ,
      AgentTokenScope::TASKS_READ,
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::EXECUTIONS_WRITE,
      AgentTokenScope::MESSAGES_WRITE,
    ];
    $lead = $agents->create($workspace, 'Executor', '', '', TRUE, 1);
    $reviewer = $agents->create($workspace, 'Revisor', '', '', TRUE, 1);
    $grants->grant($lead, $board, ['read', 'write']);
    $grants->grant($reviewer, $board, ['read', 'write']);
    $leadToken = $tokens->issue($lead, $scopes);
    $reviewToken = $tokens->issue($reviewer, $scopes);
    $this->assertNotSame($leadToken->publicId, $reviewToken->publicId);

    $directory = DRUPAL_ROOT . '/' . $this->siteDirectory;
    $leadState = $directory . '/runner-lead.json';
    $reviewState = $directory . '/runner-review.json';
    $workspaceId = (string) $workspace->uuid();
    $opened = $this->runner('run', $leadToken->token, $workspaceId, $leadState);
    $this->assertSame(0, $opened['exit'], $opened['stdout'] . $opened['stderr']);
    $execution = $this->marker($opened['stdout'], 'claimed');
    $request = $this->marker($opened['stdout'], 'waiting');
    $this->assertSame(1, $this->executionCount((string) $first->uuid()));
    $this->assertSame(1, $this->progressCount($execution));
    $this->assertSame('waiting_input', $this->executionStatus($execution));
    $stored = $this->readState($leadState);
    $this->assertArrayHasKey('boards', $stored['cursors']);
    $this->assertArrayHasKey('tasks:' . $board->uuid(), $stored['cursors']);
    $claimKey = 'task.claim:' . $first->uuid();
    $this->assertArrayHasKey($claimKey, $stored['keys']);
    $this->assertSame(0600, fileperms($leadState) & 0777);

    $repeated = $this->runner('run', $leadToken->token, $workspaceId, $leadState);
    $this->assertSame(0, $repeated['exit'], $repeated['stdout'] . $repeated['stderr']);
    $this->assertSame($request, $this->marker($repeated['stdout'], 'waiting'));
    $this->assertStringNotContainsString('claimed', $repeated['stdout']);
    $this->assertSame(1, $this->executionCount((string) $first->uuid()));
    $this->assertSame(1, $this->progressCount($execution));
    $this->assertSame($stored['keys'][$claimKey], $this->readState($leadState)['keys'][$claimKey]);

    $beat = $this->runner('heartbeat', $leadToken->token, $workspaceId, $leadState);
    $this->assertSame(0, $beat['exit'], $beat['stdout'] . $beat['stderr']);
    $this->assertStringContainsString('heartbeat', $beat['stdout']);
    $this->assertSame(1, $this->progressCount($execution));
    $intact = hash_file('sha256', $leadState);

    $broken = $leadState . '.broken';
    $copy = $this->readState($leadState);
    $badLease = 'ab_lease_' . str_repeat('ab', 16) . '.' . str_repeat('cd', 32);
    $copy['execution']['lease_token'] = $badLease;
    file_put_contents($broken, json_encode($copy));
    $stopped = $this->runner('heartbeat', $leadToken->token, $workspaceId, $broken);
    $this->assertSame(2, $stopped['exit'], $stopped['stdout'] . $stopped['stderr']);
    $this->assertStringContainsString('stopped', $stopped['stdout']);
    $this->assertStringNotContainsString($badLease, $stopped['stdout'] . $stopped['stderr']);
    $this->assertSame($intact, hash_file('sha256', $leadState));
    $this->assertSame('waiting_input', $this->executionStatus($execution));
    $this->assertSame('in_progress', $this->taskStatus((string) $first->uuid()));

    $blocked = $this->runner('run', $reviewToken->token, $workspaceId, $reviewState);
    $this->assertSame(0, $blocked['exit'], $blocked['stdout'] . $blocked['stderr']);
    $this->assertStringContainsString('idle', $blocked['stdout']);
    $this->assertSame(0, $this->executionCount((string) $second->uuid()));

    $this->drupalLogin($owner);
    $prefix = '/api/agent-board/v1/workspaces/' . $workspaceId;
    $csrf = trim((string) $this->call('GET', '/session/token')->getContent());
    $json = ['CONTENT_TYPE' => 'application/json'];
    $answer = $this->json('POST', $prefix . '/input-requests/' . $request . '/answer', $json + [
      'HTTP_X_CSRF_TOKEN' => $csrf,
      'HTTP_IDEMPOTENCY_KEY' => '11111111-1111-4111-8111-111111111111',
    ], '{"body":"Pode seguir"}');
    $this->assertSame(201, $answer['status']);

    $submitted = $this->runner('run', $leadToken->token, $workspaceId, $leadState);
    $this->assertSame(0, $submitted['exit'], $submitted['stdout'] . $submitted['stderr']);
    $this->assertSame($execution, $this->marker($submitted['stdout'], 'submitted'));
    $this->assertSame('in_review', $this->taskStatus((string) $first->uuid()));
    $this->assertSame(1, $this->progressCount($execution));

    $stillBlocked = $this->runner('run', $reviewToken->token, $workspaceId, $reviewState);
    $this->assertSame(0, $stillBlocked['exit'], $stillBlocked['stdout'] . $stillBlocked['stderr']);
    $this->assertStringContainsString('idle', $stillBlocked['stdout']);
    $this->assertSame(0, $this->executionCount((string) $second->uuid()));

    $task = $this->json('GET', $prefix . '/tasks/' . $first->uuid());
    $approved = $this->json('POST', $prefix . '/tasks/' . $first->uuid() . '/approve', $json + [
      'HTTP_X_CSRF_TOKEN' => $csrf,
      'HTTP_IDEMPOTENCY_KEY' => '22222222-2222-4222-8222-222222222222',
    ], '{"version":' . $task['body']['data']['version'] . '}');
    $this->assertSame(200, $approved['status']);
    $this->assertSame('done', $approved['body']['data']['status']);

    $reviewOpened = $this->runner('run', $reviewToken->token, $workspaceId, $reviewState);
    $this->assertSame(0, $reviewOpened['exit'], $reviewOpened['stdout'] . $reviewOpened['stderr']);
    $reviewExecution = $this->marker($reviewOpened['stdout'], 'claimed');
    $reviewRequest = $this->marker($reviewOpened['stdout'], 'waiting');
    $this->assertSame(1, $this->executionCount((string) $second->uuid()));
    $answered = $this->json('POST', $prefix . '/input-requests/' . $reviewRequest . '/answer', $json + [
      'HTTP_X_CSRF_TOKEN' => $csrf,
      'HTTP_IDEMPOTENCY_KEY' => '33333333-3333-4333-8333-333333333333',
    ], '{"body":"Pode entregar"}');
    $this->assertSame(201, $answered['status']);
    $reviewSubmitted = $this->runner('run', $reviewToken->token, $workspaceId, $reviewState);
    $this->assertSame(0, $reviewSubmitted['exit'], $reviewSubmitted['stdout'] . $reviewSubmitted['stderr']);
    $this->assertSame($reviewExecution, $this->marker($reviewSubmitted['stdout'], 'submitted'));
    $this->assertSame('in_review', $this->taskStatus((string) $second->uuid()));

    $secondTask = $this->json('GET', $prefix . '/tasks/' . $second->uuid());
    $secondApproved = $this->json('POST', $prefix . '/tasks/' . $second->uuid() . '/approve', $json + [
      'HTTP_X_CSRF_TOKEN' => $csrf,
      'HTTP_IDEMPOTENCY_KEY' => '44444444-4444-4444-8444-444444444444',
    ], '{"version":' . $secondTask['body']['data']['version'] . '}');
    $this->assertSame(200, $secondApproved['status']);
    $this->assertSame('done', $this->taskStatus((string) $second->uuid()));
    $this->assertNotSame($this->actor((string) $first->uuid()), $this->actor((string) $second->uuid()));
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
   * Runs the reference runner with only its own environment.
   *
   * @return array{exit: int, stdout: string, stderr: string}
   *   Process result. The lease is not part of it.
   */
  private function runner(string $command, string $token, string $workspace, string $state): array {
    $this->prepareRequest();
    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(BrowserKitDriver::class, $driver);
    $cookie = '';
    foreach ($driver->getClient()->getCookieJar()->all() as $stored) {
      if ($stored->getName() === 'SIMPLETEST_USER_AGENT') {
        $cookie = $stored->getValue();
      }
    }
    $this->assertNotSame('', $cookie);
    return $this->spawn([
      'PATH' => (string) getenv('PATH'),
      'AGENT_BOARD_URL' => $this->baseUrl,
      'AGENT_BOARD_TOKEN' => $token,
      'AGENT_BOARD_WORKSPACE' => $workspace,
      'AGENT_BOARD_STATE' => $state,
      'AGENT_BOARD_TIMEOUT' => '10',
      'AGENT_BOARD_INTERVAL' => '0',
      'AGENT_BOARD_COOKIE' => $cookie,
    ], $command);
  }

  /**
   * Starts the runner process.
   *
   * @param array<string, string> $env
   *   Environment passed to the process.
   * @param string $command
   *   Runner command, either run or heartbeat.
   *
   * @return array{exit: int, stdout: string, stderr: string}
   *   Process result.
   */
  private function spawn(array $env, string $command): array {
    $script = dirname(DRUPAL_ROOT) . '/tools/agent-runner/runner.php';
    $line = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($command);
    $pipes = [];
    $process = proc_open($line, [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ], $pipes, NULL, $env);
    $this->assertTrue(is_resource($process));
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
   * Reads one identifier the runner printed.
   */
  private function marker(string $stdout, string $name): string {
    $matched = preg_match('/^' . $name . ' ([0-9a-f-]{36})$/m', $stdout, $found);
    $this->assertSame(1, $matched, $stdout);
    return $found[1];
  }

  /**
   * Reads the local state file.
   *
   * @return array<string, mixed>
   *   Stored cursor, execution, and keys.
   */
  private function readState(string $path): array {
    $decoded = json_decode((string) file_get_contents($path), TRUE);
    $this->assertIsArray($decoded);
    return $decoded;
  }

  /**
   * Reads the runner source that the process executes.
   */
  private function runnerSource(): string {
    $root = dirname(DRUPAL_ROOT) . '/tools/agent-runner';
    $source = '';
    foreach (['runner.php', 'src/HttpClient.php', 'src/AgentRunner.php'] as $path) {
      $source .= (string) file_get_contents($root . '/' . $path);
    }
    return $source;
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
    $actor = $this->container->get('database')->query(
      'SELECT a.uuid FROM {ab_execution} e INNER JOIN {ab_agent} a ON a.id = e.agent INNER JOIN {ab_task} t ON t.id = e.task WHERE t.uuid = :uuid',
      [':uuid' => $taskUuid],
    )->fetchField();
    return is_string($actor) ? $actor : '';
  }

}
