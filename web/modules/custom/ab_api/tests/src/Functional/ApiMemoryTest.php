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
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\ab_memory\MemoryService;
use Drupal\Tests\BrowserTestBase;
use Symfony\Component\BrowserKit\Response;

/**
 * Proves memory HTTP routes stay inside scope with If-Match and idempotency.
 *
 * @group ab_api
 */
class ApiMemoryTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ab_api'];

  /**
   * Search, get, draft, revise, publish and archive follow the contract.
   */
  public function testMemoryApiScopeIdempotencyAndVersions(): void {
    $owner = $this->drupalCreateUser();
    $viewer = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($owner);
    $workspace = $this->container->get(WorkspaceCreator::class)->create($owner, 'Casa Memória API', 'America/Sao_Paulo');
    $visible = $this->container->get(BoardManager::class)->create($workspace, 'Quadro API');
    $hidden = $this->container->get(BoardManager::class)->create($workspace, 'Quadro oculto');
    $agent = $this->container->get(AgentManager::class)->create($workspace, 'MemReader', '', '', TRUE, 1);
    $this->container->get(AgentGrantManager::class)->grant($agent, $visible, ['read', 'write']);
    $readToken = $this->container->get(AgentTokenManager::class)->issue($agent, [
      AgentTokenScope::MEMORY_READ,
    ]);
    $writeToken = $this->container->get(AgentTokenManager::class)->issue($agent, [
      AgentTokenScope::MEMORY_READ,
      AgentTokenScope::MEMORY_WRITE,
    ]);
    $tasksOnly = $this->container->get(AgentTokenManager::class)->issue($agent, [
      AgentTokenScope::TASKS_READ,
    ]);
    $this->container->get(MembershipManager::class)->addMember($workspace, (string) $viewer->getAccountName(), 'viewer');

    $human = $this->container->get(HumanActorContextFactory::class)->create($workspace);
    $memory = $this->container->get(MemoryService::class);
    $draft = $memory->createDraft($human, [
      'title' => 'Rascunho humano',
      'content_markdown' => 'Ainda privado.',
      'kind' => 'fact',
      'board_id' => (string) $visible->uuid(),
    ]);
    $publishedSeed = $memory->createDraft($human, [
      'title' => 'Publicada no grant',
      'content_markdown' => 'Visível ao agente.',
      'kind' => 'decision',
      'board_id' => (string) $visible->uuid(),
    ]);
    $published = $memory->publish($human, $publishedSeed->uuid, $publishedSeed->version);
    $hiddenDraft = $memory->createDraft($human, [
      'title' => 'Segredo do outro quadro',
      'content_markdown' => 'Sem grant.',
      'kind' => 'lesson',
      'board_id' => (string) $hidden->uuid(),
    ]);
    $hiddenPublished = $memory->publish($human, $hiddenDraft->uuid, $hiddenDraft->version);
    $workspaceOnly = $memory->createDraft($human, [
      'title' => 'Só workspace',
      'content_markdown' => 'Humano apenas.',
      'kind' => 'summary',
    ]);
    $workspacePublished = $memory->publish($human, $workspaceOnly->uuid, $workspaceOnly->version);

    $prefix = '/api/agent-board/v1';
    $ws = (string) $workspace->uuid();
    $read = ['HTTP_AUTHORIZATION' => 'Bearer ' . $readToken->token];
    $write = ['HTTP_AUTHORIZATION' => 'Bearer ' . $writeToken->token];
    $narrow = ['HTTP_AUTHORIZATION' => 'Bearer ' . $tasksOnly->token];

    $anonymous = $this->call('GET', $prefix . '/workspaces/' . $ws . '/memory');
    $this->assertProblem($anonymous, 401, 'invalid_credential');

    $deniedScope = $this->call('GET', $prefix . '/workspaces/' . $ws . '/memory', $narrow);
    $this->assertProblem($deniedScope, 403, 'forbidden');

    $search = $this->json('GET', $prefix . '/workspaces/' . $ws . '/memory', $read);
    $this->assertSame(200, $search['status']);
    $ids = array_column($search['body']['data'], 'memory_id');
    $this->assertContains($published->uuid, $ids);
    $this->assertNotContains($draft->uuid, $ids);
    $this->assertNotContains($hiddenPublished->uuid, $ids);
    $this->assertNotContains($workspacePublished->uuid, $ids);
    $this->assertStringNotContainsString('Segredo do outro quadro', (string) json_encode($search['body']));

    $got = $this->json('GET', $prefix . '/workspaces/' . $ws . '/memory/' . $published->uuid, $read);
    $this->assertSame(200, $got['status']);
    $this->assertSame($published->uuid, $got['body']['data']['id']);
    $this->assertSame('"2"', $got['etag']);

    $draftGet = $this->call('GET', $prefix . '/workspaces/' . $ws . '/memory/' . $draft->uuid, $read);
    $this->assertProblem($draftGet, 404, 'not_found');

    $hiddenGet = $this->call('GET', $prefix . '/workspaces/' . $ws . '/memory/' . $hiddenPublished->uuid, $read);
    $this->assertProblem($hiddenGet, 404, 'not_found');

    $key = '11111111-1111-4111-8111-111111111101';
    $created = $this->json('POST', $prefix . '/workspaces/' . $ws . '/memory', $write + [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IDEMPOTENCY_KEY' => $key,
    ], json_encode([
      'title' => 'Lição do agente',
      'content_markdown' => 'Heartbeat a cada 30s.',
      'kind' => 'lesson',
      'board_id' => (string) $visible->uuid(),
    ], JSON_THROW_ON_ERROR));
    $this->assertSame(201, $created['status']);
    $this->assertSame('draft', $created['body']['data']['status']);
    $agentDraftId = $created['body']['data']['id'];
    $this->assertSame('"1"', $created['etag']);

    $replay = $this->json('POST', $prefix . '/workspaces/' . $ws . '/memory', $write + [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IDEMPOTENCY_KEY' => $key,
    ], json_encode([
      'title' => 'Lição do agente',
      'content_markdown' => 'Heartbeat a cada 30s.',
      'kind' => 'lesson',
      'board_id' => (string) $visible->uuid(),
    ], JSON_THROW_ON_ERROR));
    $this->assertSame(201, $replay['status']);
    $this->assertSame($agentDraftId, $replay['body']['data']['id']);
    $this->assertSame(1, $this->entryCount());

    $forged = $this->call('POST', $prefix . '/workspaces/' . $ws . '/memory', $write + [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IDEMPOTENCY_KEY' => '22222222-2222-4222-8222-222222222201',
    ], json_encode([
      'title' => 'x',
      'content_markdown' => 'y',
      'kind' => 'fact',
      'board_id' => (string) $visible->uuid(),
      'author_id' => 'forged',
    ], JSON_THROW_ON_ERROR));
    $this->assertProblem($forged, 422, 'invalid');

    $secret = $this->call('POST', $prefix . '/workspaces/' . $ws . '/memory', $write + [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IDEMPOTENCY_KEY' => '33333333-3333-4333-8333-333333333301',
    ], json_encode([
      'title' => 'Segredo',
      'content_markdown' => "password=hunter2\n",
      'kind' => 'fact',
      'board_id' => (string) $visible->uuid(),
    ], JSON_THROW_ON_ERROR));
    $this->assertProblem($secret, 422, 'invalid');
    $this->assertStringNotContainsString('hunter2', (string) $secret->getContent());

    $noMatch = $this->call('PATCH', $prefix . '/workspaces/' . $ws . '/memory/' . $agentDraftId, $write + [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IDEMPOTENCY_KEY' => '44444444-4444-4444-8444-444444444401',
    ], '{"content_markdown":"Novo","version":1}');
    $this->assertProblem($noMatch, 428, 'precondition_required');

    $revised = $this->json('PATCH', $prefix . '/workspaces/' . $ws . '/memory/' . $agentDraftId, $write + [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IF_MATCH' => '"1"',
      'HTTP_IDEMPOTENCY_KEY' => '55555555-5555-4555-8555-555555555501',
    ], json_encode([
      'content_markdown' => 'Heartbeat a cada 30s com lease.',
      'change_reason' => 'Detalhe',
      'version' => 1,
    ], JSON_THROW_ON_ERROR));
    $this->assertSame(200, $revised['status']);
    $this->assertSame(2, $revised['body']['data']['version']);
    $this->assertSame(2, $revised['body']['data']['revision']['version']);
    $this->assertSame('"2"', $revised['etag']);

    $stale = $this->call('PATCH', $prefix . '/workspaces/' . $ws . '/memory/' . $agentDraftId, $write + [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IF_MATCH' => '"1"',
      'HTTP_IDEMPOTENCY_KEY' => '66666666-6666-4666-8666-666666666601',
    ], '{"content_markdown":"Velho","version":1}');
    $this->assertProblem($stale, 412, 'stale_version');

    $publishDenied = $this->call('POST', $prefix . '/workspaces/' . $ws . '/memory/' . $agentDraftId . '/publish', $write + [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IF_MATCH' => '"2"',
      'HTTP_IDEMPOTENCY_KEY' => '77777777-7777-4777-8777-777777777701',
    ], '{"version":2}');
    $this->assertProblem($publishDenied, 403, 'forbidden');

    $this->drupalLogin($owner);
    $csrf = trim((string) $this->call('GET', '/session/token')->getContent());
    $humanHeaders = [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_CSRF_TOKEN' => $csrf,
      'HTTP_IF_MATCH' => '"2"',
      'HTTP_IDEMPOTENCY_KEY' => '88888888-8888-4888-8888-888888888801',
    ];
    $publishedAgent = $this->json('POST', $prefix . '/workspaces/' . $ws . '/memory/' . $agentDraftId . '/publish', $humanHeaders, '{"version":2}');
    $this->assertSame(200, $publishedAgent['status']);
    $this->assertSame('published', $publishedAgent['body']['data']['status']);

    $revisions = $this->json('GET', $prefix . '/workspaces/' . $ws . '/memory/' . $agentDraftId . '/revisions', $read);
    $this->assertSame(200, $revisions['status']);
    $this->assertGreaterThanOrEqual(2, count($revisions['body']['data']));

    $archived = $this->json('POST', $prefix . '/workspaces/' . $ws . '/memory/' . $agentDraftId . '/archive', [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_CSRF_TOKEN' => $csrf,
      'HTTP_IF_MATCH' => '"3"',
      'HTTP_IDEMPOTENCY_KEY' => '99999999-9999-4999-8999-999999999901',
    ], '{"version":3}');
    $this->assertSame(200, $archived['status']);
    $this->assertSame('archived', $archived['body']['data']['status']);

    $this->drupalLogin($viewer);
    $viewerCsrf = trim((string) $this->call('GET', '/session/token')->getContent());
    $viewerCreate = $this->call('POST', $prefix . '/workspaces/' . $ws . '/memory', [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_CSRF_TOKEN' => $viewerCsrf,
      'HTTP_IDEMPOTENCY_KEY' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa01',
    ], json_encode([
      'title' => 'Viewer',
      'content_markdown' => 'Não deve',
      'kind' => 'fact',
    ], JSON_THROW_ON_ERROR));
    $this->assertProblem($viewerCreate, 403, 'forbidden');
  }

  /**
   * Counts memory entries created in this test.
   */
  private function entryCount(): int {
    return (int) $this->container->get('database')->select('ab_memory_entry', 'e')
      ->condition('title', 'Lição do agente')
      ->countQuery()
      ->execute()
      ->fetchField();
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
    $this->assertSame($code, $decoded['code'] ?? NULL);
  }

}
