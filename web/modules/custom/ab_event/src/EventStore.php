<?php

declare(strict_types=1);

namespace Drupal\ab_event;

use Drupal\ab_event\Exception\TransactionRequiredException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Reserves a per-workspace sequence and writes the event store.
 *
 * Lock order inside one caller transaction:
 * 1. ab_workspace_sequence for the workspace, SELECT FOR UPDATE.
 * 2. INSERT ab_event.
 * 3. INSERT ab_outbox, destinations sorted by name.
 */
final class EventStore implements EventStoreInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function record(EventDraft $draft): StoredEvent {
    if (!$this->database->inTransaction()) {
      throw new TransactionRequiredException('Event recording requires the caller transaction.');
    }

    $sequence = $this->reserveSequence($draft->workspaceId);
    $eventId = $draft->eventId ?? $this->uuid->generate();
    $created = $this->time->getCurrentTime();
    $payload = json_encode($draft->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
      throw new \InvalidArgumentException('Payload must encode as JSON.');
    }

    $this->database->insert('ab_event')
      ->fields([
        'event_id' => $eventId,
        'workspace_id' => $draft->workspaceId,
        'sequence' => $sequence,
        'type' => $draft->type,
        'resource_id' => $draft->resourceId,
        'actor_type' => $draft->actorType,
        'actor_id' => $draft->actorId,
        'created' => $created,
        'payload' => $payload,
      ])
      ->execute();

    $destinations = $draft->destinations;
    sort($destinations, SORT_STRING);
    foreach ($destinations as $destination) {
      $this->database->insert('ab_outbox')
        ->fields([
          'event_id' => $eventId,
          'destination' => $destination,
          'attempts' => 0,
          'available_at' => $created,
          'processed_at' => NULL,
          'last_error' => NULL,
        ])
        ->execute();
    }

    return new StoredEvent($eventId, $draft->workspaceId, $sequence, $created);
  }

  /**
   * Locks the workspace counter and returns the next sequence.
   */
  private function reserveSequence(string $workspaceId): int {
    for ($attempt = 0; $attempt < 3; $attempt++) {
      $current = $this->lockedSequence($workspaceId);
      if ($current !== NULL) {
        $next = $current + 1;
        $this->database->update('ab_workspace_sequence')
          ->fields(['sequence' => $next])
          ->condition('workspace_id', $workspaceId)
          ->execute();
        return $next;
      }
      try {
        $this->database->insert('ab_workspace_sequence')
          ->fields([
            'workspace_id' => $workspaceId,
            'sequence' => 0,
          ])
          ->execute();
      }
      catch (IntegrityConstraintViolationException) {
        // The competing transaction owns the new counter row.
      }
    }
    throw new \RuntimeException(sprintf('Could not reserve an event sequence for workspace %s.', $workspaceId));
  }

  /**
   * Returns the locked counter, or NULL when the workspace has no row yet.
   */
  private function lockedSequence(string $workspaceId): ?int {
    $query = $this->database->select('ab_workspace_sequence', 's');
    $query->forUpdate();
    $query->fields('s', ['sequence']);
    $query->condition('workspace_id', $workspaceId);
    $statement = $query->execute();
    if ($statement === NULL) {
      return NULL;
    }
    $current = $statement->fetchField();
    if ($current === FALSE) {
      return NULL;
    }
    return (int) $current;
  }

}
