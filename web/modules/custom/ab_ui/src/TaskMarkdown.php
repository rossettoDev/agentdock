<?php

declare(strict_types=1);

namespace Drupal\ab_ui;

use Drupal\Component\Utility\Xss;

/**
 * Renders a small Markdown subset after escaping raw HTML.
 *
 * Script and other markup from the author stay as text. Only the tags
 * produced here are allowed through the final filter.
 */
final class TaskMarkdown {

  /**
   * Tags produced by this renderer.
   */
  private const ALLOWED = [
    'p',
    'br',
    'strong',
    'em',
    'code',
    'a',
    'ul',
    'li',
    'h1',
    'h2',
    'h3',
  ];

  /**
   * Converts Markdown to sanitized HTML.
   */
  public function render(string $source): string {
    $source = trim(str_replace(["\r\n", "\r"], "\n", $source));
    if ($source === '') {
      return '';
    }
    $html = [];
    foreach (preg_split("/\n{2,}/", $source) ?: [] as $block) {
      if ($block !== '') {
        $html[] = $this->block($block);
      }
    }
    return Xss::filter(implode("\n", $html), self::ALLOWED);
  }

  /**
   * Renders one blank-line-separated block.
   */
  private function block(string $block): string {
    $lines = explode("\n", $block);
    if ($this->isList($lines)) {
      $items = [];
      foreach ($lines as $line) {
        $items[] = '<li>' . $this->inline(substr($line, 2)) . '</li>';
      }
      return '<ul>' . implode('', $items) . '</ul>';
    }
    if (count($lines) === 1 && preg_match('/^(#{1,3}) (.+)$/', $lines[0], $matches) === 1) {
      $level = strlen($matches[1]);
      return '<h' . $level . '>' . $this->inline($matches[2]) . '</h' . $level . '>';
    }
    $rendered = [];
    foreach ($lines as $line) {
      $rendered[] = $this->inline($line);
    }
    return '<p>' . implode('<br>', $rendered) . '</p>';
  }

  /**
   * Reports whether every line is a list item.
   *
   * @param string[] $lines
   *   Lines of one block.
   */
  private function isList(array $lines): bool {
    if ($lines === []) {
      return FALSE;
    }
    foreach ($lines as $line) {
      if (preg_match('/^[-*] \S/', $line) !== 1) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Escapes text and then applies inline markers.
   */
  private function inline(string $text): string {
    $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safe = preg_replace('/`([^`]+)`/', '<code>$1</code>', $safe) ?? $safe;
    $safe = preg_replace_callback(
      '/\[([^\]]+)\]\(([^)]+)\)/',
      function (array $matches): string {
        $url = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!$this->safeUrl($url)) {
          return $matches[1];
        }
        $href = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<a href="' . $href . '">' . $matches[1] . '</a>';
      },
      $safe,
    ) ?? $safe;
    $safe = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $safe) ?? $safe;
    return preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $safe) ?? $safe;
  }

  /**
   * Allows http(s) and same-site paths only.
   */
  private function safeUrl(string $url): bool {
    $url = trim($url);
    if ($url === '' || preg_match('/[\s<>"\']/', $url) === 1) {
      return FALSE;
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
      return TRUE;
    }
    return preg_match('#\Ahttps?://#i', $url) === 1;
  }

}
