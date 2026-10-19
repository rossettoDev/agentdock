<?php

declare(strict_types=1);

namespace Drupal\ab_communication;

/**
 * The message was refused before it was stored.
 *
 * The message is fixed and does not include the body.
 */
final class MessageRejectedException extends \RuntimeException {

  public const KIND = 'That message kind is not allowed.';

  public const REPLY = 'The reply is not in this conversation.';

  public const EXECUTION = 'The execution is not part of this conversation.';

  public const MENTION = 'That mention is not allowed.';

  public const BUDGET = 'The execution reached its message budget.';

}
