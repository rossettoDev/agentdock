<?php

declare(strict_types=1);

namespace McpAdapter;

/**
 * Speaks MCP over newline-delimited JSON on stdio.
 *
 * Closing the input ends the process. That is not a cancellation: no
 * execution is released, failed, or renewed.
 */
final class StdioServer {

  private const PROTOCOL = '2025-06-18';

  /**
   * Protocol versions this process can answer.
   */
  private const VERSIONS = ['2025-06-18', '2024-11-05'];

  /**
   * TRUE after initialize has been answered.
   */
  private bool $ready = FALSE;

  /**
   * Stores the streams for one stdio session.
   *
   * @param \McpAdapter\ToolGateway $gateway
   *   Caller that forwards a tool to the API.
   * @param resource $input
   *   Client messages, one JSON object per line.
   * @param resource $output
   *   Server messages. Logs stay off this stream.
   */
  public function __construct(
    private readonly ToolGateway $gateway,
    private mixed $input,
    private mixed $output,
  ) {}

  /**
   * Serves until the client closes the input.
   *
   * @return int
   *   Process status. Zero means the session ended without a protocol fault
   *   that aborted the process.
   */
  public function serve(): int {
    while (($line = $this->readLine()) !== NULL) {
      if ($line === '') {
        continue;
      }
      $decoded = json_decode($line, TRUE);
      if (!is_array($decoded) || array_is_list($decoded)) {
        $this->fail(NULL, -32700, 'Parse error.');
        continue;
      }
      $method = $decoded['method'] ?? NULL;
      if (!is_string($method) || $method === '') {
        $this->fail($this->id($decoded), -32600, 'Invalid request.');
        continue;
      }
      $notification = !array_key_exists('id', $decoded);
      try {
        $this->dispatch($method, $decoded, $notification);
      }
      catch (ProtocolException $exception) {
        if (!$notification) {
          $this->fail($this->id($decoded), $exception->rpcCode, $exception->getMessage());
        }
      }
    }
    return 0;
  }

  /**
   * Handles one message.
   *
   * @param string $method
   *   JSON-RPC method.
   * @param array<string, mixed> $decoded
   *   Decoded JSON-RPC object.
   * @param bool $notification
   *   TRUE when the message has no id.
   */
  private function dispatch(string $method, array $decoded, bool $notification): void {
    if ($method === 'notifications/cancelled') {
      return;
    }
    if ($method === 'notifications/initialized') {
      return;
    }
    if ($notification) {
      return;
    }
    $id = $this->id($decoded);
    if ($method === 'ping') {
      $this->result($id, new \stdClass());
      return;
    }
    if ($method === 'initialize') {
      $this->initialize($id, $decoded['params'] ?? NULL);
      return;
    }
    if (!$this->ready) {
      throw new ProtocolException(-32600, 'Expected initialize.');
    }
    if ($method === 'tools/list') {
      $this->result($id, ['tools' => ToolCatalog::tools()]);
      return;
    }
    if ($method === 'tools/call') {
      $this->result($id, $this->invoke($decoded['params'] ?? NULL));
      return;
    }
    throw new ProtocolException(-32601, 'Method not found.');
  }

  /**
   * Answers initialize and remembers that the session may call tools.
   *
   * @param int|string|null $id
   *   Request id.
   * @param mixed $params
   *   Initialize parameters.
   */
  private function initialize(int|string|null $id, mixed $params): void {
    if ($this->ready || !is_array($params)) {
      throw new ProtocolException(-32600, 'Invalid request.');
    }
    $requested = $params['protocolVersion'] ?? NULL;
    if (!is_string($requested) || $requested === '') {
      throw new ProtocolException(-32602, 'Invalid arguments.');
    }
    $version = in_array($requested, self::VERSIONS, TRUE) ? $requested : self::PROTOCOL;
    $this->ready = TRUE;
    $this->result($id, [
      'protocolVersion' => $version,
      'capabilities' => ['tools' => new \stdClass()],
      'serverInfo' => [
        'name' => 'agent-board',
        'title' => 'Agent Board',
        'version' => '1.0.0',
      ],
      'instructions' => 'This process is one agent credential. It does not renew a lease. The reference runner owns heartbeat while work is active. Closing this process does not cancel an execution.',
    ]);
  }

  /**
   * Invokes one tool. Arguments are not logged.
   *
   * @param mixed $params
   *   Parameters of the tool call.
   *
   * @return array<string, mixed>
   *   MCP tool result.
   */
  private function invoke(mixed $params): array {
    if (!is_array($params) || !is_string($params['name'] ?? NULL)) {
      throw new ProtocolException(-32602, 'Invalid arguments.');
    }
    $arguments = $params['arguments'] ?? [];
    if (!is_array($arguments)) {
      throw new ProtocolException(-32602, 'Invalid arguments.');
    }
    try {
      return $this->gateway->call($params['name'], $arguments);
    }
    catch (\RuntimeException $exception) {
      if ($exception instanceof ProtocolException) {
        throw $exception;
      }
      return [
        'content' => [[
          'type' => 'text',
          'text' => 'The HTTP call failed.',
        ]],
        'structuredContent' => [
          'status' => 0,
          'code' => 'transport',
          'detail' => 'The HTTP call failed.',
        ],
        'isError' => TRUE,
      ];
    }
  }

  /**
   * Reads one JSON line, up to the payload cap.
   */
  private function readLine(): ?string {
    $line = '';
    while (is_resource($this->input) && !feof($this->input)) {
      $chunk = fgets($this->input, 8192);
      if ($chunk === FALSE) {
        break;
      }
      $line .= $chunk;
      if (str_ends_with($chunk, "\n") || strlen($line) > 65536) {
        break;
      }
    }
    if ($line === '') {
      return NULL;
    }
    return trim($line);
  }

  /**
   * Reads a request id when the message has one.
   *
   * @param array<string, mixed> $decoded
   *   Decoded message.
   */
  private function id(array $decoded): int|string|null {
    $id = $decoded['id'] ?? NULL;
    if (is_int($id) || is_string($id) || $id === NULL) {
      return $id;
    }
    return NULL;
  }

  /**
   * Writes a successful JSON-RPC result.
   *
   * @param int|string|null $id
   *   Request id.
   * @param array<string, mixed>|\stdClass $result
   *   Result object.
   */
  private function result(int|string|null $id, array|\stdClass $result): void {
    $this->write([
      'jsonrpc' => '2.0',
      'id' => $id,
      'result' => $result,
    ]);
  }

  /**
   * Writes a JSON-RPC error. The message is a fixed label.
   *
   * @param int|string|null $id
   *   Request id.
   * @param int $code
   *   JSON-RPC error code.
   * @param string $message
   *   Fixed error label.
   */
  private function fail(int|string|null $id, int $code, string $message): void {
    $this->write([
      'jsonrpc' => '2.0',
      'id' => $id,
      'error' => [
        'code' => $code,
        'message' => $message,
      ],
    ]);
  }

  /**
   * Writes one JSON line to the output stream.
   *
   * @param array<string, mixed> $message
   *   JSON-RPC message.
   */
  private function write(array $message): void {
    $encoded = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || !is_resource($this->output)) {
      throw new \RuntimeException('The response could not be written.');
    }
    fwrite($this->output, $encoded . "\n");
  }

}
