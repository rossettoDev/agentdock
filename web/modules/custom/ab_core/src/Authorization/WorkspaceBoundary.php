<?php

declare(strict_types=1);

namespace Drupal\ab_core\Authorization;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\ab_core\Entity\Workspace;

/**
 * Detects an entity reference that leaves its workspace.
 */
final class WorkspaceBoundary {

  /**
   * Reports whether the referenced entity belongs to another workspace.
   */
  public function isCrossWorkspaceReference(Workspace $workspace, ContentEntityInterface $referenced): bool {
    if ($workspace->id() === NULL) {
      return TRUE;
    }
    if ($referenced instanceof Workspace) {
      return (string) $referenced->id() !== (string) $workspace->id();
    }
    if (!$referenced->hasField('workspace')) {
      return FALSE;
    }
    $referenced_workspace = $referenced->get('workspace')->getString();
    return $referenced_workspace === '' || $referenced_workspace !== (string) $workspace->id();
  }

}
