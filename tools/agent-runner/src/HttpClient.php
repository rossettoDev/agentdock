<?php

declare(strict_types=1);

namespace AgentRunner;

/**
 * Calls the agent API with a timeout. It does not open a data store.
 */
final class HttpClient {

  public function __construct(
    private readonly string $base,
    private readonly string $token,
    private readonly int $timeout,
    private readonly string $cookie,
  ) {}

  /**
   * Sends one request and returns the status plus a decoded object.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   Path under the API prefix.
   * @param string|null $body
   *   Raw JSON body, or NULL for a read.
   * @param array<string, string> $headers
   *   Extra headers. Values are not written to the output.
   *
   * @return array{status: int, json: array<string, mixed>|null}
   *   HTTP status and JSON object, when the body is one.
   */
  public function send(string $method, string $path, ?string $body, array $headers): array {
    $url = rtrim($this->base, '/') . '/api/agent-board/v1' . $path;
    $lines = [
      'Authorization: Bearer ' . $this->token,
      'Accept: application/json',
      'Expect:',
    ];
    if ($this->cookie !== '') {
      $lines[] = 'Cookie: SIMPLETEST_USER_AGENT=' . $this->cookie;
    }
    if ($body !== NULL) {
      $lines[] = 'Content-Type: application/json';
    }
    foreach ($headers as $name => $value) {
      $lines[] = $name . ': ' . $value;
    }
    $handle = curl_init($url);
    if ($handle === FALSE) {
      throw new \RuntimeException('The HTTP client could not start.');
    }
    $options = [
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HTTPHEADER => $lines,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_TIMEOUT => $this->timeout,
      CURLOPT_CONNECTTIMEOUT => $this->timeout,
      CURLOPT_FOLLOWLOCATION => FALSE,
    ];
    if ($body !== NULL) {
      $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    if ($raw === FALSE) {
      $error = curl_error($handle);
      curl_close($handle);
      throw new \RuntimeException('The HTTP call failed: ' . $error);
    }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    $decoded = json_decode($raw, TRUE);
    return [
      'status' => $status,
      'json' => is_array($decoded) ? $decoded : NULL,
    ];
  }

}
