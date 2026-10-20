<?php

declare(strict_types=1);

namespace Drupal\ab_event;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Claims outbox rows and publishes each event once per destination.
 *
 * The claim locks the row with SKIP LOCKED, so a second worker takes a
 * different row. The publication table rejects a duplicate effect.
 */
class OutboxProcessor {

  /**
   * Attempts before a row waits for an operator to requeue it.
   */
  public const MAX_ATTEMPTS = 5;

  /**
   * Seconds a claimed row stays hidden while this worker publishes it.
   */
  public const CLAIM_TIMEOUT = 30;

  /**
   * Rows claimed on one pass.
   */
  public const BATCH = 20;

  /**
   * First retry delay, in seconds.
   */
  public const BACKOFF_BASE = 15;

  /**
   * Longest retry delay, in seconds.
   */
  public const BACKOFF_MAX = 600;

  /**
   * Destinations keyed by name.
   *
   * @var array<string, \Drupal\ab_event\OutboxDestinationInterface>
   */
  private array $destinations = [];

  /**
   * Collects one handler for each destination name.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The workspace database.
   * @param iterable<mixed> $destinations
   *   Publication handlers.
   */
  public function __construct(
    private readonly Connection $database,
    iterable $destinations,
  ) {
    foreach ($destinations as $destination) {
      if ($destination instanceof OutboxDestinationInterface) {
        $this->destinations[$destination->id()] = $destination;
      }
    }
  }

  /**
   * Publishes every row that is due.
   *
   * @param int $now
   *   UTC unix time of this pass.
   *
   * @return array{delivered: int, failed: int, dead: int}
   *   Counts for this pass.
   */
  public function deliverAvailable(int $now): array {
    $delivered = 0;
    $failed = 0;
    $dead = 0;
    foreach ($this->claim($now, self::BATCH) as $claim) {
      try {
        $this->project($claim, $now);
        $delivered++;
      }
      catch (\Throwable) {
        $this->defer($claim, $now);
        if ($claim['attempts'] >= self::MAX_ATTEMPTS) {
          $dead++;
        }
        else {
          $failed++;
        }
      }
    }
    return [
      'delivered' => $delivered,
      'failed' => $failed,
      'dead' => $dead,
    ];
  }

  /**
   * Seconds until a failed claim may run again.
   */
  public function backoff(int $attempts): int {
    if ($attempts < 1) {
      return self::BACKOFF_BASE;
    }
    $delay = self::BACKOFF_BASE * (2 ** ($attempts - 1));
    return (int) min(self::BACKOFF_MAX, $delay);
  }

