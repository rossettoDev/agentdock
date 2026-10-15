<?php

/**
 * @file
 * Reads or records one event on an independent PostgreSQL connection.
 *
 * Invoked by EventStoreKernelTest. This is not module runtime.
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Drupal\ab_event\EventDraft;
use Drupal\ab_event\EventStore;
use Drupal\Component\Datetime\Time;
use Drupal\Component\Uuid\Php;
use Drupal\Core\Database\Database;

try {
  $autoload = getenv('AGENTDOCK_AUTOLOAD');
  $request = getenv('AGENTDOCK_RECORD_REQUEST');
  $resultFile = getenv('AGENTDOCK_RESULT_FILE');
  $traceFile = getenv('AGENTDOCK_TRACE_FILE');
  if (!is_string($autoload) || !is_string($request) || !is_string($resultFile) || !is_string($traceFile)) {
    throw new \RuntimeException('Concurrent recorder environment is incomplete.');
  }
  $loader = require $autoload;
  if (!$loader instanceof ClassLoader) {
    throw new \RuntimeException('Composer autoload did not return a class loader.');
  }
  $root = dirname($autoload, 2);
  $loader->addPsr4(
    'Drupal\\pgsql\\Driver\\Database\\pgsql\\',
    $root . '/web/core/modules/pgsql/src/Driver/Database/pgsql/',
  );
  $loader->addPsr4(
    'Drupal\\ab_event\\',
    $root . '/web/modules/custom/ab_event/src/',
  );

  $payload = json_decode($request, TRUE, 512, JSON_THROW_ON_ERROR);
  if (!is_array($payload) || !isset($payload['connection']) || !is_array($payload['connection'])) {
    throw new \RuntimeException('Concurrent recorder request is invalid.');
  }
  $mode = $payload['mode'] ?? 'record';
  $trace = static function (string $step) use ($traceFile): void {
    file_put_contents($traceFile, $step . PHP_EOL, FILE_APPEND);
  };
  $trace('booted');

  Database::addConnectionInfo('default', 'default', $payload['connection']);
  $connection = Database::getConnection();
  $trace('connected');
  $connection->query("SET application_name = 'ab_event_concurrent'");
  $connection->query("SET default_transaction_isolation = 'read committed'");
  $trace('named');

  if ($mode === 'observe') {
    $workspaceId = $payload['workspace_id'] ?? '';
    $hiddenEventId = $payload['hidden_event_id'] ?? '';
    if (!is_string($workspaceId) || $workspaceId === '' || !is_string($hiddenEventId) || $hiddenEventId === '') {
      throw new \RuntimeException('Observation requires a workspace and a hidden event id.');
    }
    $trace('observing');
    $events = [];
    $select = $connection->select('ab_event', 'e');
    $select->fields('e', ['event_id', 'sequence', 'type']);
    $select->condition('workspace_id', $workspaceId);
    $select->orderBy('sequence');
    foreach ($select->execute() as $row) {
      $events[] = [
        'event_id' => (string) $row->event_id,
        'sequence' => (int) $row->sequence,
        'type' => (string) $row->type,
      ];
    }
    $outboxCount = $connection->select('ab_outbox', 'o')
      ->countQuery()
      ->execute()
      ->fetchField();
    $hiddenOutboxCount = $connection->select('ab_outbox', 'o')
      ->condition('event_id', $hiddenEventId)
      ->countQuery()
      ->execute()
      ->fetchField();
    $sequence = $connection->select('ab_workspace_sequence', 's')
      ->fields('s', ['sequence'])
      ->condition('workspace_id', $workspaceId)
      ->execute()
      ->fetchField();
    file_put_contents($resultFile, json_encode([
      'events' => $events,
      'outbox_count' => (int) $outboxCount,
      'hidden_outbox_count' => (int) $hiddenOutboxCount,
      'sequence' => (int) $sequence,
    ], JSON_THROW_ON_ERROR));
    $trace('observed');
    exit(0);
  }

  if (!isset($payload['draft']) || !is_array($payload['draft'])) {
    throw new \RuntimeException('Recording requires a draft.');
  }
  $draft = $payload['draft'];
  $transaction = $connection->startTransaction('ab_event_concurrent');
  $trace('before_lock');
  $stored = (new EventStore($connection, new Php(), new Time()))->record(new EventDraft(
    $draft['workspace_id'],
    $draft['type'],
    $draft['resource_id'],
    $draft['actor_type'],
    $draft['actor_id'],
    $draft['payload'],
  ));
  unset($transaction);
  $trace('committed');
  file_put_contents($resultFile, json_encode([
    'event_id' => $stored->eventId,
    'sequence' => $stored->sequence,
  ], JSON_THROW_ON_ERROR));
}
catch (\Throwable $throwable) {
  fwrite(STDERR, $throwable::class . ': ' . $throwable->getMessage() . PHP_EOL);
  exit(1);
}
