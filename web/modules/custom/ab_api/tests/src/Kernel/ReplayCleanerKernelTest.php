<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_api\Kernel;

use Drupal\ab_api\ReplayCleaner;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\KernelTests\KernelTestBase;

/**
 * Drops idempotency replays after their window and keeps the rest.
 *
 * @group ab_api
 */
class ReplayCleanerKernelTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('ab_api', ['ab_idempotency']);
  }

  /**
   * An expired replay goes away. A replay still in its window stays.
   */
  public function testExpiredReplayIsRemoved(): void {
    $now = $this->time()->getRequestTime();
    $this->insert('expired-completed', 'completed', $now - 10);
    $this->insert('expired-pending', 'pending', $now - 10);
    $this->insert('fresh', 'completed', $now + 3600);
    $removed = (new ReplayCleaner($this->database(), $this->time()))->run();
    $this->assertSame(2, $removed);
    $left = $this->database()->select('ab_idempotency', 'i')
      ->fields('i', ['idempotency_key'])
      ->execute()
      ->fetchCol();
    $this->assertSame(['fresh'], $left);
  }

  /**
   * Inserts one replay row.
   */
  private function insert(string $key, string $status, int $expires): void {
    $now = $this->time()->getRequestTime();
    $this->database()->insert('ab_idempotency')
      ->fields([
        'workspace' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'actor_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'token_id' => '',
        'operation' => 'task.update',
        'resource_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        'idempotency_key' => $key,
        'request_hash' => hash('sha256', '{}'),
        'response_status' => 200,
        'response_body' => '{}',
        'sealed' => 0,
        'status' => $status,
        'expires_at' => $expires,
        'created' => $now,
      ])
      ->execute();
  }

  /**
   * Loads the test clock.
   */
  private function time(): TimeInterface {
    $time = $this->container->get('datetime.time');
    $this->assertInstanceOf(TimeInterface::class, $time);
    return $time;
  }

  /**
   * Loads the active test connection.
   */
  private function database(): Connection {
    $database = $this->container->get('database');
    $this->assertInstanceOf(Connection::class, $database);
    return $database;
  }

}
