<?php

declare(strict_types=1);

namespace McpAdapter;

/**
 * Forwards one tool call to the agent API and forgets it.
 *
 * The process credential is the only actor. Arguments cannot choose an
 * agent, an author, or a workspace. This object does not keep an
 * execution, and it does not renew a lease.
 */
final class ToolGateway {

  /**
   * Argument names that would choose an identity.
   */
  private const REJECTED = [
    'agent_id',
    'author_id',
    'workspace_id',
    'authorization',
    'password',
    'secret',
    'token',
    'bearer',
  ];

  public function __construct(
    private readonly ApiClientInterface $client,
    private readonly string $workspace,
  ) {}

  /**
   * Calls one tool and returns an MCP tool result.
   *
   * @param string $name
   *   Tool name from the catalog.
   * @param array<string, mixed> $arguments
   *   Arguments supplied by the client.
   *
   * @return array<string, mixed>
   *   MCP tool result. An API rejection is isError, not a protocol error.
   */
  public function call(string $name, array $arguments): array {
    $this->rejectIdentity($arguments);
    return match ($name) {
      'list_boards' => $this->read('/boards', $this->pageQuery($arguments)),
      'list_ready_tasks' => $this->readyTasks($arguments),
      'get_task' => $this->one($arguments, 'task_id', '/tasks/'),
      'claim_task' => $this->claim($arguments),
      'report_progress' => $this->leaseWrite($arguments, '/progress', [
        'note' => $this->text($arguments, 'note', 500, TRUE),
      ]),
      'post_message' => $this->message($arguments),
      'request_human_input' => $this->leaseWrite($arguments, '/request-input', [
        'body' => $this->text($arguments, 'body', 16384, TRUE),
      ]),
      'get_execution' => $this->one($arguments, 'execution_id', '/executions/'),
      'submit_result' => $this->leaseWrite($arguments, '/submit', $this->submitBody($arguments)),
      'fail_execution' => $this->leaseWrite($arguments, '/fail', $this->failBody($arguments)),
      'release_task' => $this->leaseWrite($arguments, '/release', []),
      'get_agent_inbox' => $this->read('/agent-inbox', $this->pageQuery($arguments)),
      default => throw new ProtocolException(-32602, 'Unknown tool.'),
    };
  }

  /**
   * Refuses an argument that would select the actor.
   *
   * @param array<string, mixed> $arguments
   *   Tool arguments.
   */
  private function rejectIdentity(array $arguments): void {
    foreach (self::REJECTED as $name) {
      if (array_key_exists($name, $arguments)) {
        throw new ProtocolException(-32602, 'Invalid arguments.');
      }
    }
  }

  /**
   * Lists ready tasks. The status filter is fixed by the tool name.
   *
   * @param array<string, mixed> $arguments
   *   Tool arguments.
   *
   * @return array<string, mixed>
   *   MCP tool result.
   */
  private function readyTasks(array $arguments): array {
    $this->only($arguments, ['board_id', 'cursor', 'limit']);
    return $this->read(
      '/boards/' . $this->uuid($arguments, 'board_id') . '/tasks',
      ['status' => 'ready'] + $this->pageQuery($arguments, ['board_id']),
    );
  }

  /**
   * Reads one task or execution.
   *
   * @param array<string, mixed> $arguments
   *   Tool arguments.
   * @param string $name
   *   UUID argument name.
   * @param string $prefix
   *   Path prefix under the workspace.
   *
   * @return array<string, mixed>
   *   MCP tool result.
   */
  private function one(array $arguments, string $name, string $prefix): array {
    $this->only($arguments, [$name]);
    return $this->read($prefix . $this->uuid($arguments, $name), []);
  }

  /**
   * Claims a task. The returned lease is not stored on this object.
   *
   * @param array<string, mixed> $arguments
   *   Tool arguments.
   *
   * @return array<string, mixed>
   *   MCP tool result.
   */
  private function claim(array $arguments): array {
    $this->only($arguments, ['task_id', 'idempotency_key']);
    return $this->write(
      '/tasks/' . $this->uuid($arguments, 'task_id') . '/claim',
      [],
      $this->uuid($arguments, 'idempotency_key'),
      NULL,
    );
  }

