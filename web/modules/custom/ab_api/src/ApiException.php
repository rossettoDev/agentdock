<?php

declare(strict_types=1);

namespace Drupal\ab_api;

/**
 * A safe API failure with a fixed status and code.
 *
 * The detail is written by this module. It is not an exception from
 * the domain and it never carries a stack frame to the client.
 */
final class ApiException extends \RuntimeException {

  public function __construct(
    public readonly int $status,
    public readonly string $errorCode,
    string $detail,
  ) {
    parent::__construct($detail);
  }

}
