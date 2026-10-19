<?php

declare(strict_types=1);

namespace Drupal\ab_api;

/**
 * Installs the idempotency table on sites that already have the module.
 */
final class ApiSchema {

  /**
   * Schema for one reserved idempotency key.
   *
   * The unique key includes the resource so the same client key on two
   * tasks does not collide. A sealed response body is ciphertext.
   *
   * @return array<string, mixed>
   *   A hook_schema table definition.
   */
  public static function idempotencyTable(): array {
    return [
      'description' => 'Reserved idempotency keys and their replay.',
      'fields' => [
        'id' => [
          'description' => 'Primary identifier.',
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'size' => 'big',
        ],
        'workspace' => [
          'description' => 'Workspace UUID taken from the actor.',
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
        ],
        'actor_id' => [
          'description' => 'Authenticated actor id.',
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
        ],
        'token_id' => [
          'description' => 'Agent token public id, or empty for a human.',
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
          'default' => '',
        ],
        'operation' => [
          'description' => 'Dotted operation name.',
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
        ],
        'resource_id' => [
          'description' => 'Resource UUID the operation targets.',
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
        ],
        'idempotency_key' => [
          'description' => 'Client Idempotency-Key UUID.',
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
        ],
        'request_hash' => [
          'description' => 'SHA-256 of the raw request body.',
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
        ],
        'response_status' => [
          'description' => 'HTTP status stored for replay.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
        'response_body' => [
          'description' => 'Replay body. Ciphertext when sealed is 1.',
          'type' => 'text',
          'not null' => TRUE,
          'size' => 'big',
        ],
        'sealed' => [
          'description' => '1 when response_body is ciphertext.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
          'size' => 'tiny',
        ],
        'status' => [
          'description' => 'pending or completed.',
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
        ],
        'expires_at' => [
          'description' => 'UTC timestamp when the replay may be dropped.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'created' => [
          'description' => 'UTC timestamp when the key was reserved.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'scope' => [
          'workspace',
          'actor_id',
          'token_id',
          'operation',
          'resource_id',
          'idempotency_key',
        ],
      ],
      'indexes' => [
        'expires' => ['expires_at'],
      ],
    ];
  }

  /**
   * Creates the idempotency table on an existing site.
   */
  public static function install(): string {
    $schema = \Drupal::database()->schema();
    if ($schema->tableExists('ab_idempotency')) {
      return 'Idempotency schema already present.';
    }
    $schema->createTable('ab_idempotency', self::idempotencyTable());
    return 'Created the idempotency table.';
  }

}
