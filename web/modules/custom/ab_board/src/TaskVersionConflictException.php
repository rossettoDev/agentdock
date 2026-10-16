<?php

declare(strict_types=1);

namespace Drupal\ab_board;

/**
 * Raised when an edit targets a task version that is no longer current.
 */
final class TaskVersionConflictException extends \RuntimeException {
}
