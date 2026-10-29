<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_documentation\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

/**
 * Tests wiki documentation functionality.
 *
 * @group ab_documentation
 */
class WikiDocumentationTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'taxonomy',
    'text',
    'filter',
    'views',
    'path',
    'pathauto',
    'token',
    'ab_documentation',
  ];

  /**
   * Tests that wiki content type and vocabulary are created.
   */
  public function testWikiContentTypeExists(): void {
    $content_types = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();

    $this->assertArrayHasKey('wiki', $content_types, 'Wiki content type exists');

    $vocabularies = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_vocabulary')
      ->loadMultiple();

    $this->assertArrayHasKey('wiki_category', $vocabularies, 'Wiki category vocabulary exists');
  }

  /**
   * Tests that seed content is created.
   */
  public function testSeedContentCreated(): void {
    $query = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'wiki');
    $nids = $query->execute();

    $this->assertGreaterThanOrEqual(7, count($nids), 'At least 7 wiki pages were created');

    $query = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'wiki_category');
    $tids = $query->execute();

    $this->assertEquals(4, count($tids), 'Exactly 4 wiki categories were created');
  }

  /**
   * Tests that wiki pages are accessible.
   */
  public function testWikiPagesAccessible(): void {
    // Test /docs listing page.
    $this->drupalGet('/docs');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Documentação');

    // Test individual wiki page.
    $query = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'wiki')
      ->condition('title', 'Documentação')
      ->range(0, 1);
    $nids = $query->execute();

    if (!empty($nids)) {
      $nid = reset($nids);
      $node = Node::load($nid);
      $this->drupalGet($node->toUrl());
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains('AgentDock');
    }
  }

  /**
   * Tests wiki node creation via UI.
   */
  public function testCreateWikiPage(): void {
    $admin_user = $this->drupalCreateUser([
      'create wiki content',
      'edit own wiki content',
      'access content',
    ]);
    $this->drupalLogin($admin_user);

    // Get a category term.
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'wiki_category']);
    $term = reset($terms);

    $edit = [
      'title[0][value]' => 'Test Wiki Page',
      'body[0][value]' => '<h2>Test Content</h2><p>This is a test wiki page.</p>',
      'field_summary[0][value]' => 'Test summary',
      'field_wiki_category[0][target_id]' => $term->id(),
      'field_wiki_weight[0][value]' => 10,
    ];

    $this->drupalGet('node/add/wiki');
    $this->submitForm($edit, 'Salvar');
    $this->assertSession()->pageTextContains('Wiki Test Wiki Page foi criado.');

    // Verify the page appears on /docs.
    $this->drupalGet('/docs');
    $this->assertSession()->pageTextContains('Test Wiki Page');
  }

  /**
   * Tests that wiki pages are grouped by category.
   */
  public function testWikiCategoryGrouping(): void {
    $this->drupalGet('/docs');
    $this->assertSession()->statusCodeEquals(200);

    // Check that category names appear as grouping headers.
    $this->assertSession()->pageTextContains('Começar');
    $this->assertSession()->pageTextContains('Agentes');
    $this->assertSession()->pageTextContains('API e MCP');
    $this->assertSession()->pageTextContains('Operação');
  }

}
