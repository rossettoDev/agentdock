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
   *
   * Custom columns join this set. The reserved statuses stay out of it.
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
   * Unlocked columns of the board, including ones the creator added,
   * may receive a card. in_progress, in_review and done stay reserved.
   * Done may only be reopened to ready. Cancelled returns to backlog.
   *
   * @param string $from
   *   Stored status.
   * @param string[] $human
   *   Unlocked column keys on the board. The default is the original pair.
   *
   * @return string[]
   *   Status values the human command may write.
   */
  public function targets(string $from, array $human = ['backlog', 'ready']): array {
    if ($from === 'done') {
      return self::HUMAN['done'];
    }
    if (in_array($from, self::RESERVED, TRUE)) {
      return [];
    }
    if ($from === 'cancelled') {
      return self::HUMAN['cancelled'];
    }
    $open = [];
    foreach ($human as $key) {
      if ($key !== $from && !in_array($key, self::RESERVED, TRUE)) {
        $open[] = $key;
      }
    }
    if (!in_array($from, $human, TRUE) && !isset(self::HUMAN[$from])) {
      return [];
    }
    if (!in_array('cancelled', $open, TRUE)) {
      $open[] = 'cancelled';
    }
    return $open;
  }

  /**
   * Reports whether the pair is a human transition.
   *
   * @param string $from
   *   Stored status.
   * @param string $to
   *   Status the person wants to write.
   * @param string[] $human
   *   Unlocked column keys on the board.
   */
  public function allows(string $from, string $to, array $human = ['backlog', 'ready']): bool {
    return in_array($to, $this->targets($from, $human), TRUE);
  }

}
