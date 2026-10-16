<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * The claim lost the race or the task is not available.
 *
 * The message is a stable code and never includes a lease secret.
 */
final class ClaimRejectedException extends \RuntimeException {

  public const STATUS = 'The task is not ready.';

  public const BLOCKED = 'A predecessor is still open.';

  public const ASSIGNED = 'Another agent is assigned.';

  public const LEASE = 'The task already has a lease.';

  public const LIMIT = 'The agent is already at its limit.';

}
