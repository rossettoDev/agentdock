<?php

declare(strict_types=1);

namespace Drupal\ab_board\Hook;

use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Authorization\WorkspaceQueryScope;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Query scope and task list cache invalidation.
 */
final class AbTaskScopeHooks {

  public function __construct(
    private readonly WorkspaceQueryScope $queryScope,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Limits task entity queries that opt into access checking.
   */
  #[Hook('query_ab_task_access_alter')]
  public function queryTaskAccessAlter(AlterableInterface $query): void {
    $this->queryScope->alterTask($query);
  }

  /**
   * Drops the cached task list of the affected workspace.
   */
  #[Hook('entity_insert')]
  #[Hook('entity_update')]
  #[Hook('entity_delete')]
  public function invalidateTaskList(EntityInterface $entity): void {
    if (!$entity instanceof Task) {
      return;
    }
    $workspace_id = $entity->get('workspace')->getString();
    if ($workspace_id === '') {
      return;
    }
    $this->cacheTagsInvalidator->invalidateTags(['ab_workspace_tasks:' . $workspace_id]);
  }

}
