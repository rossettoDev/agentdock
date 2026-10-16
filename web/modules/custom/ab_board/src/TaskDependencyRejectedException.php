<?php

declare(strict_types=1);

namespace Drupal\ab_board;

/**
 * Raised when a task dependency breaks the board or cycle rules.
 *
 * The message is one of self, cycle, board, duplicate or missing.
 */
final class TaskDependencyRejectedException extends \RuntimeException {

  public const SELF = 'self';

  public const CYCLE = 'cycle';

  public const BOARD = 'board';

  public const DUPLICATE = 'duplicate';

  public const MISSING = 'missing';

}
