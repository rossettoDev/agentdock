<?php

/**
 * @file
 * Command line entry for the reference runner.
 */

declare(strict_types=1);

use AgentRunner\AgentRunner;

require __DIR__ . '/src/HttpClient.php';
require __DIR__ . '/src/AgentRunner.php';

$command = $argv[1] ?? '';
if ($command !== 'run' && $command !== 'heartbeat') {
  fwrite(STDERR, "Usage: runner.php run|heartbeat\n");
  exit(1);
}

try {
  $runner = AgentRunner::fromEnvironment();
  exit($command === 'run' ? $runner->run() : $runner->heartbeat());
}
catch (Throwable $exception) {
  fwrite(STDERR, $exception->getMessage() . "\n");
  exit(1);
}
