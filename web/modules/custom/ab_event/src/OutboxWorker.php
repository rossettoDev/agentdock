<?php

declare(strict_types=1);

namespace Drupal\ab_event;

use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the outbox, maintenance and purge on one supervised loop.
 *
 * A stop request finishes the current pass and does not start another.
 * Crossing the memory limit does the same, so a supervisor can restart it.
 */
class OutboxWorker {

  /**
   * Seconds between passes.
   */
  public const INTERVAL = 15;

  /**
   * Stop after this many allocated bytes.
   */
  public const MEMORY = 134217728;

  /**
   * Set when the process should leave after the current pass.
   */
  private bool $stop = FALSE;

  /**
   * Collects the passes that run after publication.
   *
   * @param \Drupal\ab_event\OutboxProcessor $processor
   *   Outbox claim and publication.
   * @param \Drupal\ab_event\EventCleaner $cleaner
   *   Retention cleanup.
   * @param iterable<mixed> $passes
   *   Maintenance steps from other modules.
   * @param \Drupal\ab_event\WorkerSleeperInterface $sleeper
   *   Wait between passes.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Clock used for this pass.
   * @param \Psr\Log\LoggerInterface $logger
   *   Log that receives fixed sentences only.
   */
  public function __construct(
    private readonly OutboxProcessor $processor,
    private readonly EventCleaner $cleaner,
    private readonly iterable $passes,
    private readonly WorkerSleeperInterface $sleeper,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Asks the loop to finish the current pass and return.
   */
  public function requestStop(): void {
    $this->stop = TRUE;
  }

  /**
   * Reports whether a stop was requested.
   */
  public function stopping(): bool {
    return $this->stop;
  }

  /**
   * Publishes due events, runs maintenance and purges expired rows.
   */
  public function tick(): WorkerTick {
    $now = $this->time->getCurrentTime();
    $delivery = $this->processor->deliverAvailable($now);
    $maintenance = 0;
    foreach ($this->passes as $pass) {
      if (!$pass instanceof MaintenancePassInterface) {
        continue;
      }
      try {
        $maintenance += $pass->run();
      }
      catch (\Throwable) {
        $this->logger->error('Maintenance pass failed.');
      }
    }
    $purge = $this->cleaner->purge($now);
    return new WorkerTick(
      $delivery['delivered'],
      $delivery['failed'],
      $delivery['dead'],
      $maintenance,
      $purge->events,
      $purge->outbox,
      $this->processor->age($now),
    );
  }

  /**
   * Loops until stop, the tick limit or the memory limit.
   *
   * @param int $interval
   *   Seconds between passes.
   * @param int $memoryLimit
   *   Stop after a pass that passes this many allocated bytes.
   * @param int $maxTicks
   *   Stop after this many passes. Zero means until asked.
   * @param callable $onTick
   *   Receives the counts and no secrets.
   */
  public function run(int $interval, int $memoryLimit, int $maxTicks, callable $onTick): int {
    $completed = 0;
    while (!$this->stop) {
      $tick = $this->tick();
      $onTick($tick);
      $completed++;
      if ($this->stop || ($maxTicks > 0 && $completed >= $maxTicks)) {
        break;
      }
      if (memory_get_usage(TRUE) > $memoryLimit) {
        $this->logger->notice('Worker stopped after reaching the memory limit.');
        break;
      }
      $this->sleeper->wait($interval, fn (): bool => $this->stop);
      if ($this->stop) {
        break;
      }
    }
    return $completed;
  }

}
