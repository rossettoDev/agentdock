<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * The agent token cannot perform the operation.
 *
 * The message stays generic so a denial never echoes the secret.
 */
final class AgentAccessDeniedException extends \RuntimeException {

  public function __construct() {
    parent::__construct('The agent cannot perform that operation.');
  }

}
