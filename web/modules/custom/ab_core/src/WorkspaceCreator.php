<?php

declare(strict_types=1);

namespace Drupal\ab_core;

use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\user\UserInterface;

/**
 * Creates an ordinary workspace and its owner membership together.
 */
final class WorkspaceCreator {

  /**
   * Timezone offered as the form default.
   */
  public const DEFAULT_TIMEZONE = 'America/Sao_Paulo';

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Creates a workspace owned by the given account.
   *
   * The owner is the account argument. The workspace and the owner membership
   * commit together.
   *
   * @throws \InvalidArgumentException
   *   The account is anonymous.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   The workspace or the membership was rejected.
   */
  public function create(UserInterface $account, string $name, string $timezone): Workspace {
    if ((int) $account->id() <= 0) {
      throw new \InvalidArgumentException('A workspace requires a user.');
    }
    $transaction = $this->database->startTransaction();
    try {
      $workspace = Workspace::create([
        'name' => $name,
        'owner' => $account->id(),
        'timezone' => $timezone,
        'status' => 'active',
        'personal' => FALSE,
      ]);
      $workspace->save();
      if (!$workspace instanceof Workspace) {
        throw new EntityStorageException('The workspace was not stored.');
      }
      $membership = Membership::create([
        'workspace' => $workspace->id(),
        'user' => $account->id(),
        'role' => 'owner',
        'status' => 'active',
      ]);
      $membership->save();
      return $workspace;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

}
