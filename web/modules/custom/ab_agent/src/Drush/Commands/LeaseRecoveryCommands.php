<?php

declare(strict_types=1);

namespace Drupal\ab_agent\Drush\Commands;

use Drupal\ab_agent\ExecutionMaintenance;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Recovers executions whose lease has expired.
 */
final class LeaseRecoveryCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly ExecutionMaintenance $maintenance,
  ) {
    parent::__construct();
  }

  /**
   * Interrupts executions whose lease has expired.
   */
  #[CLI\Command(name: 'ab-agent:recover-leases', aliases: ['ab-agent-recover-leases'])]
  public function recover(): void {
    $count = $this->maintenance->recover();
    $this->logger()?->notice('Recovered {count} expired leases.', ['count' => $count]);
  }

}
