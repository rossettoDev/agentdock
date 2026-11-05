<?php
$board_uuid = '27f43bb5-e88e-4f15-98b0-37964440d701';
$boards = \Drupal::entityTypeManager()->getStorage('ab_board')->loadByProperties(['uuid' => $board_uuid]);
$board = $boards ? reset($boards) : NULL;
if (!$board) { throw new \RuntimeException('Board not found'); }
$storage = \Drupal::entityTypeManager()->getStorage('ab_task');
$titles = [
  'E3-T01 — Entidade drm_site, cadastro e teste de conexão',
  'E3-T02 — HTTP client com SSRF, TLS e timeouts',
  'E3-T03 — Dashboard de sites, estado e capabilities',
];
$ids = $storage->getQuery()->accessCheck(FALSE)
  ->condition('board', $board->id())
  ->condition('title', $titles, 'IN')
  ->execute();
echo "matched=" . count($ids) . "\n";
foreach ($storage->loadMultiple($ids) as $t) {
  $old = $t->get('status')->value;
  $t->set('status', 'ready');
  $t->save();
  echo "UPDATED|#{$t->id()}|{$old}->ready|{$t->label()}\n";
}
echo "DONE\n";
