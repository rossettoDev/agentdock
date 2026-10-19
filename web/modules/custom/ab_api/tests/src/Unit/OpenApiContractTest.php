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

}
