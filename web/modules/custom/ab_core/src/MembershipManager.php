<?php

declare(strict_types=1);

namespace Drupal\ab_core;

use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserInterface;

/**
 * Adds, changes and transfers workspace memberships.
 *
 * An admin cannot grant or remove the owner role. Transfer keeps one owner
 * and commits the membership changes together with the workspace owner.
 */
class MembershipManager {

  /**
   * Roles an administrator may assign.
   */
  private const ASSIGNABLE_ROLES = ['admin', 'member', 'viewer'];

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly MembershipScope $scope,
  ) {}

  /**
   * Lists active members of a workspace the current user can read.
   *
   * @return array<int, array{uuid: string, name: string, role: string}>
   *   Members in a stable order.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The workspace is outside the caller's scope.
   */
  public function listMembers(Workspace $workspace): array {
    $this->assertReadable($workspace);
    $storage = $this->entityTypeManager->getStorage('ab_membership');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('status', 'active')
      ->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $membership) {
      if (!$membership instanceof Membership) {
        continue;
      }
      $rows[] = [
        'uuid' => $membership->uuid(),
        'name' => $this->memberName($membership),
        'role' => $membership->get('role')->getString(),
      ];
    }
    usort($rows, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);
    return $rows;
  }

  /**
   * Adds an existing active user, or reactivates a revoked membership.
   *
   * @throws \InvalidArgumentException
   *   The user or the role cannot be used.
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The workspace is outside the caller's scope.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller cannot manage members, or the role is owner.
   */
  public function addMember(Workspace $workspace, string $username, string $role): Membership {
    $this->assertManage($workspace);
    $this->assertAssignableRole($role);
    $account = $this->loadActiveUser($username);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $existing = $this->membershipFor($workspace, $account);
      if ($existing instanceof Membership && $existing->get('status')->getString() === 'active') {
        throw new \InvalidArgumentException('This user is already a member.');
      }
      if ($existing instanceof Membership) {
        $existing->set('role', $role);
        $existing->set('status', 'active');
        $existing->save();
        return $existing;
      }
      $membership = Membership::create([
        'workspace' => $workspace->id(),
        'user' => $account->id(),
        'role' => $role,
        'status' => 'active',
      ]);
      $membership->save();
      if (!$membership instanceof Membership) {
        throw new \RuntimeException('The membership was not stored.');
      }
      return $membership;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Changes the role of a non-owner membership.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The membership is missing or outside this workspace.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller cannot manage members, or the membership is an owner.
   */
  public function changeRole(Workspace $workspace, string $membership_uuid, string $role): Membership {
    $this->assertManage($workspace);
    $this->assertAssignableRole($role);
    $membership = $this->membershipInWorkspace($workspace, $membership_uuid);
    if ($membership->get('role')->getString() === 'owner') {
      throw new ScopedOperationDeniedException('The owner role cannot be changed here.');
    }
    $membership->set('role', $role);
    $membership->save();
    return $membership;
  }

  /**
   * Revokes a non-owner membership.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The membership is missing or outside this workspace.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller cannot manage members, or the membership is an owner.
   */
  public function remove(Workspace $workspace, string $membership_uuid): void {
    $this->assertManage($workspace);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $membership = $this->membershipInWorkspace($workspace, $membership_uuid);
      if ($membership->get('role')->getString() === 'owner') {
        throw new ScopedOperationDeniedException('The owner cannot be removed.');
      }
      if ($this->activeOwnerCount($workspace) < 1) {
        throw new ScopedOperationDeniedException('The workspace must keep an owner.');
      }
      $membership->set('status', 'revoked');
      $membership->save();
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Transfers ownership and demotes the previous owner in one transaction.
   *
   * @throws \InvalidArgumentException
   *   The account is missing, inactive, or already the owner.
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The workspace is outside the caller's scope.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller cannot transfer ownership.
   */
  public function transferOwnership(Workspace $workspace, string $username): void {
    $this->assertReadable($workspace);
    $account = $this->currentUser->getAccount();
    $can_transfer = $this->scope->allows(
      $account,
      $workspace,
      WorkspacePermission::TRANSFER_OWNERSHIP,
    );
    if (!$can_transfer) {
      throw new ScopedOperationDeniedException('Ownership cannot be transferred.');
    }
    if ($this->isPersonal($workspace)) {
      throw new ScopedOperationDeniedException('The personal workspace cannot be transferred.');
    }
    $next = $this->loadActiveUser($username);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $owners = $this->activeOwnerMemberships($workspace);
      if (count($owners) !== 1) {
        throw new ScopedOperationDeniedException('The workspace must have one owner.');
      }
      $current = $owners[0];
      if ($current->get('user')->getString() === (string) $next->id()) {
        throw new \InvalidArgumentException('This user is already the owner.');
      }
      $target = $this->membershipFor($workspace, $next);
      if ($target instanceof Membership) {
        $target->set('role', 'owner');
        $target->set('status', 'active');
        $target->save();
      }
      else {
        $created = Membership::create([
          'workspace' => $workspace->id(),
          'user' => $next->id(),
          'role' => 'owner',
          'status' => 'active',
        ]);
        $created->save();
      }
      $current->set('role', 'admin');
      $current->save();
      $workspace->setOwnerId((int) $next->id());
      $workspace->save();
      $this->afterTransfer($workspace);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Confirms the transfer left a single owner.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The workspace does not have exactly one owner.
   */
  protected function afterTransfer(Workspace $workspace): void {
    if ($this->activeOwnerCount($workspace) !== 1) {
      throw new ScopedOperationDeniedException('The workspace must have one owner.');
    }
  }

  /**
   * Requires a readable workspace.
   */
  private function assertReadable(Workspace $workspace): void {
    $allowed = $this->scope->allows(
      $this->currentUser->getAccount(),
      $workspace,
      WorkspacePermission::READ,
    );
    if (!$allowed) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
  }

  /**
   * Requires permission to manage members.
   */
  private function assertManage(Workspace $workspace): void {
    $this->assertReadable($workspace);
    $allowed = $this->scope->allows(
      $this->currentUser->getAccount(),
      $workspace,
      WorkspacePermission::MANAGE_MEMBERS,
    );
    if (!$allowed) {
      throw new ScopedOperationDeniedException('Membership cannot be changed.');
    }
  }

  /**
   * Rejects the owner role and unknown roles.
   */
  private function assertAssignableRole(string $role): void {
    if ($role === 'owner') {
      throw new ScopedOperationDeniedException('The owner role cannot be assigned here.');
    }
    if (!in_array($role, self::ASSIGNABLE_ROLES, TRUE)) {
      throw new \InvalidArgumentException('The role is unknown.');
    }
  }

  /**
   * Loads an active user by account name.
   */
  private function loadActiveUser(string $username): UserInterface {
    if ($username === '') {
      throw new \InvalidArgumentException('A membership requires a user.');
    }
    $ids = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $username)
      ->condition('status', 1)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      throw new \InvalidArgumentException('The user was not found.');
    }
    $account = $this->entityTypeManager->getStorage('user')->load((string) reset($ids));
    if (!$account instanceof UserInterface || (int) $account->id() <= 0) {
      throw new \InvalidArgumentException('The user was not found.');
    }
    return $account;
  }

  /**
   * Locks the workspace row for the current transaction.
   */
  private function lockWorkspace(Workspace $workspace): void {
    $locked = $this->database->query('SELECT id FROM {ab_workspace} WHERE id = :id FOR UPDATE', [
      ':id' => $workspace->id(),
    ])->fetchField();
    if ($locked === FALSE) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
  }

  /**
   * Loads the membership of one user in a workspace, if any.
   */
  private function membershipFor(Workspace $workspace, UserInterface $account): ?Membership {
    $storage = $this->entityTypeManager->getStorage('ab_membership');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('user', $account->id())
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $membership = $storage->load((string) reset($ids));
    return $membership instanceof Membership ? $membership : NULL;
  }

  /**
   * Loads a membership that belongs to the given workspace.
   */
  private function membershipInWorkspace(Workspace $workspace, string $membership_uuid): Membership {
    $entity = $this->entityTypeManager->getStorage('ab_membership')->loadByProperties([
      'uuid' => $membership_uuid,
    ]);
    $membership = reset($entity);
    if (!$membership instanceof Membership) {
      throw new ScopedResourceNotFoundException('Membership was not found.');
    }
    if ($membership->get('workspace')->getString() !== (string) $workspace->id()) {
      throw new ScopedResourceNotFoundException('Membership was not found.');
    }
    return $membership;
  }

  /**
   * Returns active owner memberships.
   *
   * @return \Drupal\ab_core\Entity\Membership[]
   *   Owner memberships.
   */
  private function activeOwnerMemberships(Workspace $workspace): array {
    $storage = $this->entityTypeManager->getStorage('ab_membership');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('role', 'owner')
      ->condition('status', 'active')
      ->execute();
    $owners = [];
    foreach ($storage->loadMultiple($ids) as $membership) {
      if ($membership instanceof Membership) {
        $owners[] = $membership;
      }
    }
    return $owners;
  }

  /**
   * Counts active owner memberships.
   */
  private function activeOwnerCount(Workspace $workspace): int {
    return count($this->activeOwnerMemberships($workspace));
  }

  /**
   * Reports whether the workspace is the personal workspace.
   */
  private function isPersonal(Workspace $workspace): bool {
    return (int) $workspace->get('personal')->getString() === 1;
  }

  /**
   * Returns the account name stored on a membership.
   */
  private function memberName(Membership $membership): string {
    $user_id = $membership->get('user')->getString();
    $account = $this->entityTypeManager->getStorage('user')->load($user_id);
    if ($account instanceof UserInterface) {
      return $account->getAccountName();
    }
    return $user_id;
  }

}
