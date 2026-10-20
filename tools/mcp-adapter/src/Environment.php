<?php

declare(strict_types=1);

namespace McpAdapter;

/**
 * Reads the process environment. Values are not written to the output.
 */
final class Environment {

  /**
   * Reads the API URL.
   */
  public static function url(): string {
    $url = self::required('AGENT_BOARD_URL');
    if (!preg_match('#^https?://#', $url)) {
      throw new \RuntimeException('AGENT_BOARD_URL is invalid.');
    }
    return $url;
  }

  /**
   * Reads the agent credential.
   */
  public static function token(): string {
    return self::required('AGENT_BOARD_TOKEN');
  }

  /**
   * Reads the workspace this process acts in.
   */
  public static function workspace(): string {
    $workspace = self::required('AGENT_BOARD_WORKSPACE');
    $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    if (!preg_match($pattern, $workspace)) {
      throw new \RuntimeException('AGENT_BOARD_WORKSPACE is invalid.');
    }
    return $workspace;
  }

  /**
   * Reads the HTTP timeout in seconds.
   */
  public static function timeout(): int {
    $value = getenv('AGENT_BOARD_TIMEOUT');
    if (!is_string($value) || $value === '') {
      return 10;
    }
    if (!preg_match('/^[0-9]+$/', $value)) {
      throw new \RuntimeException('AGENT_BOARD_TIMEOUT is invalid.');
    }
    $parsed = (int) $value;
    if ($parsed < 1) {
      throw new \RuntimeException('AGENT_BOARD_TIMEOUT is invalid.');
    }
    return $parsed;
  }

  /**
   * Reads the test-site cookie, when the runner is inside PHPUnit.
   */
  public static function cookie(): string {
    $cookie = getenv('AGENT_BOARD_COOKIE');
    return is_string($cookie) ? $cookie : '';
  }

  /**
   * Reads a required environment variable.
   */
  private static function required(string $name): string {
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
      throw new \RuntimeException('Missing ' . $name);
    }
    return $value;
  }

}
