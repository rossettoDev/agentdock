<?php

declare(strict_types=1);

namespace Drupal\ab_memory;

/**
 * Raised when a memory mutation breaks a domain rule.
 *
 * The HTTP API maps this to 422 without echoing the offending body.
 */
final class MemoryRejectedException extends \RuntimeException {
}
