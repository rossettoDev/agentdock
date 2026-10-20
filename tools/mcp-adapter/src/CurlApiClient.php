<?php

declare(strict_types=1);

namespace McpAdapter;

/**
 * Calls the agent API with a timeout. It does not open a data store.
 */
final class CurlApiClient implements ApiClientInterface {

  public function __construct(
    private readonly string $base,
    private readonly string $token,
    private readonly int $timeout,
    private readonly string $cookie,
  ) {}

  /**
   * {@inheritdoc}
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
      curl_close($handle);
      throw new \RuntimeException('The HTTP call failed.');
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
