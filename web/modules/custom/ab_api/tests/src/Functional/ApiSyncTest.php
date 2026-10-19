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
use Drupal\ab_communication\MessageService;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Tests\BrowserTestBase;
use Symfony\Component\BrowserKit\Response;

/**
 * Proves snapshot, event cursors and the agent inbox stay consistent.
 *
 * @group ab_api
 */
class ApiSyncTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ab_api'];

  /**
   * A commit after the snapshot is still in the next event read.
   */
  public function testSnapshotAndInboxStayComplete(): void {
    $owner = $this->drupalCreateUser();
    $this->container->get('current_user')->setAccount($owner);
    $workspace = $this->container->get(WorkspaceCreator::class)->create($owner, 'Casa sync', 'America/Sao_Paulo');
    $boards = $this->container->get(BoardManager::class);
    $tasks = $this->container->get(TaskManager::class);
    $secret = $boards->create($workspace, 'Quadro secreto');
    $visible = $boards->create($workspace, 'Quadro visivel');
    $hidden = $tasks->create($secret, ['title' => 'Segredo do outro quadro']);
    $shown = $tasks->create($visible, ['title' => 'Tarefa visivel']);
    $agents = $this->container->get(AgentManager::class);
    $grants = $this->container->get(AgentGrantManager::class);
    $lead = $agents->create($workspace, 'Leitor', '', '', TRUE, 1);
    $other = $agents->create($workspace, 'Outro', '', '', TRUE, 1);
    $grants->grant($lead, $visible, ['read']);
    $grants->grant($other, $visible, ['read']);
    $scopes = [
      AgentTokenScope::BOARDS_READ,
      AgentTokenScope::TASKS_READ,
      AgentTokenScope::EVENTS_READ,
    ];
    $tokens = $this->container->get(AgentTokenManager::class);
    $leadToken = $tokens->issue($lead, $scopes);
    $otherToken = $tokens->issue($other, $scopes);
    $this->assertNotSame($leadToken->publicId, $otherToken->publicId);
    $prefix = '/api/agent-board/v1/workspaces/' . $workspace->uuid();
    $bearer = ['HTTP_AUTHORIZATION' => 'Bearer ' . $leadToken->token];
    $otherBearer = ['HTTP_AUTHORIZATION' => 'Bearer ' . $otherToken->token];

    $anonymous = $this->call('GET', $prefix . '/events');
    $this->assertSame(401, $anonymous->getStatusCode());

    $snapshot = $this->json('GET', $prefix . '/boards/' . $visible->uuid() . '/snapshot', $bearer);
    $this->assertSame(200, $snapshot['status']);
    $titles = array_column($snapshot['body']['data'], 'title');
    $this->assertContains('Tarefa visivel', $titles);
    $this->assertNotContains('Segredo do outro quadro', $titles);
    $cursor = $snapshot['body']['cursor'];
    $this->assertIsString($cursor);

    $created = $tasks->create($visible, ['title' => 'Tarefa nova']);
    $followed = $this->json('GET', $prefix . '/events', $bearer, ['cursor' => $cursor]);
    $this->assertSame(200, $followed['status']);
    $resources = array_column($followed['body']['data'], 'resource_id');
    $this->assertContains((string) $created->uuid(), $resources);
    $this->assertNotContains((string) $hidden->uuid(), $resources);
    $encoded = (string) json_encode($followed['body']);
    $this->assertStringNotContainsString('Segredo do outro quadro', $encoded);

    $pages = $this->walk($prefix . '/events', $bearer);
    $ids = array_column($pages, 'event_id');
    $this->assertSame($ids, array_values(array_unique($ids)));
    $sequences = array_column($pages, 'sequence');
    $ordered = $sequences;
    sort($ordered);
    $this->assertSame($ordered, $sequences);
    $seen = array_column($pages, 'resource_id');
    $this->assertContains((string) $shown->uuid(), $seen);
    $this->assertContains((string) $created->uuid(), $seen);
    $this->assertNotContains((string) $hidden->uuid(), $seen);
    $this->assertStringNotContainsString('Segredo do outro quadro', (string) json_encode($pages));

    $invalid = $this->call('GET', $prefix . '/events', $bearer, ['cursor' => 'nao-e-cursor']);
    $this->assertSame(422, $invalid->getStatusCode());

    $grants->revoke($lead, $visible);
    $revoked = $this->json('GET', $prefix . '/events', $bearer);
    $this->assertSame(200, $revoked['status']);
    $this->assertNotContains((string) $shown->uuid(), array_column($revoked['body']['data'], 'resource_id'));
    $this->assertStringNotContainsString('Tarefa visivel', (string) json_encode($revoked['body']));
    $denied = $this->call('GET', $prefix . '/boards/' . $visible->uuid() . '/snapshot', $bearer);
    $this->assertSame(404, $denied->getStatusCode());

    $grants->grant($lead, $visible, ['read']);
    $actor = $this->container->get(HumanActorContextFactory::class)->create($workspace);
    $this->container->get(MessageService::class)->post(
      $actor,
      'board',
      (string) $visible->uuid(),
      'comment',
      'Texto privado da inbox',
      NULL,
      NULL,
      [['type' => 'agent', 'id' => (string) $lead->uuid()]],
    );
    $inbox = $this->json('GET', $prefix . '/agent-inbox', $bearer);
    $this->assertSame(200, $inbox['status']);
    $this->assertCount(1, $inbox['body']['data']);
    $this->assertSame('message.mentioned', $inbox['body']['data'][0]['type']);
    $this->assertSame((string) $lead->uuid(), $inbox['body']['data'][0]['recipient_id']);
    $this->assertStringNotContainsString('Texto privado da inbox', (string) json_encode($inbox['body']));
    $this->assertSame(0, $this->executionCount((string) $shown->uuid()));

    $foreign = $this->json('GET', $prefix . '/agent-inbox', $otherBearer);
    $this->assertSame(200, $foreign['status']);
    $this->assertSame([], $foreign['body']['data']);

    $grants->revoke($lead, $visible);
    $cleared = $this->json('GET', $prefix . '/agent-inbox', $bearer);
    $this->assertSame(200, $cleared['status']);
    $this->assertSame([], $cleared['body']['data']);
    $this->assertStringNotContainsString('Texto privado da inbox', (string) json_encode($cleared['body']));

    $stale = (int) $this->container->get('database')->query(
      'SELECT MIN(sequence) FROM {ab_event} WHERE workspace_id = :workspace',
      [':workspace' => (string) $workspace->uuid()],
    )->fetchField();
    $this->container->get('database')->update('ab_event')
      ->fields(['created' => $this->container->get('datetime.time')->getRequestTime() - (40 * 86400)])
      ->condition('workspace_id', (string) $workspace->uuid())
      ->condition('sequence', $stale)
      ->execute();
    $expired = $this->call('GET', $prefix . '/events', $bearer, ['cursor' => (string) $stale]);
    $this->assertSame(410, $expired->getStatusCode());
    $problem = json_decode((string) $expired->getContent(), TRUE);
    $this->assertIsArray($problem);
    $this->assertSame('resync_required', $problem['code']);
    $hiddenBoard = $this->call('GET', $prefix . '/boards/' . $secret->uuid() . '/snapshot', $bearer);
    $this->assertSame(404, $hiddenBoard->getStatusCode());

    $grants->grant($lead, $visible, ['read']);
    $fresh = $this->json('GET', $prefix . '/boards/' . $visible->uuid() . '/snapshot', $bearer);
    $this->assertSame(200, $fresh['status']);
    $freshTitles = array_column($fresh['body']['data'], 'title');
    $this->assertContains('Tarefa visivel', $freshTitles);
    $this->assertContains('Tarefa nova', $freshTitles);
    $recovered = $tasks->create($visible, ['title' => 'Tarefa depois do resync']);
    $tail = $this->json('GET', $prefix . '/events', $bearer, ['cursor' => $fresh['body']['cursor']]);
    $this->assertSame(200, $tail['status']);
    $this->assertContains((string) $recovered->uuid(), array_column($tail['body']['data'], 'resource_id'));

    $this->drupalLogin($owner);
    $humanInbox = $this->call('GET', $prefix . '/agent-inbox');
    $this->assertSame(403, $humanInbox->getStatusCode());
    $outsider = $this->drupalCreateUser();
    $this->drupalLogin($outsider);
    $missing = $this->call('GET', $prefix . '/events');
    $this->assertSame(404, $missing->getStatusCode());
  }

  /**
   * Walks event pages of one item and returns every visible event.
   *
   * @param string $path
   *   Event path under the site.
   * @param array<string, string> $server
   *   Server headers.
   *
   * @return list<array<string, mixed>>
   *   Events in cursor order.
   */
  private function walk(string $path, array $server): array {
    $events = [];
    $cursor = NULL;
    for ($guard = 0; $guard < 40; $guard++) {
      $query = ['limit' => '1'];
      if (is_string($cursor)) {
        $query['cursor'] = $cursor;
      }
      $page = $this->json('GET', $path, $server, $query);
      $this->assertSame(200, $page['status'], (string) json_encode($page['body']));
      $batch = $page['body']['data'];
      $this->assertIsArray($batch);
      $this->assertLessThanOrEqual(1, count($batch));
      foreach ($batch as $event) {
        $this->assertIsArray($event);
        $events[] = $event;
      }
      $cursor = $page['body']['page']['next_cursor'];
      if ($cursor === NULL) {
        return $events;
      }
      $this->assertIsString($cursor);
    }
    $this->fail('The event cursor did not settle.');
    return [];
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
   * @param array<string, string> $query
   *   Query parameters appended after the URL is built.
   *
   * @return array{status: int, body: array<string, mixed>}
   *   Status and decoded body.
   */
  private function json(string $method, string $path, array $server = [], array $query = []): array {
    $response = $this->call($method, $path, $server, $query);
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
   * @param array<string, string> $query
   *   Query parameters appended after the URL is built.
   */
  private function call(string $method, string $path, array $server = [], array $query = []): Response {
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
    $client->request($method, $url, [], [], $server);
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

}
