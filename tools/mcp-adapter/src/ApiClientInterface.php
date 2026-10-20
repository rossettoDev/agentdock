<?php

declare(strict_types=1);

namespace McpAdapter;

/**
 * Calls the agent API. Implementations do not open a data store.
 */
interface ApiClientInterface {

  /**
   * Sends one request and returns the status plus a decoded object.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   Path under the API prefix, including the query string.
   * @param string|null $body
   *   Raw JSON body, or NULL for a read.
   * @param array<string, string> $headers
   *   Extra headers. Values are not written to the output.
   *
   * @return array{status: int, json: array<string, mixed>|null}
   *   HTTP status and JSON object, when the body is one.
   */
  public function send(string $method, string $path, ?string $body, array $headers): array;

}
