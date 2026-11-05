<?php
$board_uuid = '27f43bb5-e88e-4f15-98b0-37964440d701';
$boards = \Drupal::entityTypeManager()->getStorage('ab_board')->loadByProperties(['uuid' => $board_uuid]);
$board = $boards ? reset($boards) : NULL;
if (!$board) { throw new \RuntimeException('Board not found'); }
$storage = \Drupal::entityTypeManager()->getStorage('ab_task');
$ids = $storage->getQuery()->accessCheck(FALSE)
  ->condition('board', $board->id())
  ->condition('status', 'in_review')
  ->execute();
echo "matched=" . count($ids) . "\n";
foreach ($storage->loadMultiple($ids) as $t) {
  $t->set('status', 'done');
  $t->save();
  echo "DONE|#{$t->id()}|{$t->label()}\n";
}
echo "DONE_ALL\n";
