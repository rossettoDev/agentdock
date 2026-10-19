<?php

declare(strict_types=1);

namespace Drupal\ab_api;

/**
 * Reads the shared cursor page size.
 */
final class PageQuery {

  /**
   * Resolves the limit query parameter.
   *
   * A missing value uses the default. A value outside 1..100 is invalid
   * instead of being clamped, so a filter cannot silently widen.
   */
  public static function limit(?string $value): int {
    if ($value === NULL || $value === '') {
      return ApiLimits::PAGE_DEFAULT;
    }
    if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    $limit = (int) $value;
    if ($limit < 1 || $limit > ApiLimits::PAGE_MAX) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return $limit;
  }

}
