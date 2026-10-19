<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_api\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_agent\Entity\Execution;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskManager;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Tests\BrowserTestBase;
use Symfony\Component\BrowserKit\Response;

/**
 * Proves HTTP reads and edits stay inside the caller's scope.
 *
 * @group ab_api
 */
class ApiTaskTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ab_api'];

  /**
   * A bearer cannot see another board, and a PATCH cannot set status.
   */
  public function testScopeFiltersAndVersion(): void {
    $owner = $this->drupalCreateUser();
    $viewer = $this->drupalCreateUser();
    $outsider = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($owner);
    $workspace = $this->container->get(WorkspaceCreator::class)->create($owner, 'Casa API', 'America/Sao_Paulo');
    $other = $this->container->get(WorkspaceCreator::class)->create($owner, 'Outra casa', 'America/Sao_Paulo');
    $visible = $this->container->get(BoardManager::class)->create($workspace, 'Quadro visivel');
    $hidden = $this->container->get(BoardManager::class)->create($workspace, 'Quadro oculto');
    $first = $this->container->get(TaskManager::class)->create($visible, ['title' => 'Tema visivel']);
    $second = $this->container->get(TaskManager::class)->create($visible, ['title' => 'Segundo tema']);
    $secret = $this->container->get(TaskManager::class)->create($hidden, ['title' => 'Segredo do outro quadro']);
    $agent = $this->container->get(AgentManager::class)->create($workspace, 'Leitor', '', '', TRUE, 1);
    $this->container->get(AgentGrantManager::class)->grant($agent, $visible, ['read', 'write']);
    $readToken = $this->container->get(AgentTokenManager::class)->issue($agent, [
      AgentTokenScope::BOARDS_READ,
      AgentTokenScope::TASKS_READ,
    ]);
    $writeToken = $this->container->get(AgentTokenManager::class)->issue($agent, [
      AgentTokenScope::BOARDS_READ,
      AgentTokenScope::TASKS_READ,
      AgentTokenScope::TASKS_WRITE,
    ]);
    $this->container->get(MembershipManager::class)->addMember($workspace, (string) $viewer->getAccountName(), 'viewer');
    $execution = Execution::create([
      'workspace' => $workspace->id(),
      'task' => $first->id(),
      'agent' => $agent->id(),
      'status' => 'running',
      'lease_expires_at' => \Drupal::time()->getRequestTime() + 120,
      'started_at' => \Drupal::time()->getRequestTime(),
      'attempt' => 1,
      'version' => 1,
    ]);
    $execution->save();
    $this->container->get('database')->insert('ab_execution_lease')->fields([
      'task_id' => (int) $first->id(),
      'execution_id' => (int) $execution->id(),
      'workspace' => (int) $workspace->id(),
      'agent_id' => (int) $agent->id(),
      'token_hash' => 'lease-hash-must-not-leak',
      'expires_at' => \Drupal::time()->getRequestTime() + 120,
      'generation' => 1,
      'created' => \Drupal::time()->getRequestTime(),
    ])->execute();

    $prefix = '/api/agent-board/v1';
    $workspaceId = (string) $workspace->uuid();
    $otherId = (string) $other->uuid();
    $visibleId = (string) $visible->uuid();
    $hiddenId = (string) $hidden->uuid();
    $read = ['HTTP_AUTHORIZATION' => 'Bearer ' . $readToken->token];
    $write = ['HTTP_AUTHORIZATION' => 'Bearer ' . $writeToken->token];

    $anonymous = $this->call('GET', $prefix . '/workspaces');
    $this->assertProblem($anonymous, 401, 'invalid_credential');

    $listed = $this->json('GET', $prefix . '/workspaces', $read);
    $this->assertSame(200, $listed['status']);
    $this->assertSame([$workspaceId], array_column($listed['body']['data'], 'id'));

    $tasks = $this->json('GET', $prefix . '/workspaces/' . $workspaceId . '/boards/' . $visibleId . '/tasks', $read);
    $this->assertSame(200, $tasks['status']);
    $titles = array_column($tasks['body']['data'], 'title');
    $this->assertEqualsCanonicalizing(['Tema visivel', 'Segundo tema'], $titles);
    $this->assertStringNotContainsString('Segredo do outro quadro', (string) json_encode($tasks['body']));

    $hiddenPage = $this->call('GET', $prefix . '/workspaces/' . $workspaceId . '/boards/' . $hiddenId . '/tasks', $read);
    $this->assertProblem($hiddenPage, 404, 'not_found');
    $this->assertStringNotContainsString('Segredo do outro quadro', (string) $hiddenPage->getContent());

    $foreign = $this->call('GET', $prefix . '/workspaces/' . $otherId . '/boards', $read);
    $this->assertProblem($foreign, 404, 'not_found');
    $this->assertStringNotContainsString('Outra casa', (string) $foreign->getContent());

    $tasksPath = $prefix . '/workspaces/' . $workspaceId . '/boards/' . $visibleId . '/tasks';
    $page = $this->json('GET', $tasksPath, $read, '', ['limit' => '1']);
    $this->assertSame(200, $page['status']);
    $this->assertCount(1, $page['body']['data']);
    $cursor = $page['body']['page']['next_cursor'];
    $this->assertIsString($cursor);
    $next = $this->json('GET', $tasksPath, $read, '', [
      'limit' => '1',
      'cursor' => $cursor,
    ]);
    $this->assertSame(200, $next['status']);
    $seen = array_merge(array_column($page['body']['data'], 'title'), array_column($next['body']['data'], 'title'));
    $this->assertEqualsCanonicalizing(['Tema visivel', 'Segundo tema'], $seen);
    $this->assertNull($next['body']['page']['next_cursor']);

    $tooMany = $this->call('GET', $tasksPath, $read, '', ['limit' => '101']);
    $this->assertProblem($tooMany, 422, 'invalid');
    $badStatus = $this->call('GET', $tasksPath, $read, '', ['status' => 'nope']);
    $this->assertProblem($badStatus, 422, 'invalid');

    $before = $this->taskCount();
    $denied = $this->call('POST', $prefix . '/workspaces/' . $workspaceId . '/boards/' . $visibleId . '/tasks', $read + [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IDEMPOTENCY_KEY' => '11111111-1111-4111-8111-111111111111',
    ], '{"title":"Nao deve nascer"}');
    $this->assertProblem($denied, 403, 'forbidden');
    $this->assertSame($before, $this->taskCount());
    $this->assertStringNotContainsString('Nao deve nascer', (string) $denied->getContent());

    $key = '22222222-2222-4222-8222-222222222222';
    $created = $this->json('POST', $prefix . '/workspaces/' . $workspaceId . '/boards/' . $visibleId . '/tasks', $write + [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IDEMPOTENCY_KEY' => $key,
    ], '{"title":"Tarefa do agente"}');
    $this->assertSame(201, $created['status']);
    $createdId = $created['body']['data']['id'];
    $this->assertSame($before + 1, $this->taskCount());
    $replay = $this->json('POST', $prefix . '/workspaces/' . $workspaceId . '/boards/' . $visibleId . '/tasks', $write + [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IDEMPOTENCY_KEY' => $key,
    ], '{"title":"Tarefa do agente"}');
    $this->assertSame(201, $replay['status']);
    $this->assertSame($createdId, $replay['body']['data']['id']);
    $this->assertSame($before + 1, $this->taskCount());
    $event = $this->container->get('database')->query(
      'SELECT actor_type, actor_id FROM {ab_event} WHERE type = :type AND resource_id = :id',
      [':type' => 'task.created', ':id' => $createdId],
    )->fetchAssoc();
    $this->assertIsArray($event);
    $this->assertSame('agent', $event['actor_type']);
    $this->assertSame((string) $agent->uuid(), $event['actor_id']);

    $agentBoard = $this->call('POST', $prefix . '/workspaces/' . $workspaceId . '/boards', $write + [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IDEMPOTENCY_KEY' => '33333333-3333-4333-8333-333333333333',
    ], '{"name":"Quadro do agente"}');
    $this->assertProblem($agentBoard, 403, 'forbidden');

    $agentMove = $this->call('POST', $prefix . '/workspaces/' . $workspaceId . '/tasks/' . $first->uuid() . '/transition', $write + [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IF_MATCH' => '"1"',
      'HTTP_IDEMPOTENCY_KEY' => '44444444-4444-4444-8444-444444444444',
    ], '{"to":"ready","version":1}');
    $this->assertProblem($agentMove, 403, 'forbidden');
    $this->assertSame('backlog', $this->statusOf($first));

    $this->drupalLogin($owner);
    $missingCsrf = $this->call('POST', $prefix . '/workspaces/' . $workspaceId . '/boards/' . $visibleId . '/tasks', [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IDEMPOTENCY_KEY' => '55555555-5555-4555-8555-555555555555',
    ], '{"title":"Sem csrf"}');
    $this->assertProblem($missingCsrf, 403, 'csrf');
    $this->assertSame($before + 1, $this->taskCount());

    $token = trim((string) $this->call('GET', '/session/token')->getContent());
    $human = $this->json('POST', $prefix . '/workspaces/' . $workspaceId . '/boards/' . $visibleId . '/tasks', [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_CSRF_TOKEN' => $token,
      'HTTP_IDEMPOTENCY_KEY' => '66666666-6666-4666-8666-666666666666',
    ], '{"title":"Tarefa humana"}');
    $this->assertSame(201, $human['status']);
    $this->assertSame('"1"', $human['etag']);
    $humanId = $human['body']['data']['id'];

    $noMatch = $this->call('PATCH', $prefix . '/workspaces/' . $workspaceId . '/tasks/' . $humanId, [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_CSRF_TOKEN' => $token,
      'HTTP_IDEMPOTENCY_KEY' => '77777777-7777-4777-8777-777777777777',
    ], '{"title":"Sem versao","version":1}');
    $this->assertProblem($noMatch, 428, 'precondition_required');

    $stale = $this->call('PATCH', $prefix . '/workspaces/' . $workspaceId . '/tasks/' . $humanId, [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_CSRF_TOKEN' => $token,
      'HTTP_IF_MATCH' => '"9"',
      'HTTP_IDEMPOTENCY_KEY' => '88888888-8888-4888-8888-888888888888',
    ], '{"title":"Velha","version":9}');
    $this->assertProblem($stale, 412, 'stale_version');

    $status = $this->call('PATCH', $prefix . '/workspaces/' . $workspaceId . '/tasks/' . $humanId, [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_CSRF_TOKEN' => $token,
      'HTTP_IF_MATCH' => '"1"',
      'HTTP_IDEMPOTENCY_KEY' => '99999999-9999-4999-8999-999999999999',
    ], '{"status":"done","version":1}');
    $this->assertProblem($status, 422, 'invalid');
    $this->assertSame('backlog', $this->statusOfUuid($humanId));

    $renamed = $this->json('PATCH', $prefix . '/workspaces/' . $workspaceId . '/tasks/' . $humanId, [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_CSRF_TOKEN' => $token,
      'HTTP_IF_MATCH' => '"1"',
      'HTTP_IDEMPOTENCY_KEY' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    ], '{"title":"Tarefa revisada","version":1}');
    $this->assertSame(200, $renamed['status']);
    $this->assertSame('Tarefa revisada', $renamed['body']['data']['title']);
    $this->assertSame('"2"', $renamed['etag']);

    $moved = $this->json('POST', $prefix . '/workspaces/' . $workspaceId . '/tasks/' . $humanId . '/transition', [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_CSRF_TOKEN' => $token,
      'HTTP_IF_MATCH' => '"2"',
      'HTTP_IDEMPOTENCY_KEY' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    ], '{"to":"ready","version":2}');
    $this->assertSame(200, $moved['status']);
    $this->assertSame('ready', $moved['body']['data']['status']);

    $this->drupalLogin($viewer);
    $viewerToken = trim((string) $this->call('GET', '/session/token')->getContent());
    $seenTask = $this->json('GET', $prefix . '/workspaces/' . $workspaceId . '/tasks/' . $second->uuid());
    $this->assertSame(200, $seenTask['status']);
    $this->assertSame('Segundo tema', $seenTask['body']['data']['title']);
    $viewerPatch = $this->call('PATCH', $prefix . '/workspaces/' . $workspaceId . '/tasks/' . $second->uuid(), [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_CSRF_TOKEN' => $viewerToken,
      'HTTP_IF_MATCH' => '"1"',
      'HTTP_IDEMPOTENCY_KEY' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    ], '{"title":"Viewer muda","version":1}');
    $this->assertProblem($viewerPatch, 403, 'forbidden');
    $this->assertSame('Segundo tema', $this->titleOf($second));

    $this->drupalLogin($outsider);
    $outside = $this->call('GET', $prefix . '/workspaces/' . $workspaceId . '/tasks/' . $secret->uuid());
    $this->assertProblem($outside, 404, 'not_found');
    $this->assertStringNotContainsString('Segredo do outro quadro', (string) $outside->getContent());

    $this->drupalLogin($owner);
    $detail = $this->json('GET', $prefix . '/workspaces/' . $workspaceId . '/executions/' . $execution->uuid());
    $this->assertSame(200, $detail['status']);
    $this->assertArrayNotHasKey('lease_token', $detail['body']['data']);
    $this->assertStringNotContainsString('lease-hash-must-not-leak', (string) json_encode($detail['body']));
    $this->assertSame((string) $execution->uuid(), $detail['body']['data']['id']);

    $this->assertFalse($this->container->get('module_handler')->moduleExists('jsonapi'));
    $this->assertFalse($this->container->get('module_handler')->moduleExists('rest'));
    $generic = $this->call('GET', '/jsonapi');
    $this->assertSame(404, $generic->getStatusCode());
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
   * @param array<string, string> $query
   *   Query parameters.
   *
   * @return array{status: int, body: array<string, mixed>, etag: string}
   *   Status, decoded body and ETag.
   */
  private function json(string $method, string $path, array $server = [], string $body = '', array $query = []): array {
    $response = $this->call($method, $path, $server, $body, $query);
    $decoded = json_decode((string) $response->getContent(), TRUE);
    $this->assertIsArray($decoded);
    return [
      'status' => $response->getStatusCode(),
      'body' => $decoded,
      'etag' => (string) $response->getHeader('ETag'),
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
   * @param array<string, string> $query
   *   Query parameters.
   */
  private function call(string $method, string $path, array $server = [], string $body = '', array $query = []): Response {
    $server += [
      'HTTP_X_REQUEST_ID' => '11111111-1111-4111-8111-111111111111',
    ];
    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(BrowserKitDriver::class, $driver);
    $client = $driver->getClient();
    $url = $this->buildUrl($path);
    if ($query !== []) {
      $url .= '?' . http_build_query($query);
    }
    $client->request($method, $url, [], [], $server, $body);
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
   * Counts stored tasks.
   */
  private function taskCount(): int {
    return (int) $this->container->get('database')->select('ab_task', 't')->countQuery()->execute()->fetchField();
  }

  /**
   * Reads the stored status of a task entity.
   */
  private function statusOf(Task $task): string {
    return $this->statusOfUuid((string) $task->uuid());
  }

  /**
   * Reads the stored status of a task UUID.
   */
  private function statusOfUuid(string $uuid): string {
    $status = $this->container->get('database')->query('SELECT status FROM {ab_task} WHERE uuid = :uuid', [
      ':uuid' => $uuid,
    ])->fetchField();
    return is_string($status) ? $status : '';
  }

  /**
   * Reads the stored title of a task.
   */
  private function titleOf(Task $task): string {
    $title = $this->container->get('database')->query('SELECT title FROM {ab_task} WHERE uuid = :uuid', [
      ':uuid' => (string) $task->uuid(),
    ])->fetchField();
    return is_string($title) ? $title : '';
  }

}
