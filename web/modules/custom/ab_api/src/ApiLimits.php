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

  /**
   * Requests one credential may make in a minute.
   *
   * Heartbeat every 30 seconds and a poll fit in this budget.
   */
  public const RATE_MAX = 120;

  /**
   * Message creates one credential may make in a minute.
   *
   * Kept apart from the general budget so a message loop cannot
   * spend the heartbeat allowance.
   */
  public const MESSAGE_RATE_MAX = 20;

  /**
   * Length of one rate window, in seconds.
   */
  public const RATE_WINDOW = 60;

}
