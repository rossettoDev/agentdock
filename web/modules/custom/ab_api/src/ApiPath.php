<?php

declare(strict_types=1);

namespace Drupal\ab_api;

use Symfony\Component\HttpFoundation\Request;

/**
 * Recognizes the versioned API prefix.
 */
final class ApiPath {

  public const PREFIX = '/api/agent-board/v1';

  /**
   * Reports whether the request targets this API.
   */
  public static function matches(Request $request): bool {
    $path = $request->getPathInfo();
    return $path === self::PREFIX || str_starts_with($path, self::PREFIX . '/');
  }

  /**
   * Accepts a lowercase UUID.
   */
  public static function isUuid(string $value): bool {
    $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    return preg_match($pattern, $value) === 1;
  }

}
