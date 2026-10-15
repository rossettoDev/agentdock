<?php

declare(strict_types=1);

namespace Drupal\ab_event\Exception;

/**
 * The caller tried to record an event outside a database transaction.
 */
final class TransactionRequiredException extends \RuntimeException {

}
