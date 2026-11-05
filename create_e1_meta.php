<?php
$board_uuid = '27f43bb5-e88e-4f15-98b0-37964440d701';
$boards = \Drupal::entityTypeManager()->getStorage('ab_board')->loadByProperties(['uuid' => $board_uuid]);
$board = $boards ? reset($boards) : NULL;
if (!$board) {
  throw new \RuntimeException('Board not found');
}
$workspace = $board->get('workspace')->target_id;
echo "board_id={$board->id()} workspace=$workspace\n";

$defs = $board->getFieldDefinitions(); // noop keep
$task_defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('ab_task', 'ab_task');
$status_def = $task_defs['status'];
$settings = $status_def->getSettings();
echo "status allowed:\n";
print_r($settings['allowed_values'] ?? $settings);

$priority_def = $task_defs['priority'];
echo "priority allowed:\n";
print_r($priority_def->getSettings()['allowed_values'] ?? $priority_def->getSettings());

// Existing tasks on this board
$q = \Drupal::entityTypeManager()->getStorage('ab_task')->getQuery()
  ->accessCheck(FALSE)
  ->condition('board', $board->id());
$ids = $q->execute();
echo "existing_count=" . count($ids) . "\n";
foreach (\Drupal::entityTypeManager()->getStorage('ab_task')->loadMultiple($ids) as $t) {
  echo "  #{$t->id()} [{$t->get('status')->value}] {$t->label()}\n";
}

// Max position
$max = 0;
foreach (\Drupal::entityTypeManager()->getStorage('ab_task')->loadMultiple($ids) as $t) {
  $p = (int) $t->get('position')->value;
  if ($p > $max) { $max = $p; }
}
echo "max_position=$max\n";
