<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_event\Kernel;

use Drupal\ab_event\EventSchemaInstaller;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\pgsql\Driver\Database\pgsql\Schema;

/**
 * Verifies the event store schema on install and update.
 *
 * @group ab_event
 */
class EventSchemaKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'ab_core', 'ab_event'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('ab_event', [
      'ab_workspace_sequence',
      'ab_event',
      'ab_outbox',
    ]);
  }

  /**
   * Install creates the predicted tables, unique keys and indexes.
   */
  public function testInstallCreatesIndexesAndUniqueness(): void {
    $this->assertEventSchema();
    $this->assertWorkspaceSequenceIsUnique();
  }

  /**
   * Update creates the same schema when the tables are missing.
   */
  public function testUpdateCreatesIndexesAndUniqueness(): void {
    $schema = $this->database()->schema();
    $schema->dropTable('ab_outbox');
    $schema->dropTable('ab_event');
    $schema->dropTable('ab_workspace_sequence');
    $this->assertFalse($schema->tableExists('ab_event'));

    $message = EventSchemaInstaller::installMissing();
    $this->assertStringContainsString('ab_event', $message);
    $this->assertEventSchema();

    $again = EventSchemaInstaller::installMissing();
    $this->assertSame('Event schema already present.', $again);
  }

  /**
   * Returns the active database connection.
   */
  private function database(): Connection {
    $database = $this->container->get('database');
    $this->assertInstanceOf(Connection::class, $database);
    return $database;
  }

  /**
   * Asserts tables, keys and the workspace/sequence uniqueness.
   */
  private function assertEventSchema(): void {
    $schema = $this->database()->schema();
    $this->assertInstanceOf(Schema::class, $schema);

    $this->assertTrue($schema->tableExists('ab_workspace_sequence'));
    $this->assertTrue($schema->fieldExists('ab_workspace_sequence', 'sequence'));
    $this->assertTrue($schema->constraintExists('ab_workspace_sequence', 'pkey'));

    $this->assertTrue($schema->tableExists('ab_event'));
    $event_fields = [
      'event_id',
      'workspace_id',
      'sequence',
      'type',
      'resource_id',
      'actor_type',
      'actor_id',
      'created',
      'payload',
    ];
    foreach ($event_fields as $field) {
      $this->assertTrue($schema->fieldExists('ab_event', $field), $field);
    }
    $this->assertTrue($schema->constraintExists('ab_event', 'pkey'));
    $this->assertTrue($schema->constraintExists('ab_event', 'workspace_sequence__key'));

    $this->assertTrue($schema->tableExists('ab_outbox'));
    $outbox_fields = [
      'event_id',
      'destination',
      'attempts',
      'available_at',
      'processed_at',
      'last_error',
    ];
    foreach ($outbox_fields as $field) {
      $this->assertTrue($schema->fieldExists('ab_outbox', $field), $field);
    }
    $this->assertTrue($schema->constraintExists('ab_outbox', 'pkey'));
    $this->assertTrue($schema->indexExists('ab_outbox', 'processed_available'));

    $workspace_id = '22222222-2222-4222-8222-222222222222';
    $this->insertEvent($workspace_id, 1, '11111111-1111-4111-8111-111111111111');
    try {
      $this->insertEvent($workspace_id, 1, '44444444-4444-4444-8444-444444444444');
      $this->fail('Duplicate workspace sequence was stored.');
    }
    catch (IntegrityConstraintViolationException $exception) {
      $this->assertStringContainsString('workspace_sequence', $exception->getMessage());
    }

    $count = $this->database()->select('ab_event', 'e')
      ->condition('workspace_id', $workspace_id)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame('1', (string) $count);

    $event_id = '11111111-1111-4111-8111-111111111111';
    $this->insertOutbox($event_id, 'sync');
    try {
      $this->insertOutbox($event_id, 'sync');
      $this->fail('Duplicate outbox destination was stored.');
    }
    catch (IntegrityConstraintViolationException) {
      $stored = $this->database()->select('ab_outbox', 'o')
        ->condition('event_id', $event_id)
        ->countQuery()
        ->execute()
        ->fetchField();
      $this->assertSame('1', (string) $stored);
    }
  }

  /**
   * Asserts one counter row per workspace.
   */
  private function assertWorkspaceSequenceIsUnique(): void {
    $workspace_id = '55555555-5555-4555-8555-555555555555';
    $this->database()->insert('ab_workspace_sequence')
      ->fields([
        'workspace_id' => $workspace_id,
        'sequence' => 0,
      ])
      ->execute();
    try {
      $this->database()->insert('ab_workspace_sequence')
        ->fields([
          'workspace_id' => $workspace_id,
          'sequence' => 1,
        ])
        ->execute();
      $this->fail('Duplicate workspace counter was stored.');
    }
    catch (IntegrityConstraintViolationException) {
      $stored = $this->database()->select('ab_workspace_sequence', 's')
        ->fields('s', ['sequence'])
        ->condition('workspace_id', $workspace_id)
        ->execute()
        ->fetchField();
      $this->assertSame('0', (string) $stored);
    }
  }

  /**
   * Inserts one event row.
   */
  private function insertEvent(string $workspace_id, int $sequence, string $event_id): void {
    $this->database()->insert('ab_event')
      ->fields([
        'event_id' => $event_id,
        'workspace_id' => $workspace_id,
        'sequence' => $sequence,
        'type' => 'task.created',
        'resource_id' => '33333333-3333-4333-8333-333333333333',
        'actor_type' => 'human',
        'actor_id' => '1',
        'created' => 1760000000,
        'payload' => '{}',
      ])
      ->execute();
  }

  /**
   * Inserts one outbox row.
   */
  private function insertOutbox(string $event_id, string $destination): void {
    $this->database()->insert('ab_outbox')
      ->fields([
        'event_id' => $event_id,
        'destination' => $destination,
        'attempts' => 0,
        'available_at' => 1760000000,
        'processed_at' => NULL,
        'last_error' => NULL,
      ])
      ->execute();
  }

}
