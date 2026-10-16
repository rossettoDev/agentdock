<?php

declare(strict_types=1);

namespace Drupal\ab_agent\Authorization;

use Drupal\ab_agent\Entity\Execution;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\WorkspacePermission;
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
 * Grants execution reads from the workspace membership.
 *
 * The technical agent account has no membership, so entity access does
 * not authorize a claim. The claim service does that itself.
 */
final class ExecutionAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

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
    if (!$entity instanceof Execution || $entity->id() === NULL || $operation !== 'view') {
      return $this->result(FALSE, $account);
    }
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($entity->get('workspace')->getString());
    if (!$workspace instanceof Workspace) {
      return $this->result(FALSE, $account);
    }
    return $this->result($this->scope->allows($account, $workspace, WorkspacePermission::READ), $account);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return $this->result(FALSE, $account);
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
