<?php

declare(strict_types=1);

namespace McpAdapter;

/**
 * Lists the architecture tools and the arguments each one accepts.
 *
 * The catalog does not decide eligibility, grants, or transitions.
 */
final class ToolCatalog {

  /**
   * Returns the tools in the architecture order.
   *
   * @return list<array<string, mixed>>
   *   MCP tool definitions.
   */
  public static function tools(): array {
    $cursor = [
      'type' => 'string',
      'maxLength' => 512,
      'description' => 'Opaque page cursor returned by the API.',
    ];
    $limit = [
      'type' => 'integer',
      'minimum' => 1,
      'maximum' => 100,
      'description' => 'Page size. The API default is 50.',
    ];
    $uuid = [
      'type' => 'string',
      'format' => 'uuid',
    ];
    $key = [
      'type' => 'string',
      'format' => 'uuid',
      'description' => 'Idempotency-Key forwarded to the API.',
    ];
    $lease = [
      'type' => 'string',
      'minLength' => 32,
      'maxLength' => 512,
      'description' => 'Lease token returned once by claim_task.',
    ];
    return [
      self::tool('list_boards', 'List boards the agent can read.', [
        'cursor' => $cursor,
        'limit' => $limit,
      ], []),
      self::tool('list_ready_tasks', 'List ready tasks on one board.', [
        'board_id' => $uuid + ['description' => 'Board UUID.'],
        'cursor' => $cursor,
        'limit' => $limit,
      ], ['board_id']),
      self::tool('get_task', 'Read one task.', [
        'task_id' => $uuid + ['description' => 'Task UUID.'],
      ], ['task_id']),
      self::tool('claim_task', 'Claim a ready task. The lease is returned once and is not stored here.', [
        'task_id' => $uuid + ['description' => 'Task UUID.'],
        'idempotency_key' => $key,
      ], ['task_id', 'idempotency_key']),
      self::tool('report_progress', 'Record a progress note on an execution the caller already holds.', [
        'execution_id' => $uuid + ['description' => 'Execution UUID.'],
        'lease_token' => $lease,
        'idempotency_key' => $key,
        'note' => [
          'type' => 'string',
          'minLength' => 1,
          'maxLength' => 500,
        ],
      ], ['execution_id', 'lease_token', 'idempotency_key', 'note']),
      self::tool('post_message', 'Append a message to a thread.', [
        'thread_id' => $uuid + ['description' => 'Thread UUID.'],
        'idempotency_key' => $key,
        'kind' => [
          'type' => 'string',
          'maxLength' => 32,
        ],
        'body' => [
          'type' => 'string',
          'minLength' => 1,
          'maxLength' => 16384,
        ],
        'reply_to' => $uuid + ['description' => 'Message UUID this one replies to.'],
        'mentions' => [
          'type' => 'array',
          'maxItems' => 20,
          'items' => [
            'type' => 'object',
            'additionalProperties' => FALSE,
            'required' => ['type', 'id'],
            'properties' => [
              'type' => ['type' => 'string'],
              'id' => ['type' => 'string'],
            ],
          ],
        ],
      ], ['thread_id', 'idempotency_key', 'kind', 'body']),
      self::tool('request_human_input', 'Ask a human a question through the execution API.', [
        'execution_id' => $uuid + ['description' => 'Execution UUID.'],
        'lease_token' => $lease,
        'idempotency_key' => $key,
        'body' => [
          'type' => 'string',
          'minLength' => 1,
          'maxLength' => 16384,
        ],
      ], ['execution_id', 'lease_token', 'idempotency_key', 'body']),
      self::tool('get_execution', 'Read one execution. The response has no lease secret.', [
        'execution_id' => $uuid + ['description' => 'Execution UUID.'],
      ], ['execution_id']),
      self::tool('submit_result', 'Deliver a result through the execution API.', [
        'execution_id' => $uuid + ['description' => 'Execution UUID.'],
        'lease_token' => $lease,
        'idempotency_key' => $key,
        'summary' => [
          'type' => 'string',
          'maxLength' => 16384,
        ],
        'links' => [
          'type' => 'array',
          'items' => ['type' => 'string'],
        ],
      ], ['execution_id', 'lease_token', 'idempotency_key', 'summary']),
      self::tool('fail_execution', 'Record a failure through the execution API.', [
        'execution_id' => $uuid + ['description' => 'Execution UUID.'],
        'lease_token' => $lease,
        'idempotency_key' => $key,
        'code' => [
          'type' => 'string',
          'minLength' => 1,
          'maxLength' => 64,
        ],
        'detail' => [
          'type' => 'string',
          'maxLength' => 16384,
        ],
      ], ['execution_id', 'lease_token', 'idempotency_key', 'code']),
      self::tool('release_task', 'Release the lease of one execution through the API.', [
        'execution_id' => $uuid + ['description' => 'Execution UUID.'],
        'lease_token' => $lease,
        'idempotency_key' => $key,
      ], ['execution_id', 'lease_token', 'idempotency_key']),
      self::tool('get_agent_inbox', 'Read mentions and signals for this agent.', [
        'cursor' => $cursor,
        'limit' => $limit,
      ], []),
    ];
  }

  /**
   * Builds one tool definition.
   *
   * @param string $name
   *   Tool name.
   * @param string $description
   *   What the tool asks the API to do.
   * @param array<string, mixed> $properties
   *   JSON Schema properties.
   * @param list<string> $required
   *   Required argument names.
   *
   * @return array<string, mixed>
   *   MCP tool definition.
   */
  private static function tool(string $name, string $description, array $properties, array $required): array {
    $schema = [
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => $properties,
    ];
    if ($required !== []) {
      $schema['required'] = $required;
    }
    return [
      'name' => $name,
      'description' => $description,
      'inputSchema' => $schema,
    ];
  }

}
