<?php

declare(strict_types=1);

namespace Drupal\ab_api;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * Counts API calls in the shared database.
 *
 * One minute is one row. A second app server sees the same count.
 * Message creates use a separate budget from heartbeat and polling.
 */
final class RateLimiter {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Counts this request and returns how long the caller should wait.
   *
   * NULL means the request is inside the budget. Anonymous calls are
   * not counted; they are refused as an invalid credential.
   */
  public function retryAfter(Request $request): ?int {
    $bucket = $this->bucket($request);
    if ($bucket === NULL) {
      return NULL;
    }
    $now = $this->time->getRequestTime();
    $window = intdiv($now, ApiLimits::RATE_WINDOW) * ApiLimits::RATE_WINDOW;
    $this->database->delete('ab_api_rate')
      ->condition('window_start', $now - (2 * ApiLimits::RATE_WINDOW), '<')
      ->execute();
    $hits = $this->increment($bucket, $window);
    if ($hits > $this->limit('ab_api_rate_limit', ApiLimits::RATE_MAX)) {
      return $this->wait($now, $window);
    }
    if ($this->isMessage($request)) {
      $messages = $this->increment('message:' . $bucket, $window);
      $max = $this->limit('ab_api_message_rate_limit', ApiLimits::MESSAGE_RATE_MAX);
      if ($messages > $max) {
        return $this->wait($now, $window);
      }
    }
    return NULL;
  }

  /**
   * Names the credential this request belongs to.
   */
  private function bucket(Request $request): ?string {
    $actor = $request->attributes->get('ab_agent_actor');
    if (is_array($actor) && is_string($actor['token_id'] ?? NULL) && $actor['token_id'] !== '') {
      return 'token:' . $actor['token_id'];
    }
    if ($this->currentUser->isAnonymous()) {
      return NULL;
    }
    return 'user:' . $this->currentUser->id();
  }

  /**
   * Reports whether this call creates a conversation message.
   */
  private function isMessage(Request $request): bool {
    if ($request->getMethod() !== 'POST') {
      return FALSE;
    }
    $path = $request->getPathInfo();
    return str_ends_with($path, '/messages')
      || str_ends_with($path, '/request-input')
      || str_ends_with($path, '/answer');
  }

  /**
   * Adds one hit and returns the count for this minute.
   */
  private function increment(string $bucket, int $window): int {
    $hits = $this->database->query(
      'INSERT INTO {ab_api_rate} (bucket, window_start, hits) VALUES (:bucket, :window, 1) ON CONFLICT (bucket, window_start) DO UPDATE SET hits = {ab_api_rate}.hits + 1 RETURNING hits',
      [
        ':bucket' => $bucket,
        ':window' => $window,
      ],
    )->fetchField();
    return (int) $hits;
  }

  /**
   * Reads a positive limit from settings.
   */
  private function limit(string $name, int $default): int {
    $value = Settings::get($name, $default);
    if (!is_int($value) || $value < 1) {
      return $default;
    }
    return $value;
  }

  /**
   * Seconds until the current minute ends.
   */
  private function wait(int $now, int $window): int {
    return max(1, $window + ApiLimits::RATE_WINDOW - $now);
  }

}
