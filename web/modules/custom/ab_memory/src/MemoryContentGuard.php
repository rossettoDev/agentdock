<?php

declare(strict_types=1);

namespace Drupal\ab_memory;

/**
 * Rejects obvious secret patterns before memory is persisted.
 */
final class MemoryContentGuard {

  /**
   * Ensures title and markdown do not carry obvious secrets.
   *
   * @throws \Drupal\ab_memory\MemoryRejectedException
   *   A sensitive pattern was detected.
   */
  public function assertSafe(string $title, string $markdown): void {
    $haystack = $title . "\n" . $markdown;
    if ($this->looksSensitive($haystack)) {
      throw new MemoryRejectedException('Memory content looks sensitive.');
    }
  }

  /**
   * Reports whether the text matches a pilot heuristic.
   */
  private function looksSensitive(string $text): bool {
    if (preg_match('/Authorization\s*:\s*Bearer/i', $text) === 1) {
      return TRUE;
    }
    if (preg_match('/BEGIN (?:RSA )?PRIVATE KEY/', $text) === 1) {
      return TRUE;
    }
    if (preg_match('/\bab_[0-9a-f]{16,}\.[0-9a-f]{32,}\b/i', $text) === 1) {
      return TRUE;
    }
    if (preg_match('/(?im)^[ \t]*(?:api_key|password|secret|lease_token)[ \t]*=[ \t]*\S+/', $text) === 1) {
      return TRUE;
    }
    return FALSE;
  }

}
