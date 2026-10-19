<?php

declare(strict_types=1);

namespace Drupal\ab_communication;

/**
 * The input request cannot be changed.
 *
 * The message is fixed and does not include the question or the answer.
 */
final class InputRequestRejectedException extends \RuntimeException {

  public const ACTOR = 'That actor cannot answer the request.';

  public const STATE = 'The request is not open.';

}
