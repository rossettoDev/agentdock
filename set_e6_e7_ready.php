<?php
$board_uuid = '27f43bb5-e88e-4f15-98b0-37964440d701';
$boards = \Drupal::entityTypeManager()->getStorage('ab_board')->loadByProperties(['uuid' => $board_uuid]);
$board = $boards ? reset($boards) : NULL;
if (!$board) { throw new \RuntimeException('Board not found'); }
$storage = \Drupal::entityTypeManager()->getStorage('ab_task');
$titles = [
  'E6-T01 — Entidades de operação e resultados por site',
  'E6-T02 — Filas, idempotência e retry limitado',
  'E6-T03 — Auditoria (drm_audit) sem segredos',
  'E7-T01 — Mapeamento de bundles/campos e UUIDs (drm_content)',
  'E7-T02 — CRUD seguro, conflitos/revisões e publicação autorizada',
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
