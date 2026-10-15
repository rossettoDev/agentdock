<?php

declare(strict_types=1);

namespace Drupal\ab_event;

/**
 * Records durable events inside the caller's database transaction.
 *
 * Lock order for domain services: lock domain rows first, then call record().
 * This store then locks ab_workspace_sequence, inserts ab_event and inserts
 * ab_outbox. It never locks a domain row after the sequence counter.
 */
interface EventStoreInterface {

  /**
   * Outbox destination consumed by later synchronization.
   */
  public const DESTINATION_SYNC = 'sync';

  /**
   * Reserves the next workspace sequence and writes the event plus outbox.
   *
   * The caller must already have an open transaction. This method does not
   * commit. Rollback of that transaction removes the sequence reservation,
   * the event and the outbox rows.
   *
   * @throws \Drupal\ab_event\Exception\TransactionRequiredException
   *   There is no active transaction.
   * @throws \InvalidArgumentException
   *   The draft is not valid.
   */
  public function record(EventDraft $draft): StoredEvent;

}
