<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * The execution mutation is not accepted.
 *
 * Result delivery is a later story. This refusal writes nothing.
 */
final class ExecutionRejectedException extends \RuntimeException {

  public const DELIVERY = 'Result delivery is not available yet.';

}
