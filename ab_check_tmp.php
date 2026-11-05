<?php
$etm = \Drupal::entityTypeManager();
$efm = \Drupal::service('entity_field.manager');
foreach (['ab_task','ab_execution','ab_board'] as $t) {
  $f = array_keys($efm->getBaseFieldDefinitions($t));
  echo $t, ': ', implode(',', $f), "\n";
}
$b = $etm->getStorage('ab_board')->loadByProperties(['uuid'=>'27f43bb5-e88e-4f15-98b0-37964440d701']);
$b = reset($b);
echo 'board id ', $b ? $b->id() : 'NONE', "\n";
if ($b) {
  $tasks = $etm->getStorage('ab_task')->loadByProperties(['board'=>$b->id()]);
  foreach ($tasks as $t) { echo $t->id(), ' | ', $t->get('status')->getString(), ' | ', $t->label(), "\n"; }
}
