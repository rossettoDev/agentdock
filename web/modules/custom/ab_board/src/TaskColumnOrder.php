<?php

declare(strict_types=1);

namespace Drupal\ab_board;

/**
 * Places one task among integer positions with room between them.
 *
 * A gap that cannot fit a new integer rebalances the whole column.
 */
final class TaskColumnOrder {

  /**
   * Space left between tasks when the column is rewritten.
   */
  public const GAP = 1024;

  /**
   * Chooses the position at an index, or a full new sequence.
   *
   * @param int[] $neighbors
   *   Positions of the other tasks, already in display order.
   * @param int $index
   *   Index where the moved task should sit.
   *
   * @return array{position: int, rebalance: int[]}
   *   The single position, or a rebalance that includes the moved task.
   */
  public function place(array $neighbors, int $index): array {
    $count = count($neighbors);
    if ($index < 0 || $index > $count) {
      throw new \InvalidArgumentException('The position is outside the column.');
    }
    $prev = $index > 0 ? $neighbors[$index - 1] : NULL;
    $next = $index < $count ? $neighbors[$index] : NULL;
    if ($prev === NULL && $next === NULL) {
      return ['position' => self::GAP, 'rebalance' => []];
    }
    if ($prev === NULL && $next !== NULL) {
      $position = $next - self::GAP;
      if ($position >= 0) {
        return ['position' => $position, 'rebalance' => []];
      }
    }
    elseif ($next === NULL && $prev !== NULL) {
      return ['position' => $prev + self::GAP, 'rebalance' => []];
    }
    elseif ($prev !== NULL && $next !== NULL && ($next - $prev) >= 2) {
      return [
        'position' => $prev + intdiv($next - $prev, 2),
        'rebalance' => [],
      ];
    }
    $rebalance = [];
    $total = $count + 1;
    for ($i = 0; $i < $total; $i++) {
      $rebalance[] = ($i + 1) * self::GAP;
    }
    return ['position' => $rebalance[$index], 'rebalance' => $rebalance];
  }

}
