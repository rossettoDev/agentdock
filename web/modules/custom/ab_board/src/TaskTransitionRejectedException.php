<?php

declare(strict_types=1);

namespace Drupal\ab_board;

/**
 * Raised when a human transition is outside the allowed graph.
 */
final class TaskTransitionRejectedException extends \RuntimeException {
}
