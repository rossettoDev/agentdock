<?php

declare(strict_types=1);

namespace Drupal\ab_core;

use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Creates one personal workspace and its owner membership.
 */
class PersonalWorkspaceProvisioner implements PersonalWorkspaceProvisionerInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function provision(UserInterface $account): Workspace {
    $this->assertAccount($account);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockAccount($account);
      $existing = $this->findPersonalWorkspace($account);
      if ($existing instanceof Workspace) {
        $this->attachOwnerMembership($existing, $account);
        return $existing;
      }
      $workspace = $this->createPersonalWorkspace($account);
      $this->attachOwnerMembership($workspace, $account);
      return $workspace;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Rejects the anonymous user.
   */
  private function assertAccount(UserInterface $account): void {
    if ((int) $account->id() <= 0) {
      throw new \InvalidArgumentException('A personal workspace requires a user.');
    }
  }

  /**
   * Serializes provisioning for one user.
   */
  private function lockAccount(UserInterface $account): void {
    $locked = $this->database->query('SELECT uid FROM {users} WHERE uid = :uid FOR UPDATE', [
      ':uid' => $account->id(),
    ])->fetchField();
    if ($locked === FALSE) {
      throw new \InvalidArgumentException('A personal workspace requires a stored user.');
    }
  }

  /**
   * Loads the personal workspace already stored for the user.
   */
  private function findPersonalWorkspace(UserInterface $account): ?Workspace {
    $ids = $this->entityTypeManager->getStorage('ab_workspace')->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner', $account->id())
      ->condition('personal', 1)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load(reset($ids));
    return $workspace instanceof Workspace ? $workspace : NULL;
  }

  /**
   * Creates the personal workspace for the user.
   */
  private function createPersonalWorkspace(UserInterface $account): Workspace {
    $workspace = Workspace::create([
      'name' => self::NAME,
      'owner' => $account->id(),
      'timezone' => self::TIMEZONE,
      'status' => 'active',
      'personal' => TRUE,
    ]);
    $workspace->save();
    if (!$workspace instanceof Workspace) {
      throw new EntityStorageException('The personal workspace was not stored.');
    }
    return $workspace;
  }

  /**
   * Stores the owner membership when this workspace does not have one yet.
   */
  protected function attachOwnerMembership(Workspace $workspace, UserInterface $account): Membership {
    $storage = $this->entityTypeManager->getStorage('ab_membership');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('user', $account->id())
      ->range(0, 1)
      ->execute();
    if ($ids !== []) {
      $membership = $storage->load(reset($ids));
      if (!$membership instanceof Membership) {
        throw new EntityStorageException('The owner membership could not be loaded.');
      }
      return $membership;
    }
    $membership = Membership::create([
      'workspace' => $workspace->id(),
      'user' => $account->id(),
      'role' => 'owner',
      'status' => 'active',
    ]);
    $membership->save();
    if (!$membership instanceof Membership) {
      throw new EntityStorageException('The owner membership was not stored.');
    }
    return $membership;
  }

}
