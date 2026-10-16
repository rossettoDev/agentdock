<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * The agent is stored but must not start work.
 */
final class AgentUnavailableException extends \RuntimeException {}
