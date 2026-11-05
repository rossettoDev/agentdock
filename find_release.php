<?php
$now = \Drupal::time()->getRequestTime();
echo "now=$now\n";
$exec = \Drupal::entityTypeManager()->getStorage('ab_execution')->load(45);
$lease = (int) $exec->get('lease_expires_at')->value;
echo "lease=$lease expired=" . ($lease < $now ? 'yes' : 'no') . " delta=" . ($now - $lease) . "\n";

// Find release/claim services
foreach (\Drupal::getContainer()->getServiceIds() as $id) {
  if (preg_match('/ab_|agent|claim|execution|lease|task/', $id)) {
    if (str_contains($id, 'ab_') || str_contains($id, 'claim') || str_contains($id, 'execution') || str_contains($id, 'lease')) {
      echo "svc|$id\n";
    }
  }
}

// Look for class methods via plugin/module files
$roots = [
  DRUPAL_ROOT . '/modules/custom',
  DRUPAL_ROOT . '/modules/contrib',
];
foreach ($roots as $root) {
  if (!is_dir($root)) continue;
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
  foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $p = $f->getPathname();
    if (!preg_match('/\.(php|yml)$/', $p)) continue;
    if (!preg_match('/ab_/', $p)) continue;
    $base = basename($p);
    if (preg_match('/Claim|Release|Execution|Lease|TaskService|TaskManager/i', $base)) {
      echo "file|$p\n";
    }
  }
}
