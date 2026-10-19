<?php

declare(strict_types=1);

namespace Drupal\ab_api_probe;

use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Routes used only to prove the API error envelope.
 */
final class ProbeController {

  /**
   * Throws a secret that must not reach the client.
   */
  public function missing(): never {
    throw new ScopedResourceNotFoundException('lease-secret-missing');
  }

  /**
   * Throws a forbidden operation that must not reach the client.
   */
  public function denied(): never {
    throw new ScopedOperationDeniedException('lease-secret-denied');
  }

  /**
   * Throws an unexpected error that must not reach the client.
   */
  public function boom(): never {
    throw new \RuntimeException('lease-secret-boom at /tmp/stack.php:10');
  }

  /**
   * Returns a small JSON document.
   */
  public function ok(): JsonResponse {
    return new JsonResponse(['data' => ['ok' => TRUE]]);
  }

}
