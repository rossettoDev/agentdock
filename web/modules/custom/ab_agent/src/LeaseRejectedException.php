<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * The presented lease cannot mutate the execution.
 *
 * The message is a stable code and never includes the lease secret.
 */
final class LeaseRejectedException extends \RuntimeException {

  public const EXPIRED = 'The lease is expired.';

  public const TOKEN = 'The lease token does not match.';

  public const GENERATION = 'The lease generation does not match.';

  public const STATE = 'The execution is not active.';

}
