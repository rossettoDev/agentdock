<?php
$board_uuid = '27f43bb5-e88e-4f15-98b0-37964440d701';
$boards = \Drupal::entityTypeManager()->getStorage('ab_board')->loadByProperties(['uuid' => $board_uuid]);
$board = $boards ? reset($boards) : NULL;
if (!$board) { throw new \RuntimeException('Board not found'); }
$storage = \Drupal::entityTypeManager()->getStorage('ab_task');
$defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('ab_task', 'ab_task');
echo "fields:\n";
foreach ($defs as $name => $def) {
  echo "  $name (" . $def->getType() . ")\n";
}
$ids = $storage->getQuery()->accessCheck(FALSE)->condition('board', $board->id())->sort('position')->execute();
echo "count=" . count($ids) . "\n";
foreach ($storage->loadMultiple($ids) as $t) {
  $line = "#{$t->id()}|{$t->get('status')->value}|{$t->label()}";
  foreach (['assignee', 'claimed_by', 'claim', 'agent', 'owner', 'locked', 'lease', 'worker'] as $f) {
    if ($t->hasField($f) && !$t->get($f)->isEmpty()) {
      $val = $t->get($f)->getValue();
      $line .= "|$f=" . json_encode($val, JSON_UNESCAPED_UNICODE);
    }
  }
  // dump non-empty interesting base fields
  foreach (['uid', 'changed', 'created'] as $f) {
    if ($t->hasField($f) && !$t->get($f)->isEmpty()) {
      try {
        $v = $t->get($f)->value ?? $t->get($f)->target_id ?? null;
        if ($v !== null) $line .= "|$f=$v";
      } catch (\Throwable $e) {}
    }
  }
  echo $line . "\n";
}

// Also list ab_grant / agents if any
foreach (['ab_agent', 'ab_grant', 'ab_claim', 'ab_lease'] as $et) {
  if (\Drupal::entityTypeManager()->hasDefinition($et)) {
    $s = \Drupal::entityTypeManager()->getStorage($et);
    $all = $s->getQuery()->accessCheck(FALSE)->range(0, 20)->execute();
    echo "$et count_sample=" . count($all) . "\n";
    foreach ($s->loadMultiple($all) as $e) {
      echo "  {$et}#{$e->id()}|" . $e->label() . "\n";
    }
  }
}
