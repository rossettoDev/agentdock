<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

use Drupal\user\UserInterface;

/**
 * The blocked Drupal account bearer requests run as.
 *
 * The account has no membership and no extra role. It does not log in
 * through the form. The actor context carries the real agent.
 */
final class AgentRuntimeAccount {

  public const NAME = 'ab-agent-runtime';

  /**
   * Loads or creates the blocked runtime account.
   */
  public static function ensure(): UserInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', self::NAME)
      ->execute();
    if ($ids !== []) {
      $account = $storage->load((string) reset($ids));
      if ($account instanceof UserInterface) {
        return $account;
      }
    }
    $account = $storage->create([
      'name' => self::NAME,
      'mail' => 'ab-agent-runtime@example.com',
      'status' => 0,
      'pass' => bin2hex(random_bytes(32)),
    ]);
    $account->save();
    if (!$account instanceof UserInterface) {
      throw new \RuntimeException('The agent runtime account was not stored.');
    }
    return $account;
  }

}
