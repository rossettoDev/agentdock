<?php
$board_uuid = '27f43bb5-e88e-4f15-98b0-37964440d701';
$boards = \Drupal::entityTypeManager()->getStorage('ab_board')->loadByProperties(['uuid' => $board_uuid]);
$board = $boards ? reset($boards) : NULL;
if (!$board) {
  throw new \RuntimeException('Board not found');
}

$task_defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('ab_task', 'ab_task');
$status_def = $task_defs['status'];
$settings = $status_def->getSettings();
echo "status allowed:\n";
print_r($settings['allowed_values'] ?? $settings);

$titles = [
  'E1-T01 — Inspeção do repositório e plano da árvore DRM',
  'E1-T02 — Contratos: CapabilityInterface, DTOs, schemas JSON e erros',
  'E1-T03 — Scaffold dos módulos (.info.yml, services) e testes do núcleo',
  'E1-T04 — Documentar versionamento da API e política de compatibilidade',
];

$storage = \Drupal::entityTypeManager()->getStorage('ab_task');
$ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('board', $board->id())
  ->condition('title', $titles, 'IN')
  ->execute();

echo "matched=" . count($ids) . "\n";
$new_status = 'ready';
foreach ($storage->loadMultiple($ids) as $t) {
  $old = $t->get('status')->value;
  $t->set('status', $new_status);
  $t->save();
  echo "UPDATED|#{$t->id()}|{$old}->{$t->get('status')->value}|{$t->label()}\n";
}
echo "DONE\n";
