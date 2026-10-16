<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * A grant leaves the agent workspace or the allowed permissions.
 */
final class AgentGrantRejectedException extends \RuntimeException {

  public const BOARD = 'The board is outside the agent workspace.';

  public const PERMISSION = 'That permission is outside the workspace.';

  public const MISSING = 'That grant is not stored.';

}
