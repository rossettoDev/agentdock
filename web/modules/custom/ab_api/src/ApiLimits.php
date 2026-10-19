<?php

declare(strict_types=1);

namespace Drupal\ab_api;

/**
 * Sizes the HTTP contract uses for every route.
 */
final class ApiLimits {

  /**
   * Maximum request body, in bytes.
   */
  public const BODY = 262144;

  /**
   * Maximum message body, in bytes.
   */
  public const MESSAGE = 16384;

  /**
   * Default page size.
   */
  public const PAGE_DEFAULT = 50;

  /**
   * Maximum page size.
   */
  public const PAGE_MAX = 100;

  /**
   * How long an unsealed replay is kept, in seconds.
   */
  public const RETENTION = 86400;

}
