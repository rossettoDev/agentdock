<?php

declare(strict_types=1);

namespace Drupal\ab_event;

/**
 * Installs event tables that are missing from an existing site.
 */
final class EventSchemaInstaller {

  /**
   * Creates hook_schema() tables that are not already present.
   *
   * @return string
   *   Update message describing what was created.
   */
  public static function installMissing(): string {
    $module_handler = \Drupal::moduleHandler();
    $module_handler->loadInclude('ab_event', 'install');
    $tables = $module_handler->invoke('ab_event', 'schema');
    if (!is_array($tables)) {
      throw new \RuntimeException('ab_event schema is unavailable.');
    }

    $schema = \Drupal::database()->schema();
    $created = [];
    foreach ($tables as $name => $table) {
      if (!is_string($name) || !is_array($table)) {
        throw new \RuntimeException('ab_event schema entry is invalid.');
      }
      if (!$schema->tableExists($name)) {
        $schema->createTable($name, $table);
        $created[] = $name;
      }
    }

    if ($created === []) {
      return 'Event schema already present.';
    }
    return 'Created tables: ' . implode(', ', $created) . '.';
  }

}
