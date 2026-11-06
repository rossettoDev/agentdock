<?php

declare(strict_types=1);

namespace Drupal\ab_memory;

/**
 * Rejects obvious secret patterns before memory is persisted.
 */
final class MemoryContentGuard {

  /**
   * Ensures title, markdown and optional fields do not carry secrets.
   *
   * @param string $title
   *   Entry title.
   * @param string $markdown
   *   Markdown body.
   * @param string[] $tags
   *   Normalized tags.
   * @param string|null $changeReason
   *   Optional change reason.
   *
   * @throws \Drupal\ab_memory\MemoryRejectedException
   *   A sensitive pattern was detected.
   */
  public function assertSafe(string $title, string $markdown, array $tags = [], ?string $changeReason = NULL): void {
    $parts = [$title, $markdown];
    foreach ($tags as $tag) {
      $parts[] = $tag;
    }
    if ($changeReason !== NULL && $changeReason !== '') {
      $parts[] = $changeReason;
    }
    $haystack = implode("\n", $parts);
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
