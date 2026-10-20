<?php

declare(strict_types=1);

namespace Drupal\ab_event;

use Drupal\Core\Database\Connection;

/**
 * Reports whether a cursor points at events retention already removed.
 */
final class CursorWindow {

  /**
   * A cursor behind the purge watermark has to take a new snapshot.
   */
  public static function behind(Connection $database, string $workspaceId, int $after): bool {
    $purged = $database->query(
      'SELECT purged_sequence FROM {ab_workspace_sequence} WHERE workspace_id = :workspace',
      [':workspace' => $workspaceId],
    )->fetchField();
    if ($purged === FALSE) {
      return FALSE;
    }
    return $after < (int) $purged;
  }

}
