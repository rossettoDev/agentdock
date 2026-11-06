<?php

declare(strict_types=1);

namespace Drupal\ab_memory;

/**
 * Raised when a write targets a memory version that is no longer current.
 *
 * The HTTP API maps a missing If-Match header to 428 and this exception to
 * 412.
 */
final class MemoryVersionConflictException extends \RuntimeException {
}
