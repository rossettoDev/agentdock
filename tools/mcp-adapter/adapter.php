<?php

/**
 * @file
 * MCP stdio adapter. One process is one agent credential.
 *
 * Environment: AGENT_BOARD_URL, AGENT_BOARD_TOKEN, AGENT_BOARD_WORKSPACE,
 * AGENT_BOARD_TIMEOUT (default 10), and AGENT_BOARD_COOKIE for the test
 * site only. The reference runner owns heartbeat. This process does not
 * store an execution. Closing stdin does not release or fail one.
 */

declare(strict_types=1);

use McpAdapter\CurlApiClient;
use McpAdapter\Environment;
use McpAdapter\StdioServer;
use McpAdapter\ToolGateway;

require __DIR__ . '/src/ApiClientInterface.php';
require __DIR__ . '/src/CurlApiClient.php';
require __DIR__ . '/src/Environment.php';
require __DIR__ . '/src/ProtocolException.php';
require __DIR__ . '/src/ToolCatalog.php';
require __DIR__ . '/src/ToolGateway.php';
require __DIR__ . '/src/StdioServer.php';

try {
  $server = new StdioServer(
    new ToolGateway(
      new CurlApiClient(
        Environment::url(),
        Environment::token(),
        Environment::timeout(),
        Environment::cookie(),
      ),
      Environment::workspace(),
    ),
    STDIN,
    STDOUT,
  );
  exit($server->serve());
}
catch (Throwable $exception) {
  fwrite(STDERR, $exception->getMessage() . "\n");
  exit(1);
}
