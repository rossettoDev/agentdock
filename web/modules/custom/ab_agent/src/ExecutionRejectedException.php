<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * The execution mutation is not accepted.
 *
 * The message is fixed and does not include the lease secret.
 */
final class ExecutionRejectedException extends \RuntimeException {

  public const DELIVERY = 'Result delivery is not available yet.';

  public const PROGRESS = 'The execution already has enough progress notes.';

}
