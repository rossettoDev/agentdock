<?php

declare(strict_types=1);

namespace Drupal\ab_api;

/**
 * Decodes a JSON object body.
 *
 * A list is rejected. The caller supplies the actor, never a field
 * of this object.
 */
final class JsonPayload {

  /**
   * Decodes one JSON object.
   *
   * An empty body is an empty object, which claim uses.
   *
   * @return array<string, mixed>
   *   The decoded object.
   */
  public static function object(string $raw): array {
    if (strlen($raw) > ApiLimits::BODY) {
      throw new ApiException(413, 'body_too_large', ProblemDetails::fixedDetail(413));
    }
    $trimmed = ltrim($raw);
    if ($trimmed === '') {
      return [];
    }
    if ($trimmed[0] !== '{') {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $decoded = json_decode($raw, TRUE, 16);
    if (!is_array($decoded)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return $decoded;
  }

}