  /**
   * Reads one API page.
   *
   * @param string $suffix
   *   Path under the workspace.
   * @param array<string, int|string> $query
   *   Query parameters.
   *
   * @return array<string, mixed>
   *   MCP tool result.
   */
  private function read(string $suffix, array $query): array {
    $path = $this->workspacePath($suffix);
    if ($query !== []) {
      $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    return $this->outcome($this->client->send('GET', $path, NULL, []));
  }

  /**
   * Sends one lease command. The lease is a header, not stored state.
   *
   * @param array<string, mixed> $arguments
   *   Tool arguments.
   * @param string $suffix
   *   Path under the execution.
   * @param array<string, mixed> $body
   *   JSON object.
   *
   * @return array<string, mixed>
   *   MCP tool result.
   */
  private function leaseWrite(array $arguments, string $suffix, array $body): array {
    $this->only($arguments, array_merge(
      ['execution_id', 'lease_token', 'idempotency_key'],
      array_keys($body),
    ));
    return $this->write(
      '/executions/' . $this->uuid($arguments, 'execution_id') . $suffix,
      $body,
      $this->uuid($arguments, 'idempotency_key'),
      $this->lease($arguments),
    );
  }

  /**
   * Posts one message. The body is not kept after the response.
   *
   * @param array<string, mixed> $arguments
   *   Tool arguments.
   *
   * @return array<string, mixed>
   *   MCP tool result.
   */
  private function message(array $arguments): array {
    $allowed = ['thread_id', 'idempotency_key', 'kind', 'body', 'reply_to', 'mentions'];
    $this->only($arguments, $allowed);
    $body = [
      'kind' => $this->text($arguments, 'kind', 32, TRUE),
      'body' => $this->text($arguments, 'body', 16384, TRUE),
    ];
    if (array_key_exists('reply_to', $arguments)) {
      $body['reply_to'] = $this->uuid($arguments, 'reply_to');
    }
    if (array_key_exists('mentions', $arguments)) {
      $body['mentions'] = $this->mentions($arguments['mentions']);
    }
    return $this->write(
      '/threads/' . $this->uuid($arguments, 'thread_id') . '/messages',
      $body,
      $this->uuid($arguments, 'idempotency_key'),
      NULL,
    );
  }

  /**
   * Builds the submit object the API already validates.
   *
   * @param array<string, mixed> $arguments
   *   Tool arguments.
   *
   * @return array<string, mixed>
   *   Request body.
   */
  private function submitBody(array $arguments): array {
    $body = ['summary' => $this->text($arguments, 'summary', 16384, FALSE)];
    if (array_key_exists('links', $arguments)) {
      $body['links'] = $this->links($arguments['links']);
    }
    return $body;
  }

  /**
   * Builds the fail object the API already validates.
   *
   * @param array<string, mixed> $arguments
   *   Tool arguments.
   *
   * @return array<string, mixed>
   *   Request body.
   */
  private function failBody(array $arguments): array {
    $body = ['code' => $this->text($arguments, 'code', 64, TRUE)];
    if (array_key_exists('detail', $arguments)) {
      $body['detail'] = $this->text($arguments, 'detail', 16384, FALSE);
    }
    return $body;
  }

  /**
   * Sends one write. The idempotency key comes from the caller.
   *
   * @param string $suffix
   *   Path under the workspace.
   * @param array<string, mixed> $body
   *   JSON object.
   * @param string $key
   *   Idempotency key.
   * @param string|null $lease
   *   Lease header, when the route requires one.
   *
   * @return array<string, mixed>
   *   MCP tool result.
   */
  private function write(string $suffix, array $body, string $key, ?string $lease): array {
    $headers = ['Idempotency-Key' => $key];
    if ($lease !== NULL) {
      $headers['X-Execution-Lease'] = $lease;
    }
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if ($body === []) {
      $flags |= JSON_FORCE_OBJECT;
    }
    $encoded = json_encode($body, $flags);
    if (!is_string($encoded)) {
      throw new ProtocolException(-32602, 'Invalid arguments.');
    }
    return $this->outcome($this->client->send('POST', $this->workspacePath($suffix), $encoded, $headers));
  }

  /**
   * Maps an HTTP response onto a tool result.
   *
   * @param array{status: int, json: array<string, mixed>|null} $response
   *   Client response.
   *
   * @return array<string, mixed>
   *   MCP tool result.
   */
  private function outcome(array $response): array {
    $status = $response['status'];
    $json = $response['json'];
    if ($status >= 200 && $status < 300 && is_array($json)) {
      return $this->content($json, FALSE);
    }
    $code = is_array($json) && is_string($json['code'] ?? NULL) ? $json['code'] : 'http';
    $detail = is_array($json) && is_string($json['detail'] ?? NULL) ? $json['detail'] : 'The API rejected the call.';
    return $this->content([
      'status' => $status,
      'code' => $code,
      'detail' => $detail,
    ], TRUE);
  }

  /**
   * Wraps a JSON object as text and structured content.
   *
   * @param array<string, mixed> $body
   *   Object returned to the client.
   * @param bool $error
   *   TRUE when the API rejected the call.
   *
   * @return array<string, mixed>
   *   MCP tool result.
   */
  private function content(array $body, bool $error): array {
    $text = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return [
      'content' => [[
        'type' => 'text',
        'text' => is_string($text) ? $text : '{}',
      ]],
      'structuredContent' => $body,
      'isError' => $error,
    ];
  }

  /**
   * Reads optional page arguments.
   *
   * @param array<string, mixed> $arguments
   *   Tool arguments.
   * @param list<string> $extra
   *   Other argument names this tool accepts.
   *
   * @return array<string, int|string>
   *   Query parameters.
   */
  private function pageQuery(array $arguments, array $extra = []): array {
    $this->only($arguments, array_merge(['cursor', 'limit'], $extra));
    $query = [];
    if (array_key_exists('cursor', $arguments)) {
      $query['cursor'] = $this->text($arguments, 'cursor', 512, TRUE);
    }
    if (array_key_exists('limit', $arguments)) {
      $limit = $arguments['limit'];
      if (!is_int($limit) || $limit < 1 || $limit > 100) {
        throw new ProtocolException(-32602, 'Invalid arguments.');
      }
      $query['limit'] = $limit;
    }
    return $query;
  }

  /**
   * Rejects an argument this tool does not declare.
   *
   * @param array<string, mixed> $arguments
   *   Tool arguments.
   * @param list<string> $allowed
   *   Names the tool accepts.
   */
  private function only(array $arguments, array $allowed): void {
    foreach ($arguments as $name => $value) {
      if (!in_array($name, $allowed, TRUE)) {
        throw new ProtocolException(-32602, 'Invalid arguments.');
      }
    }
  }

  /**
   * Reads a lowercase UUID argument.
   *
   * @param array<string, mixed> $arguments
   *   Tool arguments.
   * @param string $name
   *   Argument name.
   */
  private function uuid(array $arguments, string $name): string {
    $value = $arguments[$name] ?? NULL;
    if (!is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) !== 1) {
      throw new ProtocolException(-32602, 'Invalid arguments.');
    }
    return $value;
  }

