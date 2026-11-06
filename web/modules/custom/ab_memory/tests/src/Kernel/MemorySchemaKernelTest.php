<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_memory\Kernel;

use Drupal\ab_memory\MemorySchema;
use Drupal\KernelTests\KernelTestBase;

/**
 * Installs memory tables for new and existing sites.
 *
 * @group ab_memory
 */
class MemorySchemaKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'options',
    'ab_core',
    'ab_event',
    'ab_board',
    'ab_agent',
    'ab_memory',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('ab_memory', [
      'ab_memory_entry',
      'ab_memory_revision',
      'ab_memory_source',
      'ab_memory_link',
    ]);
  }

  /**
   * Hook schema creates the four memory tables with uniqueness.
   */
  public function testSchemaDefinesEntriesAndRevisions(): void {
    $schema = $this->container->get('database')->schema();
    foreach (['ab_memory_entry', 'ab_memory_revision', 'ab_memory_source', 'ab_memory_link'] as $table) {
      $this->assertTrue($schema->tableExists($table), $table);
    }
    $this->assertTrue($schema->fieldExists('ab_memory_entry', 'version'));
    $this->assertTrue($schema->fieldExists('ab_memory_revision', 'content_hash'));
    $this->assertTrue($schema->fieldExists('ab_memory_revision', 'author_type'));
  }

  /**
   * Update installer is idempotent on an existing site.
   */
  public function testInstallIsIdempotentAfterDrop(): void {
    $schema = $this->container->get('database')->schema();
    $schema->dropTable('ab_memory_link');
    $schema->dropTable('ab_memory_source');
    $schema->dropTable('ab_memory_revision');
    $schema->dropTable('ab_memory_entry');
    $first = MemorySchema::install();
    $this->assertStringContainsString('Created the ab_memory_entry table.', $first);
    $second = MemorySchema::install();
    $this->assertSame('Memory schema already present.', $second);
    $this->assertTrue($schema->tableExists('ab_memory_entry'));
    $this->assertTrue($schema->tableExists('ab_memory_revision'));
  }

}
