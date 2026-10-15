<?php

declare(strict_types=1);

namespace Drupal\ab_core\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Rejects an entity reference that points outside the workspace.
 */
#[Constraint(
  id: 'AbSameWorkspace',
  label: new TranslatableMarkup('Same workspace reference', [], ['context' => 'Validation']),
  type: ['entity'],
)]
final class AbSameWorkspaceConstraint extends SymfonyConstraint {

  /**
   * The violation message.
   */
  public string $message = 'The referenced entity belongs to another workspace.';

}
