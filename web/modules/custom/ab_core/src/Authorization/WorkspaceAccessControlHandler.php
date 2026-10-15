<?php

declare(strict_types=1);

namespace Drupal\ab_core\Authorization;

use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Grants workspace access from the active membership.
 */
final class WorkspaceAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  public function __construct(
    EntityTypeInterface $entity_type,
    private readonly MembershipScope $scope,
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
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if (!$entity instanceof Workspace || $entity->id() === NULL) {
      return $this->result(FALSE, $account);
    }
    $permission = match ($operation) {
      'view' => WorkspacePermission::READ,
      'update', 'delete' => WorkspacePermission::MANAGE_WORKSPACE,
      default => NULL,
    };
    if ($permission === NULL) {
      return $this->result(FALSE, $account);
    }
    return $this->result($this->scope->allows($account, $entity, $permission), $account);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return $this->result((int) $account->id() > 0, $account);
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
