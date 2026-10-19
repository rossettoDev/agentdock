<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_api\Kernel;

use Drupal\ab_api\ApiException;
use Drupal\ab_api\ApiLimits;
use Drupal\ab_api\IdempotencyKey;
use Drupal\ab_api\IdempotencyService;
use Drupal\ab_api\JsonPayload;
use Drupal\ab_api\PageQuery;
use Drupal\ab_api\StoredResponse;
use Drupal\ab_communication\MessageService;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;

/**
 * Verifies payload rules and the idempotency reservation.
 *
 * @group ab_api
 */
class ApiKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'options',
    'ab_core',
    'ab_event',
    'ab_board',
    'ab_agent',
    'ab_communication',
    'ab_api',
  ];

  /**
   * Base64 key used to seal claim replays.
   */
  private string $sealKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('ab_api', ['ab_idempotency']);
    $this->sealKey = base64_encode(str_repeat('k', 32));
    $settings = Settings::getAll();
    $settings['ab_api_idempotency_key'] = $this->sealKey;
    new Settings($settings);
  }

  /**
   * Page size and JSON objects follow the contract.
   */
  public function testPayloadAndPageRules(): void {
    $this->assertSame(262144, ApiLimits::BODY);
    $this->assertSame(MessageService::BODY_LIMIT, ApiLimits::MESSAGE);
    $this->assertSame(50, PageQuery::limit(NULL));
    $this->assertSame(100, PageQuery::limit('100'));
    $this->assertSame([], JsonPayload::object('{}'));
    $this->assertSame(['title' => 'Tema'], JsonPayload::object('{"title":"Tema"}'));
    try {
      PageQuery::limit('101');
      $this->fail('A page above 100 was accepted.');
    }
    catch (ApiException $exception) {
      $this->assertSame(422, $exception->status);
    }
    try {
      JsonPayload::object('[]');
      $this->fail('A JSON list was accepted.');
    }
    catch (ApiException $exception) {
      $this->assertSame(422, $exception->status);
    }
    try {
      IdempotencyKey::fromHeader(NULL);
      $this->fail('A missing idempotency key was accepted.');
    }
    catch (ApiException $exception) {
      $this->assertSame(428, $exception->status);
    }
    try {
      IdempotencyKey::fromHeader('not-a-uuid');
      $this->fail('A free-form idempotency key was accepted.');
    }
    catch (ApiException $exception) {
      $this->assertSame(422, $exception->status);
    }
  }

  /**
   * The same key replays, and a different body does not run again.
   */
  public function testReplayDoesNotRepeatTheEffect(): void {
    $runs = 0;
    $first = $this->service()->execute(
      $this->actor(),
      'task.claim',
      $this->resource(),
      $this->key(),
      '{}',
      function () use (&$runs): StoredResponse {
        $runs++;
        return new StoredResponse(201, '{"data":{"ok":true}}');
      },
    );
    $second = $this->service()->execute(
      $this->actor(),
      'task.claim',
      $this->resource(),
      $this->key(),
      '{}',
      function () use (&$runs): StoredResponse {
        $runs++;
        return new StoredResponse(201, '{"data":{"other":true}}');
      },
    );
    $this->assertSame(1, $runs);
    $this->assertSame($first->body, $second->body);
    $this->assertSame(201, $second->status);
    try {
      $this->service()->execute(
        $this->actor(),
        'task.claim',
        $this->resource(),
        $this->key(),
        '{"other":true}',
        function () use (&$runs): StoredResponse {
          $runs++;
          return new StoredResponse(201, '{"data":{"again":true}}');
        },
      );
      $this->fail('A different body reused the key.');
    }
    catch (ApiException $exception) {
      $this->assertSame(409, $exception->status);
      $this->assertSame('idempotency_conflict', $exception->errorCode);
    }
    $this->assertSame(1, $runs);
  }

  /**
   * A second connection cannot reserve the key while the effect runs.
   */
  public function testConcurrentReservationBlocks(): void {
    $runs = 0;
    $blocked = FALSE;
    $this->service()->execute(
      $this->actor(),
      'task.claim',
      $this->resource(),
      $this->key(),
      '{}',
      function () use (&$runs, &$blocked): StoredResponse {
        $runs++;
        $second = $this->secondConnection();
        $second->query('SET lock_timeout = 400');
        try {
          $second->insert('ab_idempotency')
            ->fields($this->pendingFields())
            ->execute();
        }
        catch (\Throwable $exception) {
          $message = $exception->getMessage();
          $blocked = str_contains($message, 'lock timeout')
            || str_contains($message, '55P03');
          if (!$blocked) {
            throw $exception;
          }
        }
        return new StoredResponse(201, '{"data":{"ok":true}}');
      },
    );
    $this->assertTrue($blocked);
    $this->assertSame(1, $runs);
    $this->service()->execute(
      $this->actor(),
      'task.claim',
      $this->resource(),
      $this->key(),
      '{}',
      function () use (&$runs): StoredResponse {
        $runs++;
        return new StoredResponse(201, '{"data":{"ok":true}}');
      },
    );
    $this->assertSame(1, $runs);
  }

  /**
   * A claim replay is ciphertext and disappears with the lease.
   */
  public function testSealedReplayHidesTheLeaseToken(): void {
    $secret = '{"data":{"lease_token":"lease-token-secret"}}';
    $expires = $this->container->get('datetime.time')->getRequestTime() + 120;
    $stored = $this->service()->execute(
      $this->actor(),
      'task.claim',
      $this->resource(),
      $this->key(),
      '{}',
      function () use ($secret): StoredResponse {
        return new StoredResponse(201, $secret);
      },
      TRUE,
      $expires,
    );
    $this->assertSame($secret, $stored->body);
    $raw = $this->container->get('database')->select('ab_idempotency', 'i')
      ->fields('i', ['response_body', 'sealed'])
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($raw);
    $this->assertSame(1, (int) $raw['sealed']);
    $this->assertStringNotContainsString('lease-token-secret', (string) $raw['response_body']);
    $again = $this->service()->execute(
      $this->actor(),
      'task.claim',
      $this->resource(),
      $this->key(),
      '{}',
      function (): StoredResponse {
        $this->fail('The sealed replay ran the effect again.');
      },
      TRUE,
      $expires,
    );
    $this->assertSame($secret, $again->body);
    $this->service()->discardSealed($this->actor(), 'task.claim', $this->resource());
    $left = $this->container->get('database')->select('ab_idempotency', 'i')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(0, (int) $left);
    $settings = Settings::getAll();
    unset($settings['ab_api_idempotency_key']);
    new Settings($settings);
    $ran = 0;
    try {
      $this->service()->execute(
        $this->actor(),
        'task.claim',
        $this->resource(),
        'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        '{}',
        function () use (&$ran, $secret): StoredResponse {
          $ran++;
          return new StoredResponse(201, $secret);
        },
        TRUE,
        $expires,
      );
      $this->fail('A seal without the external key was stored.');
    }
    catch (ApiException $exception) {
      $this->assertSame(500, $exception->status);
      $this->assertStringNotContainsString('lease-token-secret', $exception->getMessage());
    }
    $this->assertSame(0, $ran);
  }

  /**
   * Builds the agent actor.
   */
  private function actor(): ActorContext {
    return new ActorContext(
      ActorContext::TYPE_AGENT,
      'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
      [],
      'cccccccccccccccccccccccccccccccc',
    );
  }

  /**
   * Returns the resource UUID.
   */
  private function resource(): string {
    return 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
  }

  /**
   * Returns the client key.
   */
  private function key(): string {
    return 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
  }

  /**
   * Fields a second connection uses to collide on the same key.
   *
   * @return array<string, int|string>
   *   Insert values.
   */
  private function pendingFields(): array {
    return [
      'workspace' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
      'actor_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      'token_id' => 'cccccccccccccccccccccccccccccccc',
      'operation' => 'task.claim',
      'resource_id' => $this->resource(),
      'idempotency_key' => $this->key(),
      'request_hash' => hash('sha256', '{}'),
      'response_status' => 0,
      'response_body' => '',
      'sealed' => 0,
      'status' => 'pending',
      'expires_at' => $this->container->get('datetime.time')->getRequestTime() + 120,
      'created' => $this->container->get('datetime.time')->getRequestTime(),
    ];
  }

  /**
   * Opens a second database connection with the test prefix.
   */
  private function secondConnection(): Connection {
    $database = $this->container->get('database');
    $this->assertInstanceOf(Connection::class, $database);
    if (empty(Database::getConnectionInfo('ab_api_probe'))) {
      $options = $database->getConnectionOptions();
      Database::addConnectionInfo('ab_api_probe', 'default', $options);
    }
    return Database::getConnection('default', 'ab_api_probe');
  }

  /**
   * Loads the idempotency service.
   */
  private function service(): IdempotencyService {
    $service = $this->container->get(IdempotencyService::class);
    $this->assertInstanceOf(IdempotencyService::class, $service);
    return $service;
  }

}
