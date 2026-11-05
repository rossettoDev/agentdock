<?php
$board_uuid = '27f43bb5-e88e-4f15-98b0-37964440d701';
$boards = \Drupal::entityTypeManager()->getStorage('ab_board')->loadByProperties(['uuid' => $board_uuid]);
$board = $boards ? reset($boards) : NULL;
if (!$board) { throw new \RuntimeException('Board not found'); }
$storage = \Drupal::entityTypeManager()->getStorage('ab_task');
$titles = [
  'E5-T01 — API Agent: catálogo e mutação allowlisted de roles',
  'E5-T02 — Preview/dry-run e comparação de roles entre sites',
  'E5-T03 — UI de roles e testes de privilege escalation',
];
$ids = $storage->getQuery()->accessCheck(FALSE)->condition('board', $board->id())->condition('title', $titles, 'IN')->execute();
echo "matched=" . count($ids) . "\n";
foreach ($storage->loadMultiple($ids) as $t) {
  $old = $t->get('status')->value;
  $t->set('status', 'ready');
  $t->save();
  echo "UPDATED|#{$t->id()}|{$old}->ready|{$t->label()}\n";
}
echo "DONE\n";
