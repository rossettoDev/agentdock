<?php

declare(strict_types=1);

namespace Drupal\ab_core\Cache;

use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Varies a cache entry by the current user's active workspaces.
 */
final class MembershipsCacheContext implements CacheContextInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly MembershipScope $scope,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getLabel() {
    return new TranslatableMarkup('Active workspace memberships');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext() {
    $ids = $this->scope->activeWorkspaceIds($this->currentUser->getAccount());
    return $ids === [] ? 'none' : implode(',', $ids);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata() {
    $metadata = new CacheableMetadata();
    $metadata->setCacheTags(['ab_membership_list:' . $this->currentUser->id()]);
    return $metadata;
  }

}
