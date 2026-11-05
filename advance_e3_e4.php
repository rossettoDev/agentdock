<?php
$board_uuid = '27f43bb5-e88e-4f15-98b0-37964440d701';
$boards = \Drupal::entityTypeManager()->getStorage('ab_board')->loadByProperties(['uuid' => $board_uuid]);
$board = $boards ? reset($boards) : NULL;
if (!$board) { throw new \RuntimeException('Board not found'); }
$storage = \Drupal::entityTypeManager()->getStorage('ab_task');

$e3 = [
  'E3-T01 — Entidade drm_site, cadastro e teste de conexão',
  'E3-T02 — HTTP client com SSRF, TLS e timeouts',
  'E3-T03 — Dashboard de sites, estado e capabilities',
];
$e4 = [
  'E4-T01 — API Agent: listagem e detalhe de usuários',
  'E4-T02 — API Agent: criar/atualizar/bloquear usuários',
  'E4-T03 — UI remota de usuários no Controller',
];

$ids3 = $storage->getQuery()->accessCheck(FALSE)->condition('board', $board->id())->condition('title', $e3, 'IN')->execute();
$ids4 = $storage->getQuery()->accessCheck(FALSE)->condition('board', $board->id())->condition('title', $e4, 'IN')->execute();
echo "e3=" . count($ids3) . " e4=" . count($ids4) . "\n";
foreach ($storage->loadMultiple($ids3) as $t) {
  $old = $t->get('status')->value;
  $t->set('status', 'done');
  $t->save();
  echo "E3|#{$t->id()}|{$old}->done|{$t->label()}\n";
}
foreach ($storage->loadMultiple($ids4) as $t) {
  $old = $t->get('status')->value;
  $t->set('status', 'ready');
  $t->save();
  echo "E4|#{$t->id()}|{$old}->ready|{$t->label()}\n";
}
echo "DONE\n";
