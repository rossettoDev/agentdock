<?php

/**
 * @file
 * Claims one task on an independent PostgreSQL connection.
 *
 * Invoked by ClaimKernelTest. This is not module runtime.
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Drupal\ab_agent\ClaimCommand;
use Drupal\ab_agent\ClaimRejectedException;
use Drupal\ab_agent\ClaimWriter;
use Drupal\ab_event\EventStore;
use Drupal\Component\Datetime\Time;
use Drupal\Component\Uuid\Php;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\Transaction;

$transaction = NULL;
try {
  $autoload = getenv('AGENTDOCK_AUTOLOAD');
  $request = getenv('AGENTDOCK_RECORD_REQUEST');
  $resultFile = getenv('AGENTDOCK_RESULT_FILE');
  $traceFile = getenv('AGENTDOCK_TRACE_FILE');
  if (!is_string($autoload) || !is_string($request) || !is_string($resultFile) || !is_string($traceFile)) {
    throw new \RuntimeException('Concurrent claim environment is incomplete.');
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
  $loader->addPsr4('Drupal\\ab_event\\', $root . '/web/modules/custom/ab_event/src/');
  $loader->addPsr4('Drupal\\ab_agent\\', $root . '/web/modules/custom/ab_agent/src/');
  $loader->addPsr4('Drupal\\ab_core\\', $root . '/web/modules/custom/ab_core/src/');

  $payload = json_decode($request, TRUE, 512, JSON_THROW_ON_ERROR);
  if (!is_array($payload) || !isset($payload['connection'], $payload['command']) || !is_array($payload['connection']) || !is_array($payload['command'])) {
    throw new \RuntimeException('Concurrent claim request is invalid.');
  }
  $trace = static function (string $step) use ($traceFile): void {
    file_put_contents($traceFile, $step . PHP_EOL, FILE_APPEND);
  };
  $trace('booted');
  Database::addConnectionInfo('default', 'default', $payload['connection']);
  $connection = Database::getConnection();
  $trace('connected');
  $connection->query("SET application_name = 'ab_claim_concurrent'");
  $connection->query("SET default_transaction_isolation = 'read committed'");

  $command = $payload['command'];
  try {
    $transaction = $connection->startTransaction('ab_claim_concurrent');
    $writer = new ClaimWriter(
      $connection,
      new EventStore($connection, new Php(), new Time()),
      new Php(),
      new Time(),
    );
    $claimed = $writer->execute(new ClaimCommand(
      (int) $command['workspace_id'],
      (string) $command['workspace_uuid'],
      (int) $command['agent_id'],
      (string) $command['agent_uuid'],
      (int) $command['task_id'],
      (string) $command['task_uuid'],
      (int) $command['board_id'],
      (int) $command['concurrency_limit'],
      (string) $command['token_id'],
    ));
    $transaction = NULL;
    $trace('committed');
    file_put_contents($resultFile, json_encode([
      'ok' => TRUE,
      'execution_uuid' => $claimed->executionUuid,
      'task_version' => $claimed->taskVersion,
    ], JSON_THROW_ON_ERROR));
    exit(0);
  }
  catch (ClaimRejectedException $exception) {
    if ($transaction instanceof Transaction) {
      $transaction->rollBack();
    }
    $trace('rejected');
    file_put_contents($resultFile, json_encode([
      'ok' => FALSE,
      'code' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(0);
  }
}
catch (\Throwable $exception) {
  if ($transaction instanceof Transaction) {
    $transaction->rollBack();
  }
  fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
  exit(1);
}
