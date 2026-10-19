<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * The human review command does not apply to this task.
 *
 * The message is fixed and does not include a lease secret.
 */
final class TaskReviewRejectedException extends \RuntimeException {

  public const STATUS = 'The task is not waiting for that review.';

}
