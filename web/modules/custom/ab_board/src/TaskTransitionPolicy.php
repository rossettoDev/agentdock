<?php

declare(strict_types=1);

namespace Drupal\ab_board;

/**
 * Human status changes that do not start or imitate an execution.
 *
 * The in_progress and in_review statuses belong to the execution cycle.
 * Done is only a source here: reopening it is human, and approving a
 * delivery is not.
 */
final class TaskTransitionPolicy {

  /**
   * Statuses this command must never write.
   */
  public const RESERVED = ['in_progress', 'in_review', 'done'];

  /**
   * Targets a human may choose from each stored status.
   */
  private const HUMAN = [
    'backlog' => ['ready', 'cancelled'],
    'ready' => ['backlog', 'cancelled'],
    'done' => ['ready'],
    'cancelled' => ['backlog'],
  ];

  /**
   * Returns the human targets for a stored status.
   *
   * @return string[]
   *   Status values the human command may write.
   */
  public function targets(string $from): array {
    return self::HUMAN[$from] ?? [];
  }

  /**
   * Reports whether the pair is a human transition.
   */
  public function allows(string $from, string $to): bool {
    return in_array($to, $this->targets($from), TRUE);
  }

}
