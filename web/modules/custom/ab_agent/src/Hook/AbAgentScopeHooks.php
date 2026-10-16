<?php

declare(strict_types=1);

namespace Drupal\ab_agent\Hook;

use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_core\Authorization\WorkspaceQueryScope;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Query scope and agent list cache invalidation.
 */
final class AbAgentScopeHooks {

  public function __construct(
    private readonly WorkspaceQueryScope $queryScope,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Limits agent entity queries that opt into access checking.
   */
  #[Hook('query_ab_agent_access_alter')]
  public function queryAgentAccessAlter(AlterableInterface $query): void {
    $this->queryScope->alterAgent($query);
  }

  /**
   * Limits execution entity queries that opt into access checking.
   */
  #[Hook('query_ab_execution_access_alter')]
  public function queryExecutionAccessAlter(AlterableInterface $query): void {
    $this->queryScope->alterExecution($query);
  }

  /**
   * Drops the cached agent list of the affected workspace.
   */
  #[Hook('entity_insert')]
  #[Hook('entity_update')]
  #[Hook('entity_delete')]
  public function invalidateAgentList(EntityInterface $entity): void {
    if (!$entity instanceof Agent || $entity->get('workspace')->isEmpty()) {
      return;
    }
    $this->cacheTagsInvalidator->invalidateTags([
      'ab_workspace_agents:' . $entity->get('workspace')->getString(),
    ]);
  }

}
