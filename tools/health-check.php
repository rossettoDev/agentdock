<?php

/**
 * @file
 * Confirms the site can serve the workspace after install or restore.
 *
 * The process prints counts and fixed labels. It does not print secrets.
 */

declare(strict_types=1);

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$root = dirname(__DIR__) . '/web';
chdir($root);
$autoloader = require dirname($root) . '/vendor/autoload.php';
$request = Request::create('/');
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();
$kernel->preHandle($request);

$failures = [];
try {
  \Drupal::database()->query('SELECT 1')->fetchField();
}
catch (Throwable) {
  $failures[] = 'database';
}

$modules = [
  'ab_core',
  'ab_board',
  'ab_agent',
  'ab_communication',
  'ab_event',
  'ab_api',
  'ab_ui',
];
foreach ($modules as $module) {
  if (!\Drupal::moduleHandler()->moduleExists($module)) {
    $failures[] = 'module ' . $module;
  }
}

$schema = \Drupal::keyValue('system.schema')->get('ab_event');
if (!is_int($schema) || $schema < 11002) {
  $failures[] = 'ab_event schema';
}

if (!class_exists('Drupal\\ab_event\\OutboxWorker')) {
  $failures[] = 'worker';
}

$workspaces = 0;
$tasks = 0;
try {
  $workspaces = (int) \Drupal::database()->query('SELECT COUNT(*) FROM {ab_workspace}')->fetchField();
  $tasks = (int) \Drupal::database()->query('SELECT COUNT(*) FROM {ab_task}')->fetchField();
}
catch (Throwable) {
  $failures[] = 'counts';
}
if ($workspaces < 1) {
  $failures[] = 'workspaces';
}

fwrite(STDOUT, 'workspaces=' . $workspaces . PHP_EOL);
fwrite(STDOUT, 'tasks=' . $tasks . PHP_EOL);
fwrite(STDOUT, 'schema_ab_event=' . (is_int($schema) ? (string) $schema : 'missing') . PHP_EOL);
if ($failures === []) {
  fwrite(STDOUT, "ok\n");
  exit(0);
}
fwrite(STDOUT, 'fail ' . implode(', ', $failures) . PHP_EOL);
exit(1);
