<?php

declare(strict_types=1);

namespace Drupal\ab_event;

use Drupal\Core\Database\Connection;

/**
 * Removes the transient outbox feed and a prefix of expired events.
 *
 * The sequence counter stays. A cursor behind the removed prefix is
 * rejected by the watermark instead of skipping the gap.
 */
class EventCleaner {

  /**
   * Processed outbox rows removed on one pass.
   */
  private const FEED_BATCH = 100;

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Deletes finished outbox rows and expired events up to the limit.
   */
  public function purge(int $now, int $limit = 100): PurgeResult {
    $limit = max(1, min(500, $limit));
    $outbox = $this->purgeFeed();
    $events = 0;
    $workspaces = $this->database->query('SELECT DISTINCT workspace_id FROM {ab_event}')->fetchCol();
    foreach ($workspaces as $workspaceId) {
      if ($events >= $limit || !is_string($workspaceId)) {
        break;
      }
      $events += $this->purgeWorkspace($workspaceId, $now - EventRetention::seconds(), $limit - $events);
    }
    return new PurgeResult($events, $outbox);
  }

  /**
   * Deletes processed outbox rows. The event audit stays.
   */
  private function purgeFeed(): int {
    $statement = $this->database->query(
      'SELECT event_id, destination FROM {ab_outbox} WHERE processed_at IS NOT NULL ORDER BY processed_at LIMIT ' . self::FEED_BATCH,
    );
    $removed = 0;
    foreach ($statement as $row) {
      $removed += (int) $this->database->delete('ab_outbox')
        ->condition('event_id', (string) $row->event_id)
        ->condition('destination', (string) $row->destination)
        ->isNotNull('processed_at')
        ->execute();
    }
    return $removed;
  }

  /**
   * Deletes a contiguous prefix of events older than the cutoff.
   */
  private function purgeWorkspace(string $workspaceId, int $cutoff, int $limit): int {
    $purged = $this->database->query(
      'SELECT purged_sequence FROM {ab_workspace_sequence} WHERE workspace_id = :workspace',
      [':workspace' => $workspaceId],
    )->fetchField();
    if ($purged === FALSE) {
      return 0;
    }
    $watermark = (int) $purged;
    $statement = $this->database->query(
      'SELECT event_id, sequence, created FROM {ab_event} WHERE workspace_id = :workspace AND sequence > :purged ORDER BY sequence LIMIT ' . $limit,
      [
        ':workspace' => $workspaceId,
        ':purged' => $watermark,
      ],
    );
    $rows = $statement->fetchAll();
    $transaction = $this->database->startTransaction();
    try {
      $removed = 0;
      $next = $watermark;
      foreach ($rows as $row) {
        $sequence = (int) $row->sequence;
        if ($sequence !== $next + 1) {
          break;
        }
        if ((int) $row->created >= $cutoff) {
          break;
        }
        $pending = $this->database->query(
          'SELECT 1 FROM {ab_outbox} WHERE event_id = :event AND processed_at IS NULL',
          [':event' => (string) $row->event_id],
        )->fetchField();
        if ($pending !== FALSE) {
          break;
        }
        $this->database->delete('ab_outbox_delivery')
          ->condition('event_id', (string) $row->event_id)
          ->execute();
        $this->database->delete('ab_outbox')
          ->condition('event_id', (string) $row->event_id)
          ->execute();
        $this->database->delete('ab_event')
          ->condition('event_id', (string) $row->event_id)
          ->execute();
        $next = $sequence;
        $removed++;
      }
      if ($next > $watermark) {
        $this->database->update('ab_workspace_sequence')
          ->fields(['purged_sequence' => $next])
          ->condition('workspace_id', $workspaceId)
          ->condition('purged_sequence', $watermark)
          ->execute();
      }
      return $removed;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

}