  /**
   * Makes a dead row claimable again. A published row stays published.
   */
  public function requeue(string $eventId, string $destination, int $now): bool {
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $eventId) !== 1) {
      throw new \InvalidArgumentException('Event id must be a lowercase UUID.');
    }
    if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $destination) !== 1) {
      throw new \InvalidArgumentException('Outbox destination is invalid.');
    }
    $updated = $this->database->update('ab_outbox')
      ->fields([
        'attempts' => 0,
        'available_at' => $now,
        'last_error' => NULL,
      ])
      ->condition('event_id', $eventId)
      ->condition('destination', $destination)
      ->condition('attempts', self::MAX_ATTEMPTS, '>=')
      ->isNull('processed_at')
      ->execute();
    return (int) $updated === 1;
  }

  /**
   * Age of the oldest unpublished row, or NULL when the queue is empty.
   */
  public function age(int $now): ?int {
    $available = $this->database->query(
      'SELECT MIN(available_at) FROM {ab_outbox} WHERE processed_at IS NULL',
    )->fetchField();
    if ($available === FALSE || $available === NULL) {
      return NULL;
    }
    return max(0, $now - (int) $available);
  }

  /**
   * Locks due rows so another worker skips them.
   *
   * @param int $now
   *   UTC unix time of this pass.
   * @param int $limit
   *   Maximum rows to claim.
   *
   * @return array<int, array{event_id: string, destination: string, attempts: int}>
   *   Claimed rows, with the attempt count already incremented.
   */
  public function claim(int $now, int $limit): array {
    $limit = max(1, min(self::BATCH, $limit));
    $transaction = $this->database->startTransaction();
    try {
      $statement = $this->database->query(
        'SELECT event_id, destination, attempts FROM {ab_outbox} WHERE processed_at IS NULL AND available_at <= :now AND attempts < :max ORDER BY available_at, event_id, destination LIMIT ' . $limit . ' FOR UPDATE SKIP LOCKED',
        [
          ':now' => $now,
          ':max' => self::MAX_ATTEMPTS,
        ],
      );
      $claimed = [];
      foreach ($statement as $row) {
        $eventId = (string) $row->event_id;
        $destination = (string) $row->destination;
        $attempts = ((int) $row->attempts) + 1;
        $updated = $this->database->update('ab_outbox')
          ->fields([
            'attempts' => $attempts,
            'available_at' => $now + self::CLAIM_TIMEOUT,
            'last_error' => NULL,
          ])
          ->condition('event_id', $eventId)
          ->condition('destination', $destination)
          ->isNull('processed_at')
          ->execute();
        if ((int) $updated === 1) {
          $claimed[] = [
            'event_id' => $eventId,
            'destination' => $destination,
            'attempts' => $attempts,
          ];
        }
      }
      $this->duringClaim();
      return $claimed;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Stores the publication and marks the outbox row finished.
   *
   * @param array{event_id: string, destination: string, attempts: int} $claim
   *   Row returned by claim().
   * @param int $now
   *   UTC unix time stored on the finished row.
   */
  public function project(array $claim, int $now): void {
    $transaction = $this->database->startTransaction();
    try {
      $event = $this->load($claim['event_id']);
      if ($event === NULL) {
        throw new \RuntimeException('The event is missing.');
      }
      $destination = $this->destinations[$claim['destination']] ?? NULL;
      if (!$destination instanceof OutboxDestinationInterface) {
        throw new \RuntimeException('The destination is unknown.');
      }
      try {
        $destination->deliver($event);
      }
      catch (\Throwable $exception) {
        if (!$this->alreadyDelivered($exception)) {
          throw $exception;
        }
      }
      $this->database->update('ab_outbox')
        ->fields([
          'processed_at' => $now,
          'last_error' => NULL,
        ])
        ->condition('event_id', $claim['event_id'])
        ->condition('destination', $claim['destination'])
        ->isNull('processed_at')
        ->execute();
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Keeps a failed row unpublished and schedules the next attempt.
   *
   * The stored error is a fixed sentence. Exception text can carry a
   * secret, so it is never written.
   *
   * @param array{event_id: string, destination: string, attempts: int} $claim
   *   Row returned by claim().
   * @param int $now
   *   UTC unix time the backoff is counted from.
   */
  public function defer(array $claim, int $now): void {
    $fields = [
      'last_error' => 'Delivery failed.',
    ];
    if ($claim['attempts'] < self::MAX_ATTEMPTS) {
      $fields['available_at'] = $now + $this->backoff($claim['attempts']);
    }
    $this->database->update('ab_outbox')
      ->fields($fields)
      ->condition('event_id', $claim['event_id'])
      ->condition('destination', $claim['destination'])
      ->isNull('processed_at')
      ->execute();
  }

  /**
   * Runs while the claim transaction still holds the row locks.
   */
  protected function duringClaim(): void {
  }

  /**
   * Loads the event identity. The payload stays in the table.
   *
   * @return array<string, string>|null
   *   Identity fields, or NULL when the event rolled back.
   */
  private function load(string $eventId): ?array {
    $row = $this->database->query(
      'SELECT event_id, workspace_id, sequence, type, resource_id, actor_type, actor_id, created FROM {ab_event} WHERE event_id = :id',
      [':id' => $eventId],
    )->fetchAssoc();
    if (!is_array($row) || !is_string($row['event_id'])) {
      return NULL;
    }
    return [
      'event_id' => (string) $row['event_id'],
      'workspace_id' => (string) $row['workspace_id'],
      'sequence' => (string) $row['sequence'],
      'type' => (string) $row['type'],
      'resource_id' => (string) $row['resource_id'],
      'actor_type' => (string) $row['actor_type'],
      'actor_id' => (string) $row['actor_id'],
      'created' => (string) $row['created'],
    ];
  }

  /**
   * Reports a unique violation from this insert or a savepoint wrapper.
   */
  private function alreadyDelivered(\Throwable $exception): bool {
    $current = $exception;
    while ($current !== NULL) {
      if ($current instanceof IntegrityConstraintViolationException) {
        return TRUE;
      }
      $next = $current->getPrevious();
      $current = $next instanceof \Throwable ? $next : NULL;
    }
    return FALSE;
  }

}
