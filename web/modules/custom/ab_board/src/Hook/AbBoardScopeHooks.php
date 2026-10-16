<?php

declare(strict_types=1);

namespace Drupal\ab_board\Hook;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_core\Authorization\WorkspaceQueryScope;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Query scope and board list cache invalidation.
 */
final class AbBoardScopeHooks {

  public function __construct(
    private readonly WorkspaceQueryScope $queryScope,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Limits board entity queries that opt into access checking.
   */
  #[Hook('query_ab_board_access_alter')]
  public function queryBoardAccessAlter(AlterableInterface $query): void {
    $this->queryScope->alterBoard($query);
  }

  /**
   * Drops the cached board list of the affected workspace.
   */
  #[Hook('entity_insert')]
  #[Hook('entity_update')]
  #[Hook('entity_delete')]
  public function invalidateBoardList(EntityInterface $entity): void {
    if (!$entity instanceof Board) {
      return;
    }
    $workspace_id = $entity->get('workspace')->getString();
    if ($workspace_id === '') {
      return;
    }
    $this->cacheTagsInvalidator->invalidateTags(['ab_workspace_boards:' . $workspace_id]);
  }

}
