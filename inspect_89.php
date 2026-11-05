<?php
$storage = \Drupal::entityTypeManager()->getStorage('ab_task');
$t = $storage->load(89);
if (!$t) { echo "NOT_FOUND\n"; exit; }
echo "id={$t->id()} status={$t->get('status')->value}\n";
echo "assigned_agent=" . ($t->get('assigned_agent')->value ?? 'null') . "\n";
echo "active_execution=" . ($t->get('active_execution')->value ?? 'null') . "\n";
echo "human_owner=" . ($t->get('human_owner')->target_id ?? 'null') . "\n";
echo "version=" . ($t->get('version')->value ?? 'null') . "\n";
echo "priority=" . ($t->get('priority')->value ?? 'null') . "\n";
echo "changed=" . $t->get('changed')->value . "\n";

foreach (\Drupal::entityTypeManager()->getDefinitions() as $id => $def) {
  if (str_starts_with($id, 'ab_')) {
    echo "etype|$id\n";
  }
}

$exec_id = $t->get('active_execution')->value;
if ($exec_id && \Drupal::entityTypeManager()->hasDefinition('ab_execution')) {
  $e = \Drupal::entityTypeManager()->getStorage('ab_execution')->load($exec_id);
  if ($e) {
    echo "execution loaded #$exec_id\n";
    foreach ($e->getFields() as $name => $field) {
      if ($field->isEmpty()) continue;
      $vals = $field->getValue();
      echo "  exec.$name=" . json_encode($vals, JSON_UNESCAPED_UNICODE) . "\n";
    }
  } else {
    echo "execution #$exec_id NOT_LOADED\n";
  }
}

$agent_id = $t->get('assigned_agent')->value;
if ($agent_id) {
  $a = \Drupal::entityTypeManager()->getStorage('ab_agent')->load($agent_id);
  if ($a) {
    echo "agent #{$a->id()} label={$a->label()}\n";
    foreach ($a->getFields() as $name => $field) {
      if ($field->isEmpty()) continue;
      if (in_array($name, ['token', 'secret', 'password', 'key'], true)) {
        echo "  agent.$name=(redacted)\n";
        continue;
      }
      echo "  agent.$name=" . json_encode($field->getValue(), JSON_UNESCAPED_UNICODE) . "\n";
    }
  }
}

// Recent executions on this board/task
if (\Drupal::entityTypeManager()->hasDefinition('ab_execution')) {
  $q = \Drupal::entityTypeManager()->getStorage('ab_execution')->getQuery()->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 15);
  if (\Drupal::service('entity_field.manager')->getFieldStorageDefinitions('ab_execution')['task'] ?? NULL) {
    // try condition later
  }
  $ids = $q->execute();
  echo "recent_executions=" . count($ids) . "\n";
  foreach (\Drupal::entityTypeManager()->getStorage('ab_execution')->loadMultiple($ids) as $e) {
    $bits = ["#{$e->id()}"];
    foreach (['task', 'agent', 'status', 'state', 'lease_expires', 'claimed_at', 'finished', 'board'] as $f) {
      if ($e->hasField($f) && !$e->get($f)->isEmpty()) {
        $bits[] = "$f=" . json_encode($e->get($f)->getValue(), JSON_UNESCAPED_UNICODE);
      }
    }
    echo "exec|" . implode('|', $bits) . "\n";
  }
}
