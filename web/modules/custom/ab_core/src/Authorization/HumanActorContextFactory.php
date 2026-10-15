<?php

declare(strict_types=1);

namespace Drupal\ab_core\Authorization;

use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Builds the human actor context from the authenticated account.
 */
final class HumanActorContextFactory {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly MembershipScope $scope,
  ) {}

  /**
   * Creates the context for the current user in one workspace.
   *
   * The actor id is the authenticated account. A missing or inactive
   * membership grants nothing.
   */
  public function create(Workspace $workspace): ActorContext {
    $account = $this->currentUser->getAccount();
    $account_id = (string) $account->id();
    return new ActorContext(
      ActorContext::TYPE_HUMAN,
      $account_id === '' ? '0' : $account_id,
      (string) $workspace->uuid(),
      $this->scope->permissions($account, $workspace),
    );
  }

}
