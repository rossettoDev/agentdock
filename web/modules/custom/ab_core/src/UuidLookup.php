<?php

declare(strict_types=1);

namespace Drupal\ab_core;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Loads one content entity by its public UUID.
 *
 * The query is not an access check. Callers still apply workspace scope.
 */
final class UuidLookup {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Loads an entity by lowercase UUID.
   *
   * @throws \InvalidArgumentException
   *   The value is not a lowercase UUID.
   */
  public function load(
    string $entity_type_id,
    string $uuid,
  ): ?ContentEntityInterface {
    $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-'
      . '[0-9a-f]{4}-[0-9a-f]{12}$/';
    if (preg_match($pattern, $uuid) !== 1) {
      throw new \InvalidArgumentException('The id must be a lowercase UUID.');
    }
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $entities = $storage->loadByProperties(['uuid' => $uuid]);
    if ($entities === []) {
      return NULL;
    }
    $entity = reset($entities);
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

}
