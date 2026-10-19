<?php

declare(strict_types=1);

namespace Drupal\ab_api;

/**
 * Reads the Idempotency-Key header.
 */
final class IdempotencyKey {

  /**
   * Returns the header or refuses the call.
   */
  public static function fromHeader(?string $header): string {
    if ($header === NULL || $header === '') {
      throw new ApiException(
        428,
        'precondition_required',
        'Idempotency-Key is required.',
      );
    }
    if (!ApiPath::isUuid($header)) {
      throw new ApiException(422, 'invalid', 'Idempotency-Key must be a UUID.');
    }
    return $header;
  }

}
