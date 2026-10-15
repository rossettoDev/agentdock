<?php

declare(strict_types=1);

namespace Drupal\ab_core\Hook;

use Drupal\ab_core\Authorization\WorkspaceQueryScope;
use Drupal\ab_core\Entity\Membership;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Query scope and membership cache invalidation.
 */
final class AbCoreScopeHooks {

  public function __construct(
    private readonly WorkspaceQueryScope $queryScope,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Limits workspace entity queries that opt into access checking.
   */
  #[Hook('query_ab_workspace_access_alter')]
  public function queryWorkspaceAccessAlter(AlterableInterface $query): void {
    $this->queryScope->alterWorkspace($query);
  }

  /**
   * Limits membership entity queries that opt into access checking.
   */
  #[Hook('query_ab_membership_access_alter')]
  public function queryMembershipAccessAlter(AlterableInterface $query): void {
    $this->queryScope->alterMembership($query);
  }

  /**
   * Drops the cached workspace list of the affected user.
   */
  #[Hook('entity_insert')]
  #[Hook('entity_update')]
  #[Hook('entity_delete')]
  public function invalidateMembershipList(EntityInterface $entity): void {
    if (!$entity instanceof Membership) {
      return;
    }
    $uid = $entity->get('user')->getString();
    if ($uid === '') {
      return;
    }
    $this->cacheTagsInvalidator->invalidateTags(['ab_membership_list:' . $uid]);
  }

}
