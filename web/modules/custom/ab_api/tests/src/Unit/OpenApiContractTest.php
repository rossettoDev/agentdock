<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_api\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks the OpenAPI document the runner can rely on.
 *
 * @group ab_api
 */
class OpenApiContractTest extends UnitTestCase {

  /**
   * The parsed contract.
   *
   * @var array<string, mixed>
   */
  private array $document;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $path = dirname(__DIR__, 7) . '/docs/openapi.yaml';
    $parsed = Yaml::parseFile($path);
    $this->assertIsArray($parsed);
    $this->document = $parsed;
  }

  /**
   * The contract exposes the execution read without a lease secret.
   */
  public function testExecutionReadOmitsTheLeaseSecret(): void {
    $this->assertSame('3.0.3', $this->document['openapi']);
    $this->assertSame('1.1.1', $this->document['info']['version']);
    $paths = $this->document['paths'];
    $this->assertIsArray($paths);
    $path = '/workspaces/{workspace_id}/executions/{execution_id}';
    $this->assertArrayHasKey($path, $paths);
    $this->assertIsArray($paths[$path]);
    $this->assertArrayHasKey('get', $paths[$path]);
    $schemas = $this->document['components']['schemas'];
    $this->assertIsArray($schemas);
    $execution = $schemas['Execution']['properties'];
    $this->assertIsArray($execution);
    $this->assertArrayNotHasKey('lease_token', $execution);
    $claim = $schemas['ClaimResult']['properties'];
    $this->assertIsArray($claim);
    $this->assertArrayHasKey('lease_token', $claim);
    $problem = $schemas['Problem']['required'];
    $this->assertIsArray($problem);
    $this->assertSame(
      ['type', 'title', 'status', 'detail', 'code', 'request_id'],
      $problem,
    );
    $limit = $this->document['components']['parameters']['Limit']['schema'];
    $this->assertIsArray($limit);
    $this->assertSame(50, $limit['default']);
    $this->assertSame(100, $limit['maximum']);
    $update = $paths['/workspaces/{workspace_id}/tasks/{task_id}']['patch'];
    $this->assertIsArray($update);
    $this->assertIsArray($update['parameters']);
    $this->assertContains(
      ['$ref' => '#/components/parameters/IfMatch'],
      $update['parameters'],
    );
    $this->assertArrayHasKey('ETag', $this->document['components']['headers']);
    $this->assertStringContainsString('256 KiB', (string) $this->document['info']['description']);
    $this->assertStringContainsString('16 KiB', (string) $this->document['info']['description']);
  }

  /**
   * Memory routes are additive and never expose a lease secret.
   */
  public function testMemoryContractIsAdditiveWithoutLeaseSecrets(): void {
    $paths = $this->document['paths'];
    $this->assertIsArray($paths);
    $this->assertArrayHasKey('/workspaces/{workspace_id}/memory', $paths);
    $this->assertArrayHasKey('get', $paths['/workspaces/{workspace_id}/memory']);
    $this->assertArrayHasKey('post', $paths['/workspaces/{workspace_id}/memory']);
    $this->assertArrayHasKey('/workspaces/{workspace_id}/memory/{memory_id}', $paths);
    $this->assertArrayHasKey('get', $paths['/workspaces/{workspace_id}/memory/{memory_id}']);
    $this->assertArrayHasKey('patch', $paths['/workspaces/{workspace_id}/memory/{memory_id}']);
    $this->assertArrayHasKey('/workspaces/{workspace_id}/memory/{memory_id}/publish', $paths);
    $this->assertArrayHasKey('/workspaces/{workspace_id}/memory/{memory_id}/archive', $paths);
    $this->assertArrayHasKey('/workspaces/{workspace_id}/memory/{memory_id}/revisions', $paths);
    $this->assertArrayNotHasKey('/workspaces/{workspace_id}/memory/export', $paths);

    $revise = $paths['/workspaces/{workspace_id}/memory/{memory_id}']['patch'];
    $this->assertIsArray($revise);
    $this->assertContains(
      ['$ref' => '#/components/parameters/IfMatch'],
      $revise['parameters'],
    );

    $schemas = $this->document['components']['schemas'];
    $this->assertIsArray($schemas);
    foreach (['Memory', 'MemoryCreate', 'MemoryPatch', 'MemorySearchHit', 'MemoryRevision'] as $name) {
      $this->assertArrayHasKey($name, $schemas);
      $properties = $schemas[$name]['properties'] ?? [];
      $this->assertIsArray($properties);
      $this->assertArrayNotHasKey('lease_token', $properties);
      $this->assertArrayNotHasKey('author_id', $schemas['MemoryCreate']['properties'] ?? []);
    }

    $markdown = $schemas['MemoryCreate']['properties']['content_markdown'];
    $this->assertIsArray($markdown);
    $this->assertSame(65536, $markdown['maxLength']);
    $query = $this->document['components']['parameters']['MemoryQuery']['schema'];
    $this->assertIsArray($query);
    $this->assertSame(256, $query['maxLength']);
    $excerpt = $schemas['MemorySearchHit']['properties']['excerpt'];
    $this->assertIsArray($excerpt);
    $this->assertSame(480, $excerpt['maxLength']);
    $task = $schemas['Task']['properties']['related_memories'] ?? NULL;
    $this->assertIsArray($task);
    $this->assertSame(5, $task['maxItems']);
    $this->assertSame('#/components/schemas/MemorySearchHit', $task['items']['$ref'] ?? NULL);
    $this->assertStringContainsString('64 KiB', (string) $this->document['info']['description']);
    $this->assertStringContainsString('memory:read', (string) $this->document['info']['description']);
    $bearer = $this->document['components']['securitySchemes']['bearerAuth']['description'];
    $this->assertIsString($bearer);
    $this->assertStringContainsString('memory:write', $bearer);
  }

}
