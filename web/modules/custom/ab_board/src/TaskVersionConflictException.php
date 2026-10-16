<?php

declare(strict_types=1);

namespace Drupal\ab_board;

/**
 * Raised when a write targets a task version that is no longer current.
 *
 * Forms post the version they rendered. The HTTP API will refuse a missing
 * If-Match header with 428 and map this exception to 412.
 */
final class TaskVersionConflictException extends \RuntimeException {
}
