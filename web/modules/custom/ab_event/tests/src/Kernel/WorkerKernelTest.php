<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_event\Kernel;

use Drupal\ab_event\EventCleaner;
use Drupal\ab_event\EventDraft;
use Drupal\ab_event\EventStoreInterface;
use Drupal\ab_event\CursorWindow;
use Drupal\ab_event\MaintenancePassInterface;
use Drupal\ab_event\OutboxDestinationInterface;
use Drupal\ab_event\OutboxProcessor;
use Drupal\ab_event\OutboxWorker;
use Drupal\ab_event\StoredEvent;
use Drupal\ab_event\SyncDestination;
use Drupal\ab_event\WorkerSleeperInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use Psr\Log\NullLogger;

/**
 * Proves the worker publishes once, retries, and keeps the audit.
 *
 * @group ab_event
 */
class WorkerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'ab_core',
    'ab_event',
  ];

  /**
   * Workspace used by the publication tests.
   */
  private string $workspaceId = '22222222-2222-4222-8222-222222222222';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('ab_event', [
      'ab_workspace_sequence',
      'ab_event',
      'ab_outbox',
      'ab_outbox_delivery',
    ]);
  }

  /**
   * A second worker skips the locked row and the publication stays unique.
   */
  public function testTwoWorkersPublishOnce(): void {
    $stored = $this->record($this->workspaceId, 'task.created');
    $now = $this->time()->getCurrentTime();
    $holder = new HoldingProcessor($this->database(), $this->secondConnection(), [new SyncDestination($this->database())]);
    $holder->now = $now;
    $claimed = $holder->claim($now, 1);
    $this->assertCount(1, $claimed);
    $this->assertSame(0, $holder->seen);
    $holder->project($claimed[0], $now);
    $this->assertSame([], $holder->claim($now, 1));
    $this->assertSame(1, $this->deliveries($stored->eventId));
    $again = $holder->deliverAvailable($now);
    $this->assertSame(0, $again['delivered']);
    $this->assertSame(1, $this->deliveries($stored->eventId));
  }

  /**
   * A failed attempt keeps the event and hides the exception text.
   */
  public function testRetryThenRequeuePublishesOnce(): void {
    $stored = $this->record($this->workspaceId, 'task.updated');
    $script = new ScriptedDestination($this->database(), 5);
    $processor = new OutboxProcessor($this->database(), [$script]);
    $now = $this->time()->getCurrentTime();
    for ($attempt = 0; $attempt < 5; $attempt++) {
      $result = $processor->deliverAvailable($now);
      $this->assertSame(0, $result['delivered']);
      $row = $this->outbox($stored->eventId);
      $this->assertSame('Delivery failed.', $row['last_error']);
      $this->assertStringNotContainsString('ab_secret', (string) $row['last_error']);
      $now = (int) $row['available_at'];
    }
    $blocked = $processor->deliverAvailable($now);
    $this->assertSame(0, $blocked['delivered'] + $blocked['failed'] + $blocked['dead']);
    $this->assertTrue($processor->requeue($stored->eventId, 'sync', $now));
    $done = $processor->deliverAvailable($now);
    $this->assertSame(1, $done['delivered']);
    $this->assertSame(1, $this->deliveries($stored->eventId));
    $this->assertSame(0, $processor->deliverAvailable($now)['delivered']);
  }

  /**
   * Expired events leave a watermark. A pending row stops the prefix.
   */
  public function testPurgeKeepsTheCounterAndTheCursor(): void {
    $old = $this->record($this->workspaceId, 'task.created');
    $fresh = $this->record($this->workspaceId, 'task.updated');
    $processor = new OutboxProcessor($this->database(), [new SyncDestination($this->database())]);
    $now = $this->time()->getCurrentTime();
    $this->assertSame(2, $processor->deliverAvailable($now)['delivered']);
    $this->database()->update('ab_event')
      ->fields(['created' => $now - (40 * 86400)])
      ->condition('event_id', $old->eventId)
      ->execute();
    $purge = (new EventCleaner($this->database()))->purge($now);
    $this->assertSame(1, $purge->events);
    $this->assertGreaterThan(0, $purge->outbox);
    $this->assertSame(0, $this->deliveries($old->eventId));
    $this->assertSame(1, $this->deliveries($fresh->eventId));
    $this->assertFalse($this->eventExists($old->eventId));
    $this->assertTrue($this->eventExists($fresh->eventId));
    $this->assertSame(2, $this->sequence());
    $this->assertTrue(CursorWindow::behind($this->database(), $this->workspaceId, 0));
    $this->assertFalse(CursorWindow::behind($this->database(), $this->workspaceId, 1));

    $blocked = $this->record('44444444-4444-4444-8444-444444444444', 'board.created');
    $this->database()->update('ab_event')
      ->fields(['created' => $now - (40 * 86400)])
      ->condition('event_id', $blocked->eventId)
      ->execute();
    $kept = (new EventCleaner($this->database()))->purge($now);
    $this->assertSame(0, $kept->events);
    $this->assertTrue($this->eventExists($blocked->eventId));
    $this->assertFalse(CursorWindow::behind($this->database(), '44444444-4444-4444-8444-444444444444', 0));
  }

  /**
   * Stop and the memory limit finish the current pass and leave the loop.
   */
  public function testLoopStopsWithoutStartingAnotherPass(): void {
    $sleeper = new CountingSleeper();
    $worker = $this->worker($sleeper, []);
    $sleeper->onWait = static function () use ($worker): void {
      $worker->requestStop();
    };
    $this->assertSame(1, $worker->run(15, OutboxWorker::MEMORY, 0, static function (): void {
    }));
    $this->assertSame(1, $sleeper->waits);
    $this->assertTrue($worker->stopping());

    $quiet = new CountingSleeper();
    $limited = $this->worker($quiet, [new FixedPass(3)]);
    $this->assertSame(1, $limited->run(15, 1, 0, static function (): void {
    }));
    $this->assertSame(0, $quiet->waits);
    $tick = $limited->tick();
    $this->assertSame(3, $tick->maintenance);
  }

  /**
   * Records one event and its outbox row.
   */
  private function record(string $workspaceId, string $type): StoredEvent {
    $transaction = $this->database()->startTransaction();
    $stored = $this->container->get(EventStoreInterface::class)->record(new EventDraft(
      $workspaceId,
      $type,
      '33333333-3333-4333-8333-333333333333',
      'human',
      '7',
    ));
    unset($transaction);
    return $stored;
  }

  /**
   * Builds a worker around the real processor.
   *
   * @param \Drupal\ab_event\WorkerSleeperInterface $sleeper
   *   Wait used by the loop.
   * @param \Drupal\ab_event\MaintenancePassInterface[] $passes
   *   Maintenance steps for this worker.
   */
  private function worker(WorkerSleeperInterface $sleeper, array $passes): OutboxWorker {
    return new OutboxWorker(
      new OutboxProcessor($this->database(), [new SyncDestination($this->database())]),
      new EventCleaner($this->database()),
      $passes,
      $sleeper,
      $this->time(),
      new NullLogger(),
    );
  }

  /**
   * Counts publications for one event.
   */
  private function deliveries(string $eventId): int {
    return (int) $this->database()->select('ab_outbox_delivery', 'd')
      ->condition('event_id', $eventId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Loads the outbox row for one event.
   *
   * @return array<string, mixed>
   *   The row.
   */
  private function outbox(string $eventId): array {
    $row = $this->database()->select('ab_outbox', 'o')
      ->fields('o')
      ->condition('event_id', $eventId)
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($row);
    return $row;
  }

  /**
   * Reports whether the event row is still stored.
   */
  private function eventExists(string $eventId): bool {
    return (int) $this->database()->select('ab_event', 'e')
      ->condition('event_id', $eventId)
      ->countQuery()
      ->execute()
      ->fetchField() > 0;
  }

  /**
   * Reads the workspace counter. Purge must not rewind it.
   */
  private function sequence(): int {
    return (int) $this->database()->query(
      'SELECT sequence FROM {ab_workspace_sequence} WHERE workspace_id = :workspace',
      [':workspace' => $this->workspaceId],
    )->fetchField();
  }

  /**
   * Opens a second database connection with the test prefix.
   */
  private function secondConnection(): Connection {
    $database = $this->database();
    if (empty(Database::getConnectionInfo('ab_event_probe'))) {
      Database::addConnectionInfo('ab_event_probe', 'default', $database->getConnectionOptions());
    }
    return Database::getConnection('default', 'ab_event_probe');
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

/**
 * Claims while another connection tries to lock the same row.
 */
final class HoldingProcessor extends OutboxProcessor {

  /**
   * Rows the second connection was able to lock.
   */
  public int $seen = -1;

  /**
   * Clock value the second connection uses.
   */
  public int $now = 0;

  /**
   * Opens the second connection used to skip a locked claim.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   Connection that holds the claim.
   * @param \Drupal\Core\Database\Connection $second
   *   Connection that must skip the locked row.
   * @param iterable<mixed> $destinations
   *   Publication handlers.
   */
  public function __construct(
    Connection $database,
    private readonly Connection $second,
    iterable $destinations,
  ) {
    parent::__construct($database, $destinations);
  }

  /**
   * {@inheritdoc}
   */
  protected function duringClaim(): void {
    $this->second->query('SET lock_timeout = 400');
    $rows = $this->second->query(
      'SELECT event_id FROM {ab_outbox} WHERE processed_at IS NULL AND available_at <= :now FOR UPDATE SKIP LOCKED',
      [':now' => $this->now + 3600],
    )->fetchAll();
    $this->seen = count($rows);
  }

}

/**
 * Fails a fixed number of times, then stores one publication.
 */
final class ScriptedDestination implements OutboxDestinationInterface {

  public function __construct(
    private readonly Connection $database,
    public int $failures,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'sync';
  }

  /**
   * {@inheritdoc}
   */
  public function deliver(array $event): void {
    if ($this->failures > 0) {
      $this->failures--;
      throw new \RuntimeException('token ab_secret_should_not_leak');
    }
    $this->database->insert('ab_outbox_delivery')
      ->fields([
        'event_id' => $event['event_id'],
        'destination' => 'sync',
        'delivered_at' => 1,
      ])
      ->execute();
  }

}

/**
 * Counts waits and can stop the worker from inside the wait.
 */
final class CountingSleeper implements WorkerSleeperInterface {

  /**
   * How many times the loop waited.
   */
  public int $waits = 0;

  /**
   * Called on each wait.
   *
   * @var \Closure|null
   */
  public ?\Closure $onWait = NULL;

  /**
   * {@inheritdoc}
   */
  public function wait(int $seconds, callable $stopped): void {
    $this->waits++;
    if ($this->onWait instanceof \Closure) {
      ($this->onWait)();
    }
  }

}

/**
 * Returns a fixed maintenance count.
 */
final class FixedPass implements MaintenancePassInterface {

  public function __construct(
    private readonly int $count,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function run(): int {
    return $this->count;
  }

}
