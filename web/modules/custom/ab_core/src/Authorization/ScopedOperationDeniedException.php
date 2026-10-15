<?php

declare(strict_types=1);

namespace Drupal\ab_core\Authorization;

/**
 * Raised when the caller can see a resource but cannot change it.
 */
final class ScopedOperationDeniedException extends \RuntimeException {
}
