<?php

declare(strict_types=1);

namespace Drupal\ab_ui;

use Drupal\Component\Diff\Diff;
use Drupal\Core\Diff\DiffFormatter;

/**
 * Builds a simple line diff table for memory revisions.
 */
final class MemoryDiff {

  public function __construct(
    private readonly DiffFormatter $formatter,
  ) {}

  /**
   * Renders a line diff table comparing two markdown bodies.
   *
   * @return array<string, mixed>
   *   A table render array. Empty array when both sides match.
   */
  public function table(string $from, string $to, string $fromLabel, string $toLabel): array {
    $fromLines = $this->lines($from);
    $toLines = $this->lines($to);
    if ($fromLines === $toLines) {
      return [];
    }
    $diff = new Diff($fromLines, $toLines);
    return [
      '#type' => 'table',
      '#header' => [
        ['data' => $fromLabel, 'colspan' => 2],
        ['data' => $toLabel, 'colspan' => 2],
      ],
      '#rows' => $this->formatter->format($diff),
      '#attributes' => [
        'class' => ['diff', 'ab-memory-diff'],
      ],
      '#attached' => [
        'library' => ['system/diff'],
      ],
    ];
  }

  /**
   * Splits markdown into lines for the diff engine.
   *
   * @return string[]
   *   Lines without trailing newlines.
   */
  private function lines(string $markdown): array {
    $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
    if ($normalized === '') {
      return [];
    }
    return explode("\n", $normalized);
  }

}
