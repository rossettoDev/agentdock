<?php

declare(strict_types=1);

namespace McpAdapter;

/**
 * A JSON-RPC failure that must not include tool arguments.
 */
final class ProtocolException extends \RuntimeException {

  public function __construct(
    public readonly int $rpcCode,
    string $message,
  ) {
    parent::__construct($message);
  }

}
