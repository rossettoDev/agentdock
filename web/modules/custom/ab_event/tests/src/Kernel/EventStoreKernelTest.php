<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_event\Kernel;

use Drupal\ab_event\EventDraft;
use Drupal\ab_event\EventStore;
use Drupal\ab_event\EventStoreInterface;
use Drupal\ab_event\Exception\TransactionRequiredException;
use Drupal\Core\Database\Connection;
use Drupal\KernelTests\KernelTestBase;

/**
 * Records events inside the caller transaction.
 *
 * @group ab_event
 */
class EventStoreKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'ab_core', 'ab_event'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('ab_event', [
      'ab_workspace_sequence',
      'ab_event',
      'ab_outbox',
    ]);
    $schema = $this->database()->schema();
    $schema->createTable('ab_event_caller_change', [
      'description' => 'Caller alteration used to prove transactional rollback.',
      'fields' => [
        'change_id' => [
          'type' => 'varchar_ascii',
          'length' => 36,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['change_id'],
    ]);
  }

  /**
   * Recording without a caller transaction is refused.
   */
  public function testRecordRequiresCallerTransaction(): void {
    $store = $this->container->get(EventStoreInterface::class);
    $this->assertInstanceOf(EventStore::class, $store);
    $this->expectException(TransactionRequiredException::class);
    $store->record($this->draft('task.created'));
  }

  /**
   * A committed transaction keeps the change, event and outbox in order.
   */
  public function testCommitPersistsSequenceEventAndOutbox(): void {
    $workspaceId = '22222222-2222-4222-8222-222222222222';
    $transaction = $this->database()->startTransaction();
    $this->database()->insert('ab_event_caller_change')
      ->fields(['change_id' => '55555555-5555-4555-8555-555555555555'])
      ->execute();
    $first = $this->store()->record($this->draft('task.created', $workspaceId, ['title' => 'One']));
    $second = $this->store()->record($this->draft('task.updated', $workspaceId, ['title' => 'Two'], [
      'notification',
      EventStoreInterface::DESTINATION_SYNC,
    ]));
    unset($transaction);

    $this->assertSame(1, $first->sequence);
    $this->assertSame(2, $second->sequence);
    $this->assertSame(1, $this->countRows('ab_event_caller_change'));
    $this->assertSame(2, $this->countRows('ab_event'));
    $this->assertSame(3, $this->countRows('ab_outbox'));
    $this->assertSame(2, $this->sequenceOf($workspaceId));

    $other = $this->database()->startTransaction();
    $otherStored = $this->store()->record($this->draft('task.created', '66666666-6666-4666-8666-666666666666'));
    unset($other);
    $this->assertSame(1, $otherStored->sequence);
  }

  /**
   * Rollback removes the caller change, the event and the outbox row.
   */
  public function testRollbackRemovesChangeEventAndOutbox(): void {
    $workspaceId = '22222222-2222-4222-8222-222222222222';
    $committed = $this->database()->startTransaction();
    $kept = $this->store()->record($this->draft('task.created', $workspaceId));
    unset($committed);

    $rolled = $this->database()->startTransaction();
    $this->database()->insert('ab_event_caller_change')
      ->fields(['change_id' => '55555555-5555-4555-8555-555555555555'])
      ->execute();
    $discarded = $this->store()->record($this->draft('task.updated', $workspaceId));
    $rolled->rollBack();
    unset($rolled);

    $this->assertSame(2, $discarded->sequence);
    $this->assertSame(0, $this->countRows('ab_event_caller_change'));
    $this->assertSame(1, $this->countRows('ab_event'));
    $this->assertSame(1, $this->countRows('ab_outbox'));
    $this->assertSame(1, $this->sequenceOf($workspaceId));
    $this->assertSame($kept->eventId, $this->onlyEventId());
  }

  /**
   * Two connections commit sequences in lock order.
   */
  public function testConcurrentCommitsKeepWorkspaceOrder(): void {
    $workspaceId = '22222222-2222-4222-8222-222222222222';
    $prefix = $this->database()->getConnectionOptions()['prefix'] ?? '';
    $this->assertIsString($prefix);
    $this->assertStringStartsWith('test', $prefix);

    $transaction = $this->database()->startTransaction();
    $first = $this->store()->record($this->draft('task.created', $workspaceId, ['title' => 'parent']));
    $resultFile = tempnam(sys_get_temp_dir(), 'ab_event_');
    $traceFile = tempnam(sys_get_temp_dir(), 'ab_event_trace_');
    $this->assertIsString($resultFile);
    $this->assertIsString($traceFile);

    $process = NULL;
    $pipes = [];
    try {
      $process = $this->startConcurrentRecorder($workspaceId, $resultFile, $traceFile, $pipes);
      $this->waitForTrace($traceFile, 'before_lock', $process, $pipes);
      usleep(500000);
      $trace = (string) file_get_contents($traceFile);
      $this->assertStringNotContainsString('committed', $trace);
      unset($transaction);
      $second = $this->finishConcurrentRecorder($process, $pipes, $resultFile);
    }
    finally {
      if (is_file($resultFile)) {
        unlink($resultFile);
      }
      if (is_file($traceFile)) {
        unlink($traceFile);
      }
    }

    $this->assertSame(1, $first->sequence);
    $this->assertSame(2, $second);
    $rows = $this->database()->select('ab_event', 'e')
      ->fields('e', ['sequence', 'type'])
      ->condition('workspace_id', $workspaceId)
      ->orderBy('sequence')
      ->execute()
      ->fetchAll();
    $this->assertCount(2, $rows);
    $this->assertSame('1', (string) $rows[0]->sequence);
    $this->assertSame('task.created', $rows[0]->type);
    $this->assertSame('2', (string) $rows[1]->sequence);
    $this->assertSame('task.updated', $rows[1]->type);
  }

  /**
   * Another connection does not see a rolled-back event.
   */
  public function testRolledBackEventIsHiddenFromIndependentConnection(): void {
    $workspaceId = '22222222-2222-4222-8222-222222222222';
    $this->assertStringStartsWith('test', $this->connectionOptions()['prefix']);

    $committed = $this->database()->startTransaction();
    $visible = $this->store()->record($this->draft('task.created', $workspaceId));
    unset($committed);

    $open = $this->database()->startTransaction();
    $hidden = $this->store()->record($this->draft('task.rejected', $workspaceId));
    $during = $this->observeFlow($workspaceId, $hidden->eventId);
    $open->rollBack();
    unset($open);
    $after = $this->observeFlow($workspaceId, $hidden->eventId);

    $this->assertConfirmedFlow($during, [$visible->eventId], ['task.created']);
    $this->assertSame(0, $during['hidden_outbox_count']);
    $this->assertConfirmedFlow($after, [$visible->eventId], ['task.created']);
    $this->assertSame(0, $after['hidden_outbox_count']);

    $resultFile = tempnam(sys_get_temp_dir(), 'ab_event_');
    $traceFile = tempnam(sys_get_temp_dir(), 'ab_event_trace_');
    $this->assertIsString($resultFile);
    $this->assertIsString($traceFile);
    $pipes = [];
    try {
      $process = $this->startConcurrentRecorder($workspaceId, $resultFile, $traceFile, $pipes);
      $sequence = $this->finishConcurrentRecorder($process, $pipes, $resultFile);
      $recorded = json_decode((string) file_get_contents($resultFile), TRUE);
    }
    finally {
      if (is_file($resultFile)) {
        unlink($resultFile);
      }
      if (is_file($traceFile)) {
        unlink($traceFile);
      }
    }

    $this->assertSame(2, $sequence);
    $this->assertIsArray($recorded);
    $final = $this->observeFlow($workspaceId, $hidden->eventId);
    $this->assertConfirmedFlow($final, [
      $visible->eventId,
      $recorded['event_id'],
    ], ['task.created', 'task.updated']);
    $this->assertSame(0, $final['hidden_outbox_count']);
    $this->assertSame(2, $final['outbox_count']);
  }

  /**
   * Asserts the ordered flow visible to another connection.
   *
   * @param array<string, mixed> $flow
   *   Observation returned by the independent connection.
   * @param string[] $eventIds
   *   Event ids that must be present, in order. The rolled-back id is absent.
   * @param string[] $types
   *   Event types in sequence order.
   */
  private function assertConfirmedFlow(array $flow, array $eventIds, array $types): void {
    $this->assertIsArray($flow['events']);
    $events = $flow['events'];
    $this->assertSame($eventIds, array_column($events, 'event_id'));
    $this->assertSame($types, array_column($events, 'type'));
    $this->assertSame(range(1, count($events)), array_column($events, 'sequence'));
    $this->assertSame(count($events), $flow['sequence']);
    $this->assertSame(count($events), $flow['outbox_count']);
  }

  /**
   * Returns the event store service.
   */
  private function store(): EventStoreInterface {
    $store = $this->container->get(EventStoreInterface::class);
    $this->assertInstanceOf(EventStoreInterface::class, $store);
    return $store;
  }

  /**
   * Returns the active test connection.
   */
  private function database(): Connection {
    $database = $this->container->get('database');
    $this->assertInstanceOf(Connection::class, $database);
    return $database;
  }

  /**
   * Builds a valid draft.
   *
   * @param string $type
   *   Event type.
   * @param string $workspaceId
   *   Workspace UUID.
   * @param array<mixed> $payload
   *   Event payload.
   * @param string[]|null $destinations
   *   Outbox destinations, or NULL for the default.
   */
  private function draft(string $type, string $workspaceId = '22222222-2222-4222-8222-222222222222', array $payload = [], ?array $destinations = NULL): EventDraft {
    if ($destinations === NULL) {
      return new EventDraft(
        $workspaceId,
        $type,
        '33333333-3333-4333-8333-333333333333',
        'human',
        '7',
        $payload,
      );
    }
    return new EventDraft(
      $workspaceId,
      $type,
      '33333333-3333-4333-8333-333333333333',
      'human',
      '7',
      $payload,
      $destinations,
    );
  }

  /**
   * Counts rows in a test table.
   */
  private function countRows(string $table): int {
    $count = $this->database()->select($table)
      ->countQuery()
      ->execute()
      ->fetchField();
    return (int) $count;
  }

  /**
   * Returns the stored counter for a workspace.
   */
  private function sequenceOf(string $workspaceId): int {
    $sequence = $this->database()->select('ab_workspace_sequence', 's')
      ->fields('s', ['sequence'])
      ->condition('workspace_id', $workspaceId)
      ->execute()
      ->fetchField();
    return (int) $sequence;
  }

  /**
   * Returns the only remaining event id.
   */
  private function onlyEventId(): string {
    $eventId = $this->database()->select('ab_event', 'e')
      ->fields('e', ['event_id'])
      ->execute()
      ->fetchField();
    $this->assertIsString($eventId);
    return $eventId;
  }

  /**
   * Starts the independent recorder and leaves it blocked on the lock.
   *
   * @param string $workspaceId
   *   Workspace UUID shared with the parent transaction.
   * @param string $resultFile
   *   File that receives the child sequence.
   * @param string $traceFile
   *   File that receives child progress markers.
   * @param array<int, resource> $pipes
   *   Process pipes, filled by reference.
   *
   * @return resource
   *   The child process.
   */
  private function startConcurrentRecorder(string $workspaceId, string $resultFile, string $traceFile, array &$pipes) {
    $request = [
      'mode' => 'record',
      'connection' => $this->connectionOptions(),
      'draft' => [
        'workspace_id' => $workspaceId,
        'type' => 'task.updated',
        'resource_id' => '33333333-3333-4333-8333-333333333333',
        'actor_type' => 'human',
        'actor_id' => '8',
        'payload' => ['title' => 'child'],
      ],
    ];
    return $this->openChild($request, $resultFile, $traceFile, $pipes);
  }

  /**
   * Reads the committed flow from a new database connection.
   *
   * @param string $workspaceId
   *   Workspace whose committed events are read.
   * @param string $hiddenEventId
   *   Event id that must stay invisible when its transaction rolls back.
   *
   * @return array<string, mixed>
   *   Events, outbox count and workspace sequence visible to the reader.
   */
  private function observeFlow(string $workspaceId, string $hiddenEventId): array {
    $resultFile = tempnam(sys_get_temp_dir(), 'ab_event_observe_');
    $traceFile = tempnam(sys_get_temp_dir(), 'ab_event_trace_');
    $this->assertIsString($resultFile);
    $this->assertIsString($traceFile);
    $pipes = [];
    try {
      $process = $this->openChild([
        'mode' => 'observe',
        'connection' => $this->connectionOptions(),
        'workspace_id' => $workspaceId,
        'hidden_event_id' => $hiddenEventId,
      ], $resultFile, $traceFile, $pipes);
      $this->finishConcurrentRecorder($process, $pipes, $resultFile);
      $decoded = json_decode((string) file_get_contents($resultFile), TRUE);
    }
    finally {
      if (is_file($resultFile)) {
        unlink($resultFile);
      }
      if (is_file($traceFile)) {
        unlink($traceFile);
      }
    }
    $this->assertIsArray($decoded);
    return $decoded;
  }

  /**
   * Returns connection options that stay on the prefixed test database.
   *
   * @return array<string, mixed>
   *   Driver options safe to pass to another process.
   */
  private function connectionOptions(): array {
    $options = $this->database()->getConnectionOptions();
    unset($options['pdo']);
    foreach ($options as $key => $value) {
      if (is_object($value)) {
        unset($options[$key]);
      }
    }
    $prefix = $options['prefix'] ?? '';
    $this->assertIsString($prefix);
    $this->assertStringStartsWith('test', $prefix);
    return $options;
  }

  /**
   * Starts the independent script.
   *
   * @param array<string, mixed> $request
   *   Payload for the child process.
   * @param string $resultFile
   *   File that receives the child result.
   * @param string $traceFile
   *   File that receives child progress markers.
   * @param array<int, resource> $pipes
   *   Process pipes, filled by reference.
   *
   * @return resource
   *   The child process.
   */
  private function openChild(array $request, string $resultFile, string $traceFile, array &$pipes) {
    $env = getenv();
    $this->assertIsArray($env);
    $env['AGENTDOCK_AUTOLOAD'] = dirname(DRUPAL_ROOT) . '/vendor/autoload.php';
    $env['AGENTDOCK_RECORD_REQUEST'] = json_encode($request, JSON_THROW_ON_ERROR);
    $env['AGENTDOCK_RESULT_FILE'] = $resultFile;
    $env['AGENTDOCK_TRACE_FILE'] = $traceFile;

    $process = proc_open(
      [PHP_BINARY, __DIR__ . '/concurrent_record.php'],
      [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
      ],
      $pipes,
      dirname(DRUPAL_ROOT),
      $env,
    );
    $this->assertIsResource($process);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], FALSE);
    stream_set_blocking($pipes[2], FALSE);
    return $process;
  }

  /**
   * Waits until the child trace contains a marker.
   *
   * @param string $traceFile
   *   File that receives child progress markers.
   * @param string $marker
   *   Marker written by the child.
   * @param resource $process
   *   The child process.
   * @param array<int, resource> $pipes
   *   The child pipes.
   */
  private function waitForTrace(string $traceFile, string $marker, $process, array $pipes): void {
    $stderr = '';
    $started = microtime(TRUE);
    $iterations = 0;
    $deadline = $started + 10;
    do {
      $iterations++;
      $stderr .= $this->readPipe($pipes[2]);
      $trace = is_file($traceFile) ? (string) file_get_contents($traceFile) : '';
      if (str_contains($trace, $marker)) {
        return;
      }
      $status = proc_get_status($process);
      if (!$status['running']) {
        $this->fail("Concurrent recorder exited before {$marker}.\n{$trace}\n{$stderr}");
      }
      usleep(20000);
    } while (microtime(TRUE) < $deadline);
    $this->fail(sprintf('Timed out waiting for %s after %.3fs and %d iterations.', $marker, microtime(TRUE) - $started, $iterations) . "\n{$trace}\n{$stderr}");
  }

  /**
   * Reads available bytes from a pipe.
   *
   * @param resource $pipe
   *   The pipe to read.
   */
  private function readPipe($pipe): string {
    $chunk = fread($pipe, 8192);
    return is_string($chunk) ? $chunk : '';
  }

  /**
   * Waits for the child commit and returns its sequence.
   *
   * @param resource $process
   *   The child process.
   * @param array<int, resource> $pipes
   *   The child pipes.
   * @param string $resultFile
   *   File written by the child.
   */
  private function finishConcurrentRecorder($process, array $pipes, string $resultFile): int {
    $stdout = '';
    $stderr = '';
    $deadline = microtime(TRUE) + 10;
    $exitCode = NULL;
    do {
      $stdout .= $this->readPipe($pipes[1]);
      $stderr .= $this->readPipe($pipes[2]);
      $status = proc_get_status($process);
      if (!$status['running']) {
        $exitCode = $status['exitcode'];
        break;
      }
      usleep(50000);
    } while (microtime(TRUE) < $deadline);

    if ($exitCode === NULL) {
      proc_terminate($process);
      $this->fail("Concurrent recorder timed out.\n{$stderr}");
    }
    $stdout .= $this->readPipe($pipes[1]);
    $stderr .= $this->readPipe($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $this->assertSame(0, $exitCode, $stderr . $stdout);
    $this->assertFileExists($resultFile);
    $decoded = json_decode((string) file_get_contents($resultFile), TRUE);
    $this->assertIsArray($decoded);
    return (int) $decoded['sequence'];
  }

}
