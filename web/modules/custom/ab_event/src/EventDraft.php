<?php

declare(strict_types=1);

namespace Drupal\ab_event;

/**
 * Data required to record one workspace event.
 */
final class EventDraft {

  private const ACTOR_TYPES = ['human', 'agent', 'system'];

  private const SENSITIVE_KEYS = [
    'authorization',
    'lease_token',
    'password',
    'secret',
    'token',
  ];

  public function __construct(
    public readonly string $workspaceId,
    public readonly string $type,
    public readonly string $resourceId,
    public readonly string $actorType,
    public readonly string $actorId,
    public readonly array $payload = [],
    public readonly array $destinations = [EventStoreInterface::DESTINATION_SYNC],
    public readonly ?string $eventId = NULL,
  ) {
    $this->assertUuid($workspaceId, 'workspaceId');
    $this->assertUuid($resourceId, 'resourceId');
    if ($eventId !== NULL) {
      $this->assertUuid($eventId, 'eventId');
    }
    $this->assertType($type);
    $this->assertActor($actorType, $actorId);
    $this->assertDestinations($destinations);
    $this->assertPayload($payload);
  }

  /**
   * Rejects values that are not canonical UUID strings.
   */
  private function assertUuid(string $value, string $field): void {
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) !== 1) {
      throw new \InvalidArgumentException(sprintf('%s must be a lowercase UUID.', $field));
    }
  }

  /**
   * Rejects event types outside the dotted vocabulary.
   */
  private function assertType(string $type): void {
    if (strlen($type) > 64 || preg_match('/^[a-z][a-z0-9]*(\.[a-z][a-z0-9_]*)+$/', $type) !== 1) {
      throw new \InvalidArgumentException('Event type must be a dotted lowercase name.');
    }
  }

  /**
   * Rejects unknown actor kinds and empty identifiers.
   */
  private function assertActor(string $actorType, string $actorId): void {
    if (!in_array($actorType, self::ACTOR_TYPES, TRUE)) {
      throw new \InvalidArgumentException('Actor type must be human, agent or system.');
    }
    if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $actorId) !== 1) {
      throw new \InvalidArgumentException('Actor id must be an opaque identifier.');
    }
  }

  /**
   * Rejects an empty or duplicated destination list.
   *
   * @param string[] $destinations
   *   Requested outbox destinations.
   */
  private function assertDestinations(array $destinations): void {
    if ($destinations === []) {
      throw new \InvalidArgumentException('At least one outbox destination is required.');
    }
    foreach ($destinations as $destination) {
      if (!is_string($destination) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $destination) !== 1) {
        throw new \InvalidArgumentException('Outbox destination is invalid.');
      }
    }
    if (count($destinations) !== count(array_unique($destinations))) {
      throw new \InvalidArgumentException('Outbox destinations must be unique.');
    }
  }

  /**
   * Rejects non-JSON values and secret-bearing keys.
   *
   * @param array<mixed> $payload
   *   Payload fragment.
   */
  private function assertPayload(array $payload): void {
    foreach ($payload as $key => $value) {
      if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, TRUE)) {
        throw new \InvalidArgumentException(sprintf('Payload key "%s" is not allowed.', $key));
      }
      if (is_array($value)) {
        $this->assertPayload($value);
        continue;
      }
      if ($value !== NULL && !is_scalar($value)) {
        throw new \InvalidArgumentException('Payload values must be scalars or arrays.');
      }
    }
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
    if (!is_string($encoded)) {
      throw new \InvalidArgumentException('Payload must encode as JSON.');
    }
  }

}
