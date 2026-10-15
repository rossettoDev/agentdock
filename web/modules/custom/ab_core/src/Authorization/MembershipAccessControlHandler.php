<?php

declare(strict_types=1);

namespace Drupal\ab_core\Authorization;

use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Grants membership access from the workspace of that membership.
 */
final class MembershipAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  public function __construct(
    EntityTypeInterface $entity_type,
    private readonly MembershipScope $scope,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($entity_type);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get(MembershipScope::class),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $workspace = $this->workspaceOf($entity);
    if (!$workspace instanceof Workspace) {
      return $this->result(FALSE, $account);
    }
    $permission = match ($operation) {
      'view' => WorkspacePermission::READ,
      'update', 'delete' => WorkspacePermission::MANAGE_MEMBERS,
      default => NULL,
    };
    if ($permission === NULL) {
      return $this->result(FALSE, $account);
    }
    return $this->result($this->scope->allows($account, $workspace, $permission), $account);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    $workspace = $context['workspace'] ?? NULL;
    if (!$workspace instanceof Workspace) {
      return $this->result(FALSE, $account);
    }
    return $this->result($this->scope->allows($account, $workspace, WorkspacePermission::MANAGE_MEMBERS), $account);
  }

  /**
   * Loads the workspace referenced by a membership.
   */
  private function workspaceOf(EntityInterface $entity): ?Workspace {
    if (!$entity instanceof Membership) {
      return NULL;
    }
    $workspace_id = $entity->get('workspace')->getString();
    if ($workspace_id === '') {
      return NULL;
    }
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($workspace_id);
    return $workspace instanceof Workspace ? $workspace : NULL;
  }

  /**
   * Builds a cacheable allow or deny result.
   */
  private function result(bool $allowed, AccountInterface $account): AccessResult {
    $result = $allowed ? AccessResult::allowed() : AccessResult::forbidden();
    return $result
      ->addCacheContexts(['user', 'ab_memberships'])
      ->addCacheTags(['ab_membership_list:' . $account->id()]);
  }

}
