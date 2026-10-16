<?php

declare(strict_types=1);

namespace Drupal\ab_agent\PageCache;

use Drupal\Core\PageCache\RequestPolicyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Keeps bearer responses out of the anonymous page cache.
 */
final class DisallowAgentTokenRequests implements RequestPolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function check(Request $request) {
    $header = $request->headers->get('Authorization');
    if (is_string($header) && str_starts_with($header, 'Bearer ')) {
      return self::DENY;
    }
    return NULL;
  }

}
