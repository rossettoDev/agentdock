<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Unit;

use Drupal\ab_ui\TaskMarkdown;
use Drupal\Tests\UnitTestCase;

/**
 * Verifies Markdown rendering keeps raw HTML as text.
 *
 * @group ab_ui
 */
class TaskMarkdownTest extends UnitTestCase {

  /**
   * Script and event-handler markup do not become tags.
   */
  public function testRawHtmlStaysText(): void {
    $html = (new TaskMarkdown())->render("**negrito**\n\n<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>");
    $this->assertStringContainsString('<strong>negrito</strong>', $html);
    $this->assertStringNotContainsString('<script', $html);
    $this->assertStringNotContainsString('<img', $html);
    $this->assertStringContainsString('&lt;script&gt;', $html);
    $this->assertStringContainsString('&lt;img', $html);
  }

  /**
   * Links accept http(s) and reject a script URL.
   */
  public function testLinksRejectScriptUrls(): void {
    $markdown = new TaskMarkdown();
    $safe = $markdown->render('[site](https://example.com/a)');
    $this->assertStringContainsString('href="https://example.com/a"', $safe);
    $script = $markdown->render('[clique](javascript:alert(1))');
    $this->assertStringNotContainsString('javascript:', $script);
    $this->assertStringContainsString('clique', $script);
    $this->assertStringNotContainsString('<a ', $script);
  }

  /**
   * A list and a heading use only the allowed tags.
   */
  public function testListAndHeading(): void {
    $html = (new TaskMarkdown())->render("# Titulo\n\n- um\n- dois");
    $this->assertStringContainsString('<h1>Titulo</h1>', $html);
    $this->assertStringContainsString('<li>um</li>', $html);
    $this->assertStringContainsString('<li>dois</li>', $html);
  }

}