  /**
   * Reads the lease header value without storing it.
   *
   * @param array<string, mixed> $arguments
   *   Tool arguments.
   */
  private function lease(array $arguments): string {
    $value = $arguments['lease_token'] ?? NULL;
    if (!is_string($value) || preg_match('/^[A-Za-z0-9._~-]{32,512}$/', $value) !== 1) {
      throw new ProtocolException(-32602, 'Invalid arguments.');
    }
    return $value;
  }

  /**
   * Reads a bounded string argument.
   *
   * @param array<string, mixed> $arguments
   *   Tool arguments.
   * @param string $name
   *   Argument name.
   * @param int $max
   *   Maximum byte length.
   * @param bool $required
   *   TRUE when an empty string is rejected.
   */
  private function text(array $arguments, string $name, int $max, bool $required): string {
    $value = $arguments[$name] ?? NULL;
    if (!is_string($value) || strlen($value) > $max || str_contains($value, "\0")) {
      throw new ProtocolException(-32602, 'Invalid arguments.');
    }
    if ($required && $value === '') {
      throw new ProtocolException(-32602, 'Invalid arguments.');
    }
    return $value;
  }

  /**
   * Reads mention objects the message API accepts.
   *
   * @param mixed $value
   *   Raw mentions argument.
   *
   * @return list<array{type: string, id: string}>
   *   Mentions forwarded as given.
   */
  private function mentions(mixed $value): array {
    if (!is_array($value) || count($value) > 20) {
      throw new ProtocolException(-32602, 'Invalid arguments.');
    }
    $clean = [];
    foreach ($value as $mention) {
      if (!is_array($mention)) {
        throw new ProtocolException(-32602, 'Invalid arguments.');
      }
      $keys = array_keys($mention);
      sort($keys);
      if ($keys !== ['id', 'type']) {
        throw new ProtocolException(-32602, 'Invalid arguments.');
      }
      $type = $mention['type'];
      $id = $mention['id'];
      if (!is_string($type) || !is_string($id) || $type === '' || $id === '' || strlen($type) > 16 || strlen($id) > 64) {
        throw new ProtocolException(-32602, 'Invalid arguments.');
      }
      $clean[] = ['type' => $type, 'id' => $id];
    }
    return $clean;
  }

  /**
   * Reads link strings the submit API accepts.
   *
   * @param mixed $value
   *   Raw links argument.
   *
   * @return list<string>
   *   Links forwarded as given.
   */
  private function links(mixed $value): array {
    if (!is_array($value) || count($value) > 20) {
      throw new ProtocolException(-32602, 'Invalid arguments.');
    }
    $clean = [];
    foreach ($value as $link) {
      if (!is_string($link) || $link === '' || strlen($link) > 500) {
        throw new ProtocolException(-32602, 'Invalid arguments.');
      }
      $clean[] = $link;
    }
    return $clean;
  }

  /**
   * Prefixes a path with the workspace from the process environment.
   */
  private function workspacePath(string $suffix): string {
    return '/workspaces/' . $this->workspace . $suffix;
  }

}
