<?php

declare(strict_types=1);

namespace Drupal\ab_board;

/**
 * The five columns every board starts with.
 *
 * Cancelled stays a filter. The locked keys belong to claim, submit
 * and human approval, so a drag cannot create them.
 */
final class ColumnDefaults {

  /**
   * Machine keys a person cannot enter by dragging a card.
   */
  public const LOCKED = ['in_progress', 'in_review', 'done'];

  /**
   * Returns the columns stored for a new board, in display order.
   *
   * @return list<array{machine: string, name: string, locked: bool}>
   *   Machine key, Portuguese label and whether drag may enter it.
   */
  public static function definitions(): array {
    return [
      ['machine' => 'backlog', 'name' => 'Backlog', 'locked' => FALSE],
      ['machine' => 'ready', 'name' => 'Pronta', 'locked' => FALSE],
      [
        'machine' => 'in_progress',
        'name' => 'Em andamento',
        'locked' => TRUE,
      ],
      [
        'machine' => 'in_review',
        'name' => 'Em revisão',
        'locked' => TRUE,
      ],
      ['machine' => 'done', 'name' => 'Concluída', 'locked' => TRUE],
    ];
  }

}
