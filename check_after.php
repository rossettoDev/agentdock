<?php
$t = \Drupal::entityTypeManager()->getStorage('ab_task')->load(89);
$e = \Drupal::entityTypeManager()->getStorage('ab_execution')->load(45);
echo "task89 status=" . $t->get('status')->value . " assigned=" . ($t->get('assigned_agent')->value ?? 'null') . " active_exec=" . ($t->get('active_execution')->value ?? 'null') . "\n";
echo "exec45 status=" . $e->get('status')->value . "\n";
$ids = \Drupal::entityTypeManager()->getStorage('ab_task')->getQuery()->accessCheck(FALSE)->condition('board', 20)->sort('id')->execute();
foreach (\Drupal::entityTypeManager()->getStorage('ab_task')->loadMultiple($ids) as $task) {
  $st = $task->get('status')->value;
  if (in_array($st, ['ready', 'in_progress', 'in_review'], TRUE)) {
    echo "#{$task->id()}|$st|{$task->label()}\n";
  }
}
