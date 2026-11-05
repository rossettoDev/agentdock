<?php
$now = \Drupal::time()->getRequestTime();
echo "now=$now\n";

$storage = \Drupal::entityTypeManager()->getStorage('ab_task');
$t = NULL;
foreach ($storage->loadByProperties(['title' => 'E6-T01 — Entidades de operação e resultados por site']) as $cand) {
  $t = $cand;
  break;
}
if (!$t) {
  // fallback by id 95
  $t = $storage->load(95);
}
if (!$t) { echo "TASK_NOT_FOUND\n"; exit; }

echo "task#{$t->id()} status={$t->get('status')->value} assigned=" . ($t->get('assigned_agent')->value ?? 'null') . " active_exec=" . ($t->get('active_execution')->value ?? 'null') . " version=" . ($t->get('version')->value ?? 'null') . " changed={$t->get('changed')->value}\n";

$exec_id = $t->get('active_execution')->value;
if ($exec_id) {
  $e = \Drupal::entityTypeManager()->getStorage('ab_execution')->load($exec_id);
  if ($e) {
    $lease = (int) $e->get('lease_expires_at')->value;
    echo "exec#{$e->id()} status={$e->get('status')->value} agent=" . $e->get('agent')->target_id . " attempt=" . $e->get('attempt')->value . " started=" . $e->get('started_at')->value . " lease=$lease expired=" . ($lease < $now ? 'yes' : 'no') . " delta=" . ($now - $lease) . "\n";
    foreach (['finished_at', 'failure_code', 'summary', 'lease_token_hash'] as $f) {
      if ($e->hasField($f) && !$e->get($f)->isEmpty()) {
        $v = $e->get($f)->getValue();
        if ($f === 'lease_token_hash') { echo "  $f=(present)\n"; continue; }
        echo "  $f=" . json_encode($v, JSON_UNESCAPED_UNICODE) . "\n";
      }
    }
  }
}

$agent_id = $t->get('assigned_agent')->value;
if ($agent_id) {
  $a = \Drupal::entityTypeManager()->getStorage('ab_agent')->load($agent_id);
  echo "agent#{$a->id()} {$a->label()} concurrency=" . $a->get('concurrency_limit')->value . " enabled=" . $a->get('enabled')->value . "\n";
}

// Running executions for agent 16
$running = \Drupal::entityTypeManager()->getStorage('ab_execution')->getQuery()
  ->accessCheck(FALSE)
  ->condition('status', 'running')
  ->execute();
echo "running_executions=" . count($running) . "\n";
foreach (\Drupal::entityTypeManager()->getStorage('ab_execution')->loadMultiple($running) as $e) {
  $lease = (int) $e->get('lease_expires_at')->value;
  echo "  running#{$e->id()} task=" . $e->get('task')->target_id . " agent=" . $e->get('agent')->target_id . " lease=$lease expired=" . ($lease < $now ? 'yes' : 'no') . "\n";
}

// Recent executions for task
$all = \Drupal::entityTypeManager()->getStorage('ab_execution')->getQuery()
  ->accessCheck(FALSE)
  ->condition('task', $t->id())
  ->sort('id', 'DESC')
  ->execute();
echo "task_executions:\n";
foreach (\Drupal::entityTypeManager()->getStorage('ab_execution')->loadMultiple($all) as $e) {
  $lease = $e->hasField('lease_expires_at') && !$e->get('lease_expires_at')->isEmpty() ? (int) $e->get('lease_expires_at')->value : 0;
  echo "  #{$e->id()} status={$e->get('status')->value} agent=" . $e->get('agent')->target_id . " attempt=" . $e->get('attempt')->value . " lease=$lease\n";
}

// LEASE_TTL from code if constant
$ref = new ReflectionClass(\Drupal\ab_agent\ExecutionWriter::class);
echo "ExecutionWriter exists\n";
