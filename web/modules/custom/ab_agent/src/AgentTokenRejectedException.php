<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * A token request left the allowed scopes.
 */
final class AgentTokenRejectedException extends \RuntimeException {

  public const SCOPE = 'That scope is outside the token.';

}
