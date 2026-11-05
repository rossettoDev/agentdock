<?php

/**
 * @file
 * Starts the MCP adapter with the local AgentDock env file.
 *
 * The credential stays in .cursor/agentdock.env, which is not versioned.
 * This script does not print that file.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$override = getenv('AGENTDOCK_ENV');
$path = is_string($override) && $override !== ''
  ? $override
  : $root . '/.cursor/agentdock.env';

if (!is_file($path)) {
  fwrite(STDERR, "Falta .cursor/agentdock.env. Copie .cursor/agentdock.env.example e preencha.\n");
  exit(1);
}

$contents = file_get_contents($path);
if (!is_string($contents)) {
  fwrite(STDERR, "Nao foi possivel ler .cursor/agentdock.env.\n");
  exit(1);
}

$allowed = [
  'AGENT_BOARD_URL',
  'AGENT_BOARD_TOKEN',
  'AGENT_BOARD_WORKSPACE',
  'AGENT_BOARD_TIMEOUT',
];
$values = [];
foreach (preg_split("/\r\n|\n|\r/", $contents) as $line) {
  $line = trim($line);
  if ($line === '' || str_starts_with($line, '#')) {
    continue;
  }
  $pair = explode('=', $line, 2);
  if (count($pair) !== 2 || !in_array($pair[0], $allowed, TRUE)) {
    fwrite(STDERR, "Linha invalida em .cursor/agentdock.env.\n");
    exit(1);
  }
  $value = trim($pair[1]);
  if (
    (str_starts_with($value, '"') && str_ends_with($value, '"'))
    || (str_starts_with($value, "'") && str_ends_with($value, "'"))
  ) {
    $value = substr($value, 1, -1);
  }
  $values[$pair[0]] = $value;
}

foreach (['AGENT_BOARD_URL', 'AGENT_BOARD_TOKEN', 'AGENT_BOARD_WORKSPACE'] as $name) {
  if (($values[$name] ?? '') === '') {
    fwrite(STDERR, "Preencha $name em .cursor/agentdock.env.\n");
    exit(1);
  }
  putenv($name . '=' . $values[$name]);
}
if (isset($values['AGENT_BOARD_TIMEOUT']) && $values['AGENT_BOARD_TIMEOUT'] !== '') {
  putenv('AGENT_BOARD_TIMEOUT=' . $values['AGENT_BOARD_TIMEOUT']);
}

require __DIR__ . '/adapter.php';
