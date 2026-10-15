<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Cache;

use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Varies a cache entry by the workspace selected in the session.
 */
final class WorkspaceContextCacheContext implements CacheContextInterface {

  public function __construct(
    private readonly WorkspaceContext $context,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getLabel() {
    return new TranslatableMarkup('Selected workspace');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext() {
    $workspace = $this->context->workspace();
    return $workspace === NULL ? 'none' : $workspace->uuid();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata() {
    return new CacheableMetadata();
  }

}
