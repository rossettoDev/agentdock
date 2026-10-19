<?php

declare(strict_types=1);

namespace Drupal\ab_api;

/**
 * A success response kept for an idempotent replay.
 *
 * There is no string cast. A sealed body is ciphertext until the
 * service decrypts it for the caller.
 */
final class StoredResponse {

  public function __construct(
    public readonly int $status,
    public readonly string $body,
  ) {
    if ($status < 200 || $status > 299) {
      throw new \InvalidArgumentException('Only a success response can be stored.');
    }
  }

}
