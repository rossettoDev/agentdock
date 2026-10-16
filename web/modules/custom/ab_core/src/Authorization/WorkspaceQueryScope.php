<?php

declare(strict_types=1);

namespace Drupal\ab_core\Authorization;

use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\ConditionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Restricts entity queries to workspaces visible to the account.
 */
final class WorkspaceQueryScope {

  public function __construct(
    private readonly MembershipScope $scope,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Limits an ab_workspace query to visible workspaces.
   */
  public function alterWorkspace(AlterableInterface $query): void {
    $this->restrict($query, 'base_table.id');
  }

  /**
   * Limits an ab_membership query to visible workspaces.
   */
  public function alterMembership(AlterableInterface $query): void {
    $this->restrict($query, 'base_table.workspace');
  }

  /**
   * Limits an ab_board query to visible workspaces.
   */
  public function alterBoard(AlterableInterface $query): void {
    $this->restrict($query, 'base_table.workspace');
  }

  /**
   * Limits an ab_task query to visible workspaces.
   */
  public function alterTask(AlterableInterface $query): void {
    $this->restrict($query, 'base_table.workspace');
  }

  /**
   * Limits an ab_agent query to visible workspaces.
   */
  public function alterAgent(AlterableInterface $query): void {
    $this->restrict($query, 'base_table.workspace');
  }

  /**
   * Adds the workspace condition for the query account.
   */
  private function restrict(AlterableInterface $query, string $field): void {
    if (!$query instanceof ConditionInterface) {
      return;
    }
    $account = $query->getMetaData('account');
    if (!$account instanceof AccountInterface) {
      $account = $this->currentUser->getAccount();
    }
    $ids = $this->scope->activeWorkspaceIds($account);
    if ($ids === []) {
      $query->condition($field, 0);
      return;
    }
    $query->condition($field, $ids, 'IN');
  }

}
