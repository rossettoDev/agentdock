<?php

declare(strict_types=1);

namespace Drupal\ab_event\Drush\Commands;

use Drupal\ab_event\OutboxProcessor;
use Drupal\ab_event\OutboxWorker;
use Drupal\ab_event\WorkerTick;
use Drupal\Component\Datetime\TimeInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Supervises outbox publication, lease recovery and retention.
 */
final class OutboxWorkerCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly OutboxWorker $worker,
    private readonly OutboxProcessor $processor,
    private readonly TimeInterface $time,
  ) {
    parent::__construct();
  }

  /**
   * Publishes the outbox and recovers expired leases until stopped.
   */
  #[CLI\Command(name: 'ab:work', aliases: ['ab-work'])]
  #[CLI\Option(name: 'interval', description: 'Seconds between passes.')]
  #[CLI\Option(name: 'memory', description: 'Stop after this many allocated bytes.')]
  #[CLI\Option(name: 'once', description: 'Run one pass and exit.')]
  public function work(
    array $options = [
      'interval' => OutboxWorker::INTERVAL,
      'memory' => OutboxWorker::MEMORY,
      'once' => FALSE,
    ],
  ): void {
    $interval = (int) $options['interval'];
    $memory = (int) $options['memory'];
    if ($interval < 1 || $interval > 3600) {
      throw new \InvalidArgumentException('Interval must be between 1 and 3600 seconds.');
    }
    if ($memory < 33554432) {
      throw new \InvalidArgumentException('Memory limit must be at least 32 MiB.');
    }
    $this->listen();
    $once = !empty($options['once']);
    $this->worker->run($interval, $memory, $once ? 1 : 0, function (WorkerTick $tick): void {
      $this->logger()?->notice(
        'delivered={delivered} failed={failed} dead={dead} maintenance={maintenance} purged_events={purged} purged_outbox={feed} outbox_age={age}',
        [
          'delivered' => $tick->delivered,
          'failed' => $tick->failed,
          'dead' => $tick->dead,
          'maintenance' => $tick->maintenance,
          'purged' => $tick->purgedEvents,
          'feed' => $tick->purgedOutbox,
          'age' => $tick->outboxAge === NULL ? 'none' : (string) $tick->outboxAge,
        ],
      );
    });
  }

  /**
   * Returns a dead outbox row to the queue.
   */
  #[CLI\Command(name: 'ab:outbox-requeue', aliases: ['ab-outbox-requeue'])]
  #[CLI\Argument(name: 'event_id', description: 'Event UUID.')]
  #[CLI\Argument(name: 'destination', description: 'Outbox destination.')]
  public function requeue(string $event_id, string $destination): void {
    $done = $this->processor->requeue($event_id, $destination, $this->time->getCurrentTime());
    if (!$done) {
      throw new \RuntimeException('That outbox row is not waiting to be retried.');
    }
    $this->logger()?->notice('Requeued {event_id} for {destination}.', [
      'event_id' => $event_id,
      'destination' => $destination,
    ]);
  }

  /**
   * Stops the loop on SIGTERM and SIGINT after the current pass.
   */
  private function listen(): void {
    if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
      return;
    }
    pcntl_async_signals(TRUE);
    $stop = function (): void {
      $this->worker->requestStop();
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
  }

}
