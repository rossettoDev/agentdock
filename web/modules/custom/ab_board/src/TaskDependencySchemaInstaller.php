<?php

declare(strict_types=1);

namespace Drupal\ab_board;

/**
 * Installs the task dependency table on an existing site.
 */
final class TaskDependencySchemaInstaller {

  /**
   * Creates the dependency table when it is missing.
   */
  public static function installMissing(): string {
    $module_handler = \Drupal::moduleHandler();
    $module_handler->loadInclude('ab_board', 'install');
    $tables = $module_handler->invoke('ab_board', 'schema');
    if (!is_array($tables) || !isset($tables['ab_task_dependency']) || !is_array($tables['ab_task_dependency'])) {
      throw new \RuntimeException('Task dependency schema is unavailable.');
    }
    $schema = \Drupal::database()->schema();
    if ($schema->tableExists('ab_task_dependency')) {
      return 'Task dependency schema already present.';
    }
    $schema->createTable('ab_task_dependency', $tables['ab_task_dependency']);
    return 'Created the task dependency table.';
  }

}
