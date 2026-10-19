<?php

declare(strict_types=1);

namespace Drupal\ab_api;

use Drupal\ab_core\Authorization\ActorContext;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Reserves one idempotency key and replays the stored response.
 *
 * The actor comes from the caller. The unique key is the actor, the
 * token, the workspace, the operation and the resource. The same key
 * with another body returns 409 and does not run the effect.
 */
class IdempotencyService {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly IdempotencySeal $seal,
  ) {}

  /**
   * Runs the effect once and replays its success response.
   *
   * The effect throws when the command fails. Only a success response
   * is stored. A sealed body is ciphertext and expires with the lease.
   *
   * @param \Drupal\ab_core\Authorization\ActorContext $actor
   *   The authenticated actor.
   * @param string $operation
   *   Dotted operation name, such as task.claim.
   * @param string $resourceId
   *   Lowercase UUID of the resource.
   * @param string $idempotencyKey
   *   Client UUID from Idempotency-Key.
   * @param string $requestBody
   *   Raw body used as the hash.
   * @param callable(): \Drupal\ab_api\StoredResponse $effect
   *   The command. It runs only for the reservation holder.
   * @param bool $seal
   *   TRUE when the response contains a lease secret.
   * @param int|null $expiresAt
   *   Lease expiry for a sealed response.
   */
  public function execute(
    ActorContext $actor,
    string $operation,
    string $resourceId,
    string $idempotencyKey,
    string $requestBody,
    callable $effect,
    bool $seal = FALSE,
    ?int $expiresAt = NULL,
  ): StoredResponse {
    $this->assertScope($operation, $resourceId, $idempotencyKey, $requestBody);
    $now = $this->time->getRequestTime();
    if ($seal && ($expiresAt === NULL || $expiresAt <= $now)) {
      throw new ApiException(422, 'invalid', 'A sealed replay needs an expiry.');
    }
    if ($seal) {
      $this->seal->assertConfigured();
    }
    $expires = $seal ? (int) $expiresAt : $now + ApiLimits::RETENTION;
    $hash = hash('sha256', $requestBody);
    $owned = !$this->database->inTransaction();
    $transaction = $owned ? $this->database->startTransaction() : NULL;
    $fresh = FALSE;
    $id = 0;
    try {
      $slot = $this->reserve(
        $actor,
        $operation,
        $resourceId,
        $idempotencyKey,
        $hash,
        $expires,
        $now,
      );
      if (!$slot['fresh']) {
        return $this->replay($slot['row'], $hash);
      }
      $fresh = TRUE;
      $id = $slot['id'];
      $response = $effect();
      if (!$response instanceof StoredResponse) {
        throw new \RuntimeException('Idempotency effect returned an invalid response.');
      }
      $stored = $seal ? $this->seal->encrypt($response->body) : $response->body;
      $this->database->update('ab_idempotency')
        ->fields([
          'status' => 'completed',
          'response_status' => $response->status,
          'response_body' => $stored,
          'sealed' => $seal ? 1 : 0,
          'expires_at' => $expires,
        ])
        ->condition('id', $id)
        ->execute();
      return $response;
    }
    catch (\Throwable $exception) {
      if ($owned && $transaction !== NULL) {
        $transaction->rollBack();
      }
      elseif ($fresh) {
        $this->database->delete('ab_idempotency')
          ->condition('id', $id)
          ->execute();
      }
      throw $exception;
    }
  }

  /**
   * Removes sealed replays when the lease ends.
   */
  public function discardSealed(
    ActorContext $actor,
    string $operation,
    string $resourceId,
  ): void {
    $this->database->delete('ab_idempotency')
      ->condition('workspace', $actor->workspaceId)
      ->condition('actor_id', $actor->actorId)
      ->condition('token_id', $actor->tokenId ?? '')
      ->condition('operation', $operation)
      ->condition('resource_id', $resourceId)
      ->condition('sealed', 1)
      ->execute();
  }

  /**
   * Inserts the reservation or returns the row that already won.
   *
   * @return array{fresh: bool, id: int, row: array<string, mixed>}
   *   The reservation. Row is empty when this call owns it.
   */
  private function reserve(
    ActorContext $actor,
    string $operation,
    string $resourceId,
    string $idempotencyKey,
    string $hash,
    int $expires,
    int $now,
  ): array {
    $this->deleteExpired($actor, $operation, $resourceId, $idempotencyKey, $now);
    try {
      $id = $this->insertPending(
        $actor,
        $operation,
        $resourceId,
        $idempotencyKey,
        $hash,
        $expires,
        $now,
      );
      return ['fresh' => TRUE, 'id' => (int) $id, 'row' => []];
    }
    catch (IntegrityConstraintViolationException) {
      $row = $this->load($actor, $operation, $resourceId, $idempotencyKey);
      if ($row === NULL) {
        throw new ApiException(
          409,
          'idempotency_pending',
          'The idempotency key is already in progress.',
        );
      }
      return ['fresh' => FALSE, 'id' => (int) $row['id'], 'row' => $row];
    }
  }

  /**
   * Returns the stored success response when the body matches.
   *
   * @param array<string, mixed> $row
   *   The completed reservation.
   * @param string $hash
   *   SHA-256 of the raw body.
   */
  private function replay(array $row, string $hash): StoredResponse {
    if (!hash_equals((string) $row['request_hash'], $hash)) {
      throw new ApiException(
        409,
        'idempotency_conflict',
        'The idempotency key was reused with a different request.',
      );
    }
    if ((string) $row['status'] !== 'completed') {
      throw new ApiException(
        409,
        'idempotency_pending',
        'The idempotency key is already in progress.',
      );
    }
    $body = (string) $row['response_body'];
    if ((int) $row['sealed'] === 1) {
      $body = $this->seal->decrypt($body);
    }
    return new StoredResponse((int) $row['response_status'], $body);
  }

  /**
   * Drops an expired reservation so the key can be used again.
   */
  private function deleteExpired(
    ActorContext $actor,
    string $operation,
    string $resourceId,
    string $idempotencyKey,
    int $now,
  ): void {
    $this->database->delete('ab_idempotency')
      ->condition('workspace', $actor->workspaceId)
      ->condition('actor_id', $actor->actorId)
      ->condition('token_id', $actor->tokenId ?? '')
      ->condition('operation', $operation)
      ->condition('resource_id', $resourceId)
      ->condition('idempotency_key', $idempotencyKey)
      ->condition('expires_at', $now, '<=')
      ->execute();
  }

  /**
   * Inserts a pending reservation.
   */
  private function insertPending(
    ActorContext $actor,
    string $operation,
    string $resourceId,
    string $idempotencyKey,
    string $hash,
    int $expires,
    int $now,
  ): string {
    return (string) $this->database->insert('ab_idempotency')
      ->fields([
        'workspace' => $actor->workspaceId,
        'actor_id' => $actor->actorId,
        'token_id' => $actor->tokenId ?? '',
        'operation' => $operation,
        'resource_id' => $resourceId,
        'idempotency_key' => $idempotencyKey,
        'request_hash' => $hash,
        'response_status' => 0,
        'response_body' => '',
        'sealed' => 0,
        'status' => 'pending',
        'expires_at' => $expires,
        'created' => $now,
      ])
      ->execute();
  }

  /**
   * Loads one reservation.
   *
   * @return array<string, mixed>|null
   *   The row, or NULL when the other transaction has not committed.
   */
  private function load(
    ActorContext $actor,
    string $operation,
    string $resourceId,
    string $idempotencyKey,
  ): ?array {
    $row = $this->database->select('ab_idempotency', 'i')
      ->fields('i')
      ->condition('workspace', $actor->workspaceId)
      ->condition('actor_id', $actor->actorId)
      ->condition('token_id', $actor->tokenId ?? '')
      ->condition('operation', $operation)
      ->condition('resource_id', $resourceId)
      ->condition('idempotency_key', $idempotencyKey)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * Rejects a scope that is not a UUID or a dotted operation.
   */
  private function assertScope(
    string $operation,
    string $resourceId,
    string $idempotencyKey,
    string $requestBody,
  ): void {
    $pattern = '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9_]*)+$/';
    if (strlen($operation) > 64 || preg_match($pattern, $operation) !== 1) {
      throw new ApiException(422, 'invalid', 'The operation name is invalid.');
    }
    if (!ApiPath::isUuid($resourceId) || !ApiPath::isUuid($idempotencyKey)) {
      throw new ApiException(422, 'invalid', 'The resource and key must be UUIDs.');
    }
    if (strlen($requestBody) > ApiLimits::BODY) {
      throw new ApiException(413, 'body_too_large', ProblemDetails::fixedDetail(413));
    }
  }

}
