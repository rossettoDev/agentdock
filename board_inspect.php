<?php
$bid = '27f43bb5-e88e-4f15-98b0-37964440d701';
$storage = \Drupal::entityTypeManager()->getStorage('ab_board');
$board = $storage->load($bid);
if (!$board) {
  $loaded = $storage->loadByProperties(['uuid' => $bid]);
  $board = $loaded ? reset($loaded) : NULL;
}
if (!$board) {
  echo "BOARD_NOT_FOUND\n";
  foreach ($storage->loadMultiple() as $b) {
    echo $b->id() . '|' . $b->uuid() . '|' . $b->label() . "\n";
  }
  exit(0);
}
echo 'FOUND|' . $board->id() . '|' . $board->label() . "\n";
echo 'uuid|' . $board->uuid() . "\n";
echo "fields:\n";
foreach ($board->getFieldDefinitions() as $name => $def) {
  echo "  $name\n";
}
echo "entity types ab_*:\n";
foreach (\Drupal::entityTypeManager()->getDefinitions() as $id => $def) {
  if (str_starts_with($id, 'ab_')) {
    echo "  $id\n";
  }
}
// Also inspect ab_task if exists
if (\Drupal::entityTypeManager()->hasDefinition('ab_task')) {
  $tdef = \Drupal::entityTypeManager()->getDefinition('ab_task');
  echo "ab_task class: " . $tdef->getClass() . "\n";
  $sample = \Drupal::entityTypeManager()->getStorage('ab_task')->getQuery()->accessCheck(FALSE)->range(0,1)->execute();
  if ($sample) {
    $t = \Drupal::entityTypeManager()->getStorage('ab_task')->load(reset($sample));
    echo "sample task fields:\n";
    foreach ($t->getFieldDefinitions() as $name => $def) {
      echo "  $name (" . $def->getType() . ")\n";
    }
    echo "sample status: ";
    try { echo $t->get('status')->value; } catch (\Throwable $e) { echo '?'; }
    echo "\n";
    foreach (['field_status','state','workflow','board','ab_board'] as $cand) {
      if ($t->hasField($cand)) {
        echo "has $cand\n";
      }
    }
  }
}
