<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_api\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\ab_api\ApiLimits;
use Drupal\Tests\BrowserTestBase;
use Symfony\Component\BrowserKit\Response;

/**
 * Verifies problem responses, cache policy and the body limit.
 *
 * @group ab_api
 */
class ApiProblemTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ab_api',
    'ab_api_probe',
  ];

  /**
   * Scope errors stay in problem+json and hide the secret.
   */
  public function testProblemsHideSecrets(): void {
    $requestId = '11111111-1111-4111-8111-111111111111';
    $missing = $this->call('GET', '/api/agent-board/v1/probe/missing', [
      'HTTP_X_REQUEST_ID' => $requestId,
    ]);
    $this->assertProblem($missing, 404, 'not_found', $requestId);
    $this->assertStringNotContainsString('lease-secret-missing', (string) $missing->getContent());

    $denied = $this->call('GET', '/api/agent-board/v1/probe/denied');
    $this->assertProblem($denied, 403, 'forbidden', $requestId);
    $this->assertStringNotContainsString('lease-secret-denied', (string) $denied->getContent());

    $boom = $this->call('GET', '/api/agent-board/v1/probe/boom');
    $this->assertProblem($boom, 500, 'internal', $requestId);
    $body = (string) $boom->getContent();
    $this->assertStringNotContainsString('lease-secret-boom', $body);
    $this->assertStringNotContainsString('stack.php', $body);
    $this->assertStringNotContainsString('Stack trace', $body);

    $unknown = $this->call('GET', '/api/agent-board/v1/does-not-exist');
    $this->assertProblem($unknown, 404, 'not_found', $requestId);

    $rejected = $this->call('GET', '/api/agent-board/v1/probe/ok', [
      'HTTP_AUTHORIZATION' => 'Bearer nope',
    ]);
    $this->assertProblem($rejected, 401, 'invalid_credential', $requestId);
    $this->assertStringNotContainsString('nope', (string) $rejected->getContent());

    $token = 'ab_' . str_repeat('ab', 16) . '.' . str_repeat('cd', 32);
    $unknownToken = $this->call('GET', '/api/agent-board/v1/probe/ok', [
      'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
    ]);
    $this->assertProblem($unknownToken, 401, 'invalid_credential', $requestId);
    $this->assertStringNotContainsString($token, (string) $unknownToken->getContent());

    $ok = $this->call('GET', '/api/agent-board/v1/probe/ok');
    $this->assertSame(200, $ok->getStatusCode());
    $cache = (string) $ok->getHeader('Cache-Control');
    $this->assertStringContainsString('private', $cache);
    $this->assertStringContainsString('no-store', $cache);
    $decoded = json_decode((string) $ok->getContent(), TRUE);
    $this->assertSame(['data' => ['ok' => TRUE]], $decoded);

    $large = $this->call(
      'POST',
      '/api/agent-board/v1/probe/ok',
      ['CONTENT_TYPE' => 'application/json'],
      str_repeat('x', ApiLimits::BODY + 1),
    );
    $this->assertProblem($large, 413, 'body_too_large', $requestId);
    $this->assertLessThan(1000, strlen((string) $large->getContent()));
  }

  /**
   * Sends one API request through the HTTP kernel.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   Path under the site.
   * @param array<string, string> $server
   *   Server headers.
   * @param string $body
   *   Raw request body.
   */
  private function call(string $method, string $path, array $server = [], string $body = ''): Response {
    $server += [
      'HTTP_X_REQUEST_ID' => '11111111-1111-4111-8111-111111111111',
    ];
    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(BrowserKitDriver::class, $driver);
    $client = $driver->getClient();
    $client->request($method, $this->buildUrl($path), [], [], $server, $body);
    $response = $client->getResponse();
    $this->assertInstanceOf(Response::class, $response);
    return $response;
  }

  /**
   * Asserts the problem envelope and the private cache header.
   */
  private function assertProblem(Response $response, int $status, string $code, string $requestId): void {
    $this->assertSame($status, $response->getStatusCode());
    $type = (string) $response->getHeader('Content-Type');
    $this->assertStringContainsString('application/problem+json', $type);
    $cache = (string) $response->getHeader('Cache-Control');
    $this->assertStringContainsString('private', $cache);
    $this->assertStringContainsString('no-store', $cache);
    $this->assertSame($requestId, (string) $response->getHeader('X-Request-Id'));
    $decoded = json_decode((string) $response->getContent(), TRUE);
    $this->assertIsArray($decoded);
    $this->assertSame($status, $decoded['status']);
    $this->assertSame($code, $decoded['code']);
    $this->assertSame($requestId, $decoded['request_id']);
    $this->assertSame('/problems/' . $code, $decoded['type']);
    $this->assertArrayNotHasKey('trace', $decoded);
    $this->assertArrayNotHasKey('exception', $decoded);
  }

}
