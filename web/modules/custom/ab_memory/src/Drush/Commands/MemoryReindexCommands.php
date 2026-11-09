<?php

declare(strict_types=1);

namespace Drupal\ab_memory\Drush\Commands;

use Drupal\ab_memory\MemorySearchIndex;
use Drupal\ab_memory\MemoryService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Rebuilds the reconstructible lexical memory index.
 */
final class MemoryReindexCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly MemoryService $memory,
    private readonly MemorySearchIndex $index,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Rebuilds the lexical search projection from durable revisions.
   */
  #[CLI\Command(name: 'ab-memory:reindex', aliases: ['ab-memory-reindex'])]
  #[CLI\Option(name: 'workspace', description: 'Optional workspace UUID to rebuild.')]
  #[CLI\Usage(name: 'ab-memory:reindex', description: 'Rebuild the full lexical index.')]
  #[CLI\Usage(name: 'ab-memory:reindex --workspace=UUID', description: 'Rebuild one workspace.')]
  public function reindex(array $options = ['workspace' => NULL]): void {
    $workspaceOption = $options['workspace'] ?? NULL;
    $workspaceId = NULL;
    if (is_string($workspaceOption) && $workspaceOption !== '') {
      $storage = $this->entityTypeManager->getStorage('ab_workspace');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uuid', $workspaceOption)
        ->range(0, 1)
        ->execute();
      if ($ids === []) {
        throw new \InvalidArgumentException('Workspace was not found.');
      }
      $workspaceId = (int) reset($ids);
    }
    $this->index->ensureFts();
    $count = $this->memory->reindex($workspaceId);
    $this->logger()?->notice('Indexed {count} memory rows.', ['count' => $count]);
  }

}
