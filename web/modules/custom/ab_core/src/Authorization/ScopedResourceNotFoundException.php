<?php

declare(strict_types=1);

namespace Drupal\ab_core\Authorization;

/**
 * Raised when a UUID is outside the caller's workspace scope.
 *
 * The response matches a missing resource so the UUID does not confirm that
 * the record exists.
 */
final class ScopedResourceNotFoundException extends \RuntimeException {
}
